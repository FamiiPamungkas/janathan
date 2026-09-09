#!/usr/bin/env bash
# ================================================================
#  Janathan - production build for shared hosting (Linux)
#  Builds assets, installs production PHP deps, assembles a ready
#  deploy folder at dist/janathan/. The package ships WITHOUT a
#  database - the web setup wizard creates it (schema, APP_KEY and
#  first admin) on the first browser visit. APP_BASE_PATH is applied
#  to the dist config/app.php during the build (source stays untouched).
# ================================================================
#  Options:
#    --nopause           skip the final confirmation prompt
#    --basepath <path>   set APP_BASE_PATH non-interactively (e.g. --basepath /janathan)
#    env overrides:      PHP, NPM, COMPOSER
# ================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
DIST="$ROOT/dist/janathan"

# ----------------------------------------------------------------
# Parse arguments
# ----------------------------------------------------------------
NOPAUSE=""
APP_BASE_PATH_ARG=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --nopause)  NOPAUSE=1; shift ;;
        --basepath) APP_BASE_PATH_ARG="${2:-}"; shift 2 ;;
        *)          echo "Unknown option: $1"; exit 1 ;;
    esac
done

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

echo
echo "Build finished successfully."
echo
echo "  Deploy package  : $DIST"
echo "  Zip archive     : $ZIP_FILE"
if [[ -n "$APP_BASE_PATH" ]]; then
    echo "  APP_BASE_PATH   : $APP_BASE_PATH"
else
    echo "  APP_BASE_PATH   : (empty)"
fi
echo "  Database        : created on first visit by the web setup wizard"
echo "                    (admin account + APP_KEY are set up there)"
echo "  Next steps      : upload the zip, make sure \"database\" stays writable, open the site."
echo "                    (full guide: README-DEPLOY.md in the package)"
echo "  Docker          : unzip $ZIP_FILE -d /tmp/janathan && cd /tmp/janathan/janathan && docker-compose up -d --build"
echo "                    (binds the package as a volume; edit docker-compose.yml"
echo "                     for APP_BASE_PATH, DB_PATH, Mikrotik timeouts, port)"
echo
