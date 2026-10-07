#!/usr/bin/env bash
# ================================================================
#  Janathan - production build + optional deploy for shared hosting (Linux)
#  Builds assets, installs production PHP deps, assembles a ready
#  package folder at dist/janathan/ plus a zip at dist/janathan.zip.
#  The package ships WITHOUT a database - the web setup wizard
#  creates it (schema, APP_KEY and first admin) on the first browser
#  visit. APP_BASE_PATH is applied to the dist config/app.php during
#  the build (source stays untouched). After the build, the zip can
#  optionally be copied to a server via scp and unpacked there with
#  a Docker container restart (--scp / --deploy).
# ================================================================
#  Options:
#    --nopause           skip all interactive prompts (implies --no-scp, --no-deploy)
#    --basepath <path>   set APP_BASE_PATH non-interactively (e.g. --basepath /janathan)
#    --scp               copy dist/janathan.zip to a server via scp
#                        non-interactively (reads SSH_USER, SSH_HOST, SSH_DIR env vars)
#    --no-scp            never offer the SSH copy
#    --deploy            after a successful scp, unzip the package on the server
#                        and restart the Docker containers non-interactively
#    --no-deploy         never offer the remote unzip + restart
#    env overrides:      PHP, NPM, COMPOSER, SSH_USER, SSH_HOST, SSH_DIR
# ================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
DIST="$ROOT/dist/janathan"

# ----------------------------------------------------------------
# Parse arguments
# ----------------------------------------------------------------
NOPAUSE=""
APP_BASE_PATH_ARG=""
SCP_MODE="auto"
DEPLOY_MODE="auto"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --nopause)  NOPAUSE=1; shift ;;
        --basepath) APP_BASE_PATH_ARG="${2:-}"; shift 2 ;;
        --scp)      SCP_MODE="yes"; shift ;;
        --no-scp)   SCP_MODE="no"; shift ;;
        --deploy)   DEPLOY_MODE="yes"; shift ;;
        --no-deploy) DEPLOY_MODE="no"; shift ;;
        *)          echo "Unknown option: $1"; exit 1 ;;
    esac
done

# --nopause implies --no-scp / --no-deploy unless explicitly requested
# (--scp / --deploy set "yes" regardless of flag order, so they always win).
if [[ -n "$NOPAUSE" && "$SCP_MODE" == "auto" ]]; then
    SCP_MODE="no"
fi
if [[ -n "$NOPAUSE" && "$DEPLOY_MODE" == "auto" ]]; then
    DEPLOY_MODE="no"
fi

# ----------------------------------------------------------------
# Helpers
# ----------------------------------------------------------------
fail() { echo; echo "Build FAILED - see messages above."; exit 1; }

check() {
    if [[ ! -f "$1" ]]; then
        echo "  [FAIL] Missing: $1"
        MISSING=1
    fi
}

# Quote a remote path for the server's shell (sh).
# A leading ~/ must NOT be quoted/escaped as-is: printf '%q' turns it
# into \~/ and '...' / "..." inhibit tilde expansion, so
# cd '~/docker' fails with "No such file or directory". Rewrite ~/...
# to $HOME/... and single-quote only the remainder (POSIX-safe,
# handles spaces and single quotes).
remote_quote_dir() {
    local dir="$1"
    local rest esc prefix
    if [[ "$dir" == "~" ]]; then
        printf '%s' '$HOME'
    elif [[ "${dir:0:2}" == "~/" ]]; then
        rest="${dir:2}"
        esc="${rest//\'/\'\\\'\'}"
        printf "%s" "\$HOME/'$esc'"
    elif [[ "${dir:0:1}" == "~" && "$dir" == */* ]]; then
        # ~user/... - keep the ~user prefix unquoted, quote the rest
        prefix="${dir%%/*}"
        rest="${dir#*/}"
        esc="${rest//\'/\'\\\'\'}"
        printf "%s" "$prefix/'$esc'"
    else
        esc="${dir//\'/\'\\\'\'}"
        printf "'%s'" "$esc"
    fi
}

echo
echo "==============================================================="
echo "  Janathan production build"
echo "  Project : $ROOT"
echo "==============================================================="
echo

# ----------------------------------------------------------------
# 0. Locate the toolchain
# ----------------------------------------------------------------
echo "[0/3] Locating PHP, Node and Composer..."

PHP_BIN="${PHP:-php}"
NPM_BIN="${npm:-npm}"
COMPOSER_BIN="${COMPOSER:-composer}"

# Verify PHP exists and version >= 8.2
if ! command -v "$PHP_BIN" &>/dev/null; then
    echo "  [FAIL] PHP not found. Install PHP 8.2+ or set the PHP env var."
    fail
fi

PHP_VERSION_ID=$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')
if [[ "$PHP_VERSION_ID" -lt 80200 ]]; then
    echo "  [FAIL] PHP 8.2 or newer is required (found $("$PHP_BIN" -r 'echo PHP_VERSION;'))."
    fail
fi

if ! command -v "$NPM_BIN" &>/dev/null; then
    echo "  [FAIL] npm not found. Install Node.js or set the NPM env var."
    fail
fi

# Try to locate composer.phar if composer isn't a direct command
if ! command -v "$COMPOSER_BIN" &>/dev/null; then
    if [[ -f "$ROOT/composer.phar" ]]; then
        COMPOSER_BIN="$PHP_BIN $ROOT/composer.phar"
    else
        echo "  [FAIL] Composer not found. Install it or set the COMPOSER env var."
        fail
    fi
fi

echo "  PHP      : $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
echo "  npm      : $NPM_BIN"
echo "  Composer : $COMPOSER_BIN"
echo

# ----------------------------------------------------------------
# APP_BASE_PATH - URL prefix the app is mounted under
# ----------------------------------------------------------------
APP_BASE_PATH=""

if [[ -n "$APP_BASE_PATH_ARG" ]]; then
    APP_BASE_PATH="$APP_BASE_PATH_ARG"
elif [[ -z "$NOPAUSE" ]]; then
    echo "  APP_BASE_PATH is the URL prefix the app is mounted under."
    echo "  Leave empty when the document root points at public, or enter the"
    echo "  sub-folder name (e.g. janathan or /janathan). Press Enter for empty."
    echo
    read -r -p "APP_BASE_PATH (default: empty): " APP_BASE_PATH || true
fi

# Normalize: strip trailing slashes, strip leading slashes, re-add single leading /
APP_BASE_PATH="${APP_BASE_PATH%%/}"
APP_BASE_PATH="${APP_BASE_PATH##/}"
if [[ -n "$APP_BASE_PATH" ]]; then
    APP_BASE_PATH="/$APP_BASE_PATH"
fi

if [[ -n "$APP_BASE_PATH" ]]; then
    echo "  APP_BASE_PATH  : $APP_BASE_PATH"
else
    echo "  APP_BASE_PATH  : (empty)"
fi
echo

# ----------------------------------------------------------------
# 1. Frontend assets (icons, CSS, JS)
# ----------------------------------------------------------------
MISSING=0

if [[ ! -f "$ROOT/node_modules/.bin/esbuild" || ! -f "$ROOT/node_modules/.bin/tailwindcss" ]]; then
    echo "[1/3] Frontend dependencies missing - running npm ci..."
    $NPM_BIN ci
    # shellcheck disable=SC2181
    if [[ $? -ne 0 ]]; then fail; fi
fi

echo "[1/3] Building frontend assets..."
$NPM_BIN run build
# shellcheck disable=SC2181
if [[ $? -ne 0 ]]; then fail; fi

# ----------------------------------------------------------------
# 2. Backend dependencies (production only)
# ----------------------------------------------------------------
echo "[2/3] Installing production dependencies..."
# shellcheck disable=SC2086
$COMPOSER_BIN install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
# shellcheck disable=SC2181
if [[ $? -ne 0 ]]; then fail; fi

# ----------------------------------------------------------------
# 3. Assemble the deploy package at dist/janathan/
# ----------------------------------------------------------------
echo "[3/3] Assembling deploy package at $DIST..."
rm -rf "$DIST"
mkdir -p "$DIST"

for DIR in vendor config routes src templates resources; do
    cp -a "$ROOT/$DIR" "$DIST/$DIR"
done
cp -a "$ROOT/public" "$DIST/public"

# Ship only the compiled CSS/JS, not the Tailwind/esbuild sources.
rm -f "$DIST/public/css/index.css"
rm -f "$DIST/public/js/index.js"

cp "$ROOT/composer.json"        "$DIST/composer.json"
cp "$ROOT/composer.lock"        "$DIST/composer.lock"
cp "$ROOT/.htaccess"            "$DIST/.htaccess"
cp "$ROOT/Dockerfile"           "$DIST/Dockerfile"
cp "$ROOT/docker-compose.yml"   "$DIST/docker-compose.yml"
cp "$ROOT/docker-entrypoint.sh" "$DIST/docker-entrypoint.sh"
cp "$ROOT/.dockerignore"        "$DIST/.dockerignore"
cp "$ROOT/scripts/README-DEPLOY.md" "$DIST/README-DEPLOY.md"

# Writable dir where the web setup wizard will create the SQLite DB
mkdir -p "$DIST/database"

# ----------------------------------------------------------------
# Apply APP_BASE_PATH to the dist config/app.php (source stays untouched).
# ----------------------------------------------------------------
if [[ -n "$APP_BASE_PATH" ]]; then
    # Use sed to replace the empty APP_BASE_PATH value with the provided path.
    # Matches:  'APP_BASE_PATH'           => '',
    # Replaces with: 'APP_BASE_PATH'           => '/janathan',
    ESCAPED=$(printf '%s\n' "$APP_BASE_PATH" | sed 's/[&/\]/\\&/g')
    sed -i "s|\('APP_BASE_PATH' *=> *'\)\('|\)|\1${ESCAPED}'|" "$DIST/config/app.php"

    # Verify the replacement worked
    if ! grep -q "'APP_BASE_PATH' *=> *'${ESCAPED}'" "$DIST/config/app.php"; then
        echo "  [FAIL] Could not apply APP_BASE_PATH to dist config/app.php"
        fail
    fi
    echo "  APP_BASE_PATH set to $APP_BASE_PATH"
fi
echo

# ----------------------------------------------------------------
# Sanity check the assembled package
# ----------------------------------------------------------------
MISSING=0
check "$DIST/vendor/autoload.php"
check "$DIST/public/css/app.css"
check "$DIST/public/js/app.js"
check "$DIST/public/fonts/phosphor/style.css"
check "$DIST/public/.htaccess"
check "$DIST/.htaccess"
check "$DIST/config/app.php"
check "$DIST/Dockerfile"
check "$DIST/docker-compose.yml"
check "$DIST/docker-entrypoint.sh"
if [[ "$MISSING" -eq 1 ]]; then fail; fi

# ----------------------------------------------------------------
# 4. Create zip archive
# ----------------------------------------------------------------
ZIP_FILE="$ROOT/dist/janathan.zip"
echo "Creating $ZIP_FILE..."
(cd "$ROOT/dist" && zip -qr "$ZIP_FILE" janathan)
if [[ $? -ne 0 ]]; then
    echo "  [FAIL] Could not create zip archive"
    fail
fi

# ----------------------------------------------------------------
# 5. Optional: copy the zip to a server via SSH (scp)
# ----------------------------------------------------------------
DO_SCP=""
SSH_USER="${SSH_USER:-}"
SSH_HOST="${SSH_HOST:-}"
SSH_DIR="${SSH_DIR:-}"

if [[ "$SCP_MODE" == "no" ]]; then
    :
elif [[ "$SCP_MODE" == "yes" ]]; then
    if [[ -z "$SSH_USER" || -z "$SSH_HOST" || -z "$SSH_DIR" ]]; then
        echo "  [FAIL] --scp needs SSH_USER, SSH_HOST and SSH_DIR env vars."
        echo "         Example: SSH_USER=user SSH_HOST=192.168.1.10 SSH_DIR=/home/user ./build-deploy.sh --scp"
        fail
    fi
    DO_SCP=1
else
    echo
    read -r -p "Copy $ZIP_FILE to a server via SSH (scp)? [y/N]: " SCP_ASK || true
    if [[ "${SCP_ASK:-}" =~ ^[Yy]$ ]]; then
        if [[ -n "$SSH_USER" ]]; then
            read -r -p "SSH username [$SSH_USER]: " SCP_USER || true
            [[ -n "${SCP_USER:-}" ]] && SSH_USER="$SCP_USER"
        else
            read -r -p "SSH username: " SSH_USER || true
        fi
        if [[ -n "$SSH_HOST" ]]; then
            read -r -p "Server IP/host [$SSH_HOST]: " SCP_HOST || true
            [[ -n "${SCP_HOST:-}" ]] && SSH_HOST="$SCP_HOST"
        else
            read -r -p "Server IP/host: " SSH_HOST || true
        fi
        if [[ -n "$SSH_DIR" ]]; then
            read -r -p "Remote directory [$SSH_DIR]: " SCP_DIR || true
            [[ -n "${SCP_DIR:-}" ]] && SSH_DIR="$SCP_DIR"
        else
            read -r -p "Remote directory (e.g. /home/user or ~/docker): " SSH_DIR || true
        fi
        if [[ -z "$SSH_USER" || -z "$SSH_HOST" || -z "$SSH_DIR" ]]; then
            echo "  [FAIL] SSH upload needs a username, server IP/host and directory."
            fail
        fi
        DO_SCP=1
    fi
fi

SCP_STATUS="skipped"
REMOTE_DIR_Q=""
if [[ -n "$DO_SCP" ]]; then
    if ! command -v scp &>/dev/null; then
        echo "  [FAIL] scp not found. Install an OpenSSH client to use the SSH copy."
        fail
    fi
    if ! command -v ssh &>/dev/null; then
        echo "  [FAIL] ssh not found. Install an OpenSSH client to use the SSH copy."
        fail
    fi
    # Tilde-safe quoting for the remote shell (see remote_quote_dir).
    REMOTE_DIR_Q=$(remote_quote_dir "$SSH_DIR")
    echo "Ensuring remote directory exists on $SSH_HOST..."
    if ! ssh "$SSH_USER@$SSH_HOST" "mkdir -p $REMOTE_DIR_Q"; then
        echo "  [FAIL] Could not create remote directory (build itself succeeded)."
        fail
    fi
    echo "Copying $(basename "$ZIP_FILE") to $SSH_USER@$SSH_HOST:$SSH_DIR/ ..."
    if scp "$ZIP_FILE" "$SSH_USER@$SSH_HOST:$SSH_DIR/"; then
        SCP_STATUS="copied to $SSH_USER@$SSH_HOST:$SSH_DIR/"
    else
        echo "  [FAIL] scp upload failed (build itself succeeded)."
        fail
    fi
fi

# ----------------------------------------------------------------
# 6. Optional: unzip on the server and restart Docker containers
#    (only offered after a successful scp - there is nothing to
#    unpack otherwise). unzip merges: files in the archive overwrite,
#    everything else on the server (e.g. database/janathan.sqlite,
#    which is NOT shipped in the zip) is left untouched.
# ----------------------------------------------------------------
DO_DEPLOY=""
if [[ "$DEPLOY_MODE" == "no" ]]; then
    :
elif [[ -z "$DO_SCP" ]]; then
    if [[ "$DEPLOY_MODE" == "yes" ]]; then
        echo "  [FAIL] --deploy needs a successful SSH copy first"
        echo "         (combine with --scp, or answer y at the copy prompt)."
        fail
    fi
elif [[ "$DEPLOY_MODE" == "yes" ]]; then
    DO_DEPLOY=1
else
    echo
    read -r -p "Unzip on the server and restart containers? [y/N]: " DEPLOY_ASK || true
    if [[ "${DEPLOY_ASK:-}" =~ ^[Yy]$ ]]; then
        DO_DEPLOY=1
    fi
fi

DEPLOY_STATUS="skipped"
if [[ -n "$DO_DEPLOY" ]]; then
    if ! command -v ssh &>/dev/null; then
        echo "  [FAIL] ssh not found. Install an OpenSSH client to use the remote deploy."
        fail
    fi
    if ! ssh "$SSH_USER@$SSH_HOST" "command -v unzip >/dev/null 2>&1"; then
        echo "  [FAIL] 'unzip' not found on $SSH_HOST. Install it there first."
        fail
    fi
    # Reuse the tilde-safe quoted dir from the scp step (recompute defensively).
    if [[ -z "$REMOTE_DIR_Q" ]]; then
        REMOTE_DIR_Q=$(remote_quote_dir "$SSH_DIR")
    fi
    echo "Unpacking janathan.zip on $SSH_HOST and restarting containers..."
    REMOTE_CMD="cd $REMOTE_DIR_Q && unzip -o janathan.zip && cd janathan && (docker compose down && docker compose up -d || docker-compose down && docker-compose up -d)"
    if ssh "$SSH_USER@$SSH_HOST" "$REMOTE_CMD"; then
        DEPLOY_STATUS="unpacked + containers restarted"
    else
        echo "  [FAIL] Remote deploy failed (build + scp succeeded)."
        fail
    fi
fi

echo
echo "Build finished successfully."
echo
echo "  Package folder  : $DIST"
echo "  Zip archive     : $ZIP_FILE"
if [[ -n "$APP_BASE_PATH" ]]; then
    echo "  APP_BASE_PATH   : $APP_BASE_PATH"
else
    echo "  APP_BASE_PATH   : (empty)"
fi
echo "  Database        : created on first visit by the web setup wizard"
echo "                    (admin account + APP_KEY are set up there)"
if [[ "$SCP_STATUS" != "skipped" ]]; then
    echo "  SSH copy        : $SCP_STATUS"
fi
if [[ "$DEPLOY_STATUS" != "skipped" ]]; then
    echo "  SSH deploy      : $DEPLOY_STATUS"
elif [[ "$SCP_STATUS" != "skipped" ]]; then
    echo "                    On the server: unzip janathan.zip, make \"database\" writable, open the site."
else
    echo "  Next steps      : upload the zip, make sure \"database\" stays writable, open the site."
    echo "                    (full guide: README-DEPLOY.md in the package)"
fi
echo "  Docker          : unzip $ZIP_FILE -d /tmp/janathan && cd /tmp/janathan/janathan && docker-compose up -d --build"
echo "                    (binds the package as a volume; edit docker-compose.yml"
echo "                     for APP_BASE_PATH, DB_PATH, Mikrotik timeouts, port)"
echo
