#!/usr/bin/env bash
#================================================================
# Production deploy for the Doctor Memory Game. Run ON the server, from
# the app root:
#
#   ./deploy.sh            # pull + install + migrate + rebuild caches + verify
#   ./deploy.sh --down     # same, with maintenance mode on while migrating
#   ./deploy.sh --check    # preflight checks only; changes nothing
#
# The server has no Node.js, so front-end assets are built locally and
# committed under public_html/build. This script never builds them.
#
# Its local counterpart is cx.sh, which runs this over SSH (option 20).
#================================================================

set -euo pipefail
cd "$(dirname "$0")"

MODE="${1:-}"
case "$MODE" in
    ""|--down|--check) ;;
    *) echo "Unknown option: $MODE (use --down or --check)"; exit 2 ;;
esac

fail() { echo "✗ $1"; exit 1; }
warn() { echo "⚠ $1"; }

#---------------------------------------------------------------
# Preflight: refuse to deploy into a broken environment
#---------------------------------------------------------------
echo "→ preflight"
command -v php >/dev/null 2>&1 || fail "php is not on PATH"
[ -f .env ] || fail ".env is missing — copy .env.example to .env and fill it in"
grep -qE '^APP_KEY=base64:.+' .env || fail "APP_KEY is empty — run: php artisan key:generate"
grep -qE '^APP_ENV=production' .env || warn "APP_ENV is not 'production' in .env"
grep -qE '^APP_DEBUG=false' .env || warn "APP_DEBUG is not 'false' in .env — error pages will leak details"
[ -f public_html/build/manifest.json ] || fail "public_html/build/manifest.json is missing — run 'npm run build' locally and commit public_html/build"

# Without the scheduler, unfinished game rows are never pruned.
if ! crontab -l 2>/dev/null | grep -q "schedule:run"; then
    warn "no cron entry for the scheduler. Add:"
    echo "    * * * * * cd $PWD && php artisan schedule:run >> /dev/null 2>&1"
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    warn "tracked files were edited on the server — git pull may refuse:"
    git status --short --untracked-files=no
fi

if [ "$MODE" = "--check" ]; then
    echo "→ pending migrations"
    php artisan migrate:status --pending 2>/dev/null || php artisan migrate:status
    echo "✓ preflight passed (nothing changed)"
    exit 0
fi

#---------------------------------------------------------------
# Deploy
#---------------------------------------------------------------
if [ "$MODE" = "--down" ]; then
    echo "→ maintenance mode on"
    php artisan down --retry=15
    # Whatever happens next, bring the site back up.
    trap 'echo "→ maintenance mode off"; php artisan up' EXIT
fi

echo "→ git pull"
# Remember the lockfile so dependencies are only reinstalled when they move.
LOCK_BEFORE=$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)
git pull --ff-only
LOCK_AFTER=$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)

if [ "$LOCK_BEFORE" != "$LOCK_AFTER" ] || [ ! -d vendor ]; then
    if command -v composer >/dev/null 2>&1; then
        echo "→ composer install"
        composer install --no-dev --optimize-autoloader --no-interaction
    else
        # Not fatal: the pull has landed and stopping would also skip migrations.
        warn "composer.lock changed but composer is not on PATH — install dependencies manually"
    fi
fi

echo "→ migrate"
php artisan migrate --force --no-interaction

echo "→ cache config, routes, views and events"
php artisan optimize

#---------------------------------------------------------------
# Verify
#---------------------------------------------------------------
echo "→ verify"
php artisan migrate:status --pending 2>/dev/null | grep -q "Pending" && fail "migrations still pending"

APP_URL=$(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"')
if [ -n "$APP_URL" ] && command -v curl >/dev/null 2>&1; then
    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APP_URL/up" || echo 000)
    [ "$CODE" = "200" ] || fail "$APP_URL/up returned $CODE"
    echo "  $APP_URL/up → 200"
fi

echo "✓ deployed $(git log --oneline -1)"
