#!/usr/bin/env bash
set -euo pipefail

# ================================================================
#  Janathan - production build (Linux / macOS)
#  Builds assets, installs production PHP deps, assembles a ready
#  deploy folder at dist/janathan/ including a generated .env
#  and an initialized SQLite database with an admin.
# ================================================================
#  Options:
#    --base <path>       APP_BASE_PATH for the generated .env (default /janathan;
#                        pass "" to deploy with docroot straight at public/)
#    --url  <url>        APP_URL, e.g. https://example.com (optional)
#    --init <user> <pw>  admin credentials, prompts skipped (default janathan / 1234)
#    --no-prompt         don't prompt for admin credentials, use defaults
#    --nopause           skip the final confirmation prompt
#    --help              show this help message
# ================================================================

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="${ROOT}/dist/janathan"

# ── Defaults ────────────────────────────────────────────────────
NOPAUSE=""
NOPROMPT=""
INIT_GIVEN=""
BASE_PATH="/janathan"
APP_URL=""
ADMIN_USER="janathan"
ADMIN_PASS="1234"

# ── Argument parsing ────────────────────────────────────────────
show_help() {
    sed -n '/^#  Options:/,/^# ====/p' "$0" | sed 's/^#  \?//'
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --help|-h)     show_help ;;
        --nopause)     NOPAUSE=1;     shift ;;
        --no-prompt)   NOPROMPT=1;    shift ;;
        --base)        BASE_PATH="$2"; shift ;;
        --url)         APP_URL="$2";   shift ;;
        --init)
            INIT_GIVEN=1
            ADMIN_USER="$2"
            [[ -n "${3:-}" ]] && ADMIN_PASS="$3"
            shift 2
            ;;
        *)
            echo "Unknown option: $1"
            echo "Run with --help for usage."
            exit 1
            ;;
    esac
done

# ── Helpers ─────────────────────────────────────────────────────
fail() {
    echo ""
    echo "Build FAILED - see messages above."
    [[ -z "$NOPAUSE" ]] && read -rp "Press Enter to exit..."
    exit 1
}

check_file() {
    if [[ ! -f "$1" ]]; then
        echo "  [FAIL] Missing: $1"
        MISSING=1
    fi
}

# ── Banner ──────────────────────────────────────────────────────
echo ""
echo "================================================================"
echo " Janathan production build"
echo " Project : ${ROOT}/"
echo "================================================================"
echo ""

# ── Admin credentials (unless forced via --init or --no-prompt) ─
if [[ -z "$INIT_GIVEN" && -z "$NOPROMPT" ]]; then
    echo " Admin credentials for the new database - Enter accepts defaults:"
    read -rp "   Username [${ADMIN_USER}]: " input
    [[ -n "$input" ]] && ADMIN_USER="$input"
    read -rp "   Password [${ADMIN_PASS}]: " input
    [[ -n "$input" ]] && ADMIN_PASS="$input"
    [[ -z "$ADMIN_USER" ]] && ADMIN_USER="janathan"
    [[ -z "$ADMIN_PASS" ]] && ADMIN_PASS="1234"
    echo " Admin user     : ${ADMIN_USER}  - prompted above or default; change password after first login"
fi
echo ""

# ── [0/5] Locate toolchain ─────────────────────────────────────
echo "[0/5] Locating PHP, npm and Composer..."

PHP_EXE="$(command -v php 2>/dev/null || true)"
[[ -z "$PHP_EXE" ]] && { echo "  [FAIL] PHP not found. Add php to PATH."; fail; }

if ! php -r "exit(PHP_VERSION_ID < 80200 ? 1 : 0);" 2>/dev/null; then
    echo "  [FAIL] PHP 8.2 or newer is required."
    fail
fi

NPM_CMD="$(command -v npm 2>/dev/null || true)"
[[ -z "$NPM_CMD" ]] && { echo "  [FAIL] npm not found. Add node to PATH."; fail; }

COMPOSER_CMD="$(command -v composer 2>/dev/null || true)"
[[ -z "$COMPOSER_CMD" ]] && { echo "  [FAIL] composer not found. Add composer to PATH."; fail; }

echo "  PHP      : ${PHP_EXE}"
echo "  npm      : ${NPM_CMD}"
echo "  Composer : ${COMPOSER_CMD}"
echo ""

# ── [1/5] Frontend assets ───────────────────────────────────────
if [[ ! -f "${ROOT}/node_modules/.bin/esbuild" || ! -f "${ROOT}/node_modules/.bin/tailwindcss" ]]; then
    echo "[1/5] Frontend dependencies missing - running npm ci..."
    "$NPM_CMD" ci || fail
fi

echo "[1/5] Building frontend assets..."
"$NPM_CMD" run build || fail

# ── [2/5] Backend dependencies (production only) ───────────────
echo "[2/5] Installing production dependencies..."
"$COMPOSER_CMD" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative || fail

# ── [3/5] Assemble deploy package ──────────────────────────────
echo "[3/5] Assembling deploy package at ${DIST}/..."
rm -rf "$DIST"
mkdir -p "$DIST"

for subdir in vendor config routes src templates resources bin; do
    cp -r "${ROOT}/${subdir}" "${DIST}/${subdir}"
done
cp -r "${ROOT}/public" "${DIST}/public"

# Ship only the compiled CSS/JS, not the Tailwind/esbuild sources.
rm -f "${DIST}/public/css/index.css"
rm -f "${DIST}/public/js/index.js"

cp -f "${ROOT}/composer.json"           "${DIST}/composer.json"
cp -f "${ROOT}/composer.lock"           "${DIST}/composer.lock"
cp -f "${ROOT}/.env.example"            "${DIST}/.env.example"
cp -f "${ROOT}/.htaccess"               "${DIST}/.htaccess"
cp -f "${ROOT}/scripts/README-DEPLOY.md" "${DIST}/README-DEPLOY.md"

# Writable dir where the SQLite DB will be created.
mkdir -p "${DIST}/database"

# ── [4/5] Generate production .env ─────────────────────────────
echo "[4/5] Generating .env (APP_BASE_PATH=\"${BASE_PATH}\")..."
php "${ROOT}/scripts/generate-env.php" \
    "${DIST}/.env.example" "${DIST}/.env" \
    "--base=${BASE_PATH}" "--url=${APP_URL}" || fail

# ── [5/5] Initialize database + admin user ─────────────────────
echo "[5/5] Initializing database and admin user '${ADMIN_USER}'..."
php "${DIST}/bin/init.php" "${ADMIN_USER}" "${ADMIN_PASS}" || fail

# ── Sanity check the assembled package ──────────────────────────
MISSING=0
check_file "${DIST}/vendor/autoload.php"
check_file "${DIST}/public/css/app.css"
check_file "${DIST}/public/js/app.js"
check_file "${DIST}/public/fonts/phosphor/style.css"
check_file "${DIST}/public/.htaccess"
check_file "${DIST}/.htaccess"
check_file "${DIST}/.env"
check_file "${DIST}/database/janathan.sqlite"

[[ "$MISSING" -eq 1 ]] && fail

echo ""
echo "Build finished successfully."
echo ""
echo " Deploy package  : ${DIST}/"
echo " .env            : generated (APP_BASE_PATH=${BASE_PATH})"
echo " Admin user      : ${ADMIN_USER}  (password hidden; change it after first login)"
echo " Next steps      : upload it, make sure \"database\" stays writable, open the site."
echo "                   (full guide: README-DEPLOY.md in the package)"
echo ""
[[ -z "$NOPAUSE" ]] && read -rp "Press Enter to exit..."
exit 0
