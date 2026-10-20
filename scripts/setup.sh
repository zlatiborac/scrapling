#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

# Herd supplies PHP and Composer on macOS. Python is installed separately.
PYTHON_BIN=${PYTHON_BIN:-python3.12}
command -v php >/dev/null 2>&1 || { echo 'PHP is required. Enable PHP 8.4+ in Herd and add Herd to PATH.' >&2; exit 1; }
command -v composer >/dev/null 2>&1 || { echo 'Composer is required. Add Herd to PATH.' >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo 'Node.js 24 LTS is required to build the Filament theme.' >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo 'npm is required to build the Filament theme.' >&2; exit 1; }
command -v "$PYTHON_BIN" >/dev/null 2>&1 || { echo "Python is required. Install python@3.12 or set PYTHON_BIN." >&2; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' || { echo 'Select PHP 8.4+ in Herd.' >&2; exit 1; }
node -e 'const [major, minor] = process.versions.node.split(".").map(Number); if (!(major >= 24 || (major === 22 && minor >= 12))) { console.error("Use Node.js 24 LTS or Node.js 22.12+ to build the admin theme."); process.exit(1); }'

"$PYTHON_BIN" -m venv .venv
.venv/bin/python -m pip install --require-hashes -r requirements.txt
composer install --no-interaction --prefer-dist
npm ci --no-audit --no-fund
npm run build
[ -f .env ] || cp .env.example .env
if ! grep -Eq '^APP_KEY=.+$' .env; then php artisan key:generate --no-interaction; fi
[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan migrate --force --no-interaction
php artisan db:seed --class=LocalAdminSeeder --force --no-interaction
