#!/usr/bin/env bash
set -euo pipefail

# SiaSurf zero-fuss production update script (CloudPanel / Ubuntu VPS).
# Usage:
#   ./deploy/deploy.sh            # pulls origin/main, builds, migrates, caches
#   ./deploy/deploy.sh --no-pull  # skips git pull (useful for initial deploy)

SKIP_PULL=false
if [[ "${1:-}" == "--no-pull" ]]; then
    SKIP_PULL=true
fi

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

if [[ ! -f .env ]]; then
    echo "[ERROR] .env file not found in $APP_DIR"
    echo "        Copy .env.production.example to .env and configure it first."
    exit 1
fi

echo "==> Deploying SiaSurf in $APP_DIR"

cleanup() {
    php artisan up >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> Enabling maintenance mode..."
php artisan down --retry=60 >/dev/null 2>&1 || true

if [[ "$SKIP_PULL" == false ]]; then
    echo "==> Pulling latest changes from origin/main..."
    git pull --ff-only origin main
fi

echo "==> Installing Composer dependencies (must precede Vite build for Ziggy)..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Building frontend assets..."
npm ci --no-audit --no-fund
npm run build

echo "==> Linking storage and running database migrations..."
php artisan storage:link >/dev/null 2>&1 || true
php artisan migrate --force --no-interaction

echo "==> Refreshing Laravel optimization caches..."
php artisan optimize:clear
php artisan optimize

echo "==> Disabling maintenance mode..."
php artisan up

echo "==> Restarting Supervisor services (if available)..."
if command -v supervisorctl >/dev/null 2>&1; then
    if [[ $EUID -eq 0 ]]; then
        supervisorctl restart siasurf-worker:* siasurf-reverb || true
    elif sudo -n supervisorctl status >/dev/null 2>&1; then
        sudo supervisorctl restart siasurf-worker:* siasurf-reverb || true
    else
        echo "[NOTE] Run as root/sudo to restart queue & Reverb workers:"
        echo "       sudo supervisorctl restart siasurf-worker:* siasurf-reverb"
    fi
fi

APP_URL_LINE="$(grep -E '^APP_URL=' .env | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
if [[ -n "$APP_URL_LINE" ]] && command -v curl >/dev/null 2>&1; then
    HEALTH_URL="${APP_URL_LINE%/}/up"
    HTTP_STATUS="$(curl -s -o /dev/null -w "%{http_code}" "$HEALTH_URL" || echo "000")"
    echo "==> Health check ($HEALTH_URL): HTTP $HTTP_STATUS"
    if [[ "$HTTP_STATUS" != "200" ]]; then
        echo "[WARN] Expected HTTP 200 from $HEALTH_URL (got $HTTP_STATUS)"
    fi
fi

echo "==> Deployment complete!"
