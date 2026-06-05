#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# deploy.sh — commit, push, and deploy dply to production
# Usage: ./deploy.sh "commit message"
# ---------------------------------------------------------------------------

COMMIT_MSG="${1:-deploy}"
REMOTE="${DEPLOY_REMOTE:-origin}"
BRANCH="${DEPLOY_BRANCH:-main}"
APP_DIR="${DEPLOY_APP_DIR:-/var/www/dply}"
PHP="${DEPLOY_PHP:-php}"

log() { echo "[deploy] $*"; }
hr()  { echo "[deploy] ────────────────────────────────────────"; }

hr
log "Committing and pushing..."
hr

git add -A
git diff --cached --quiet && log "Nothing to commit, skipping." || git commit -m "$COMMIT_MSG"
git push "$REMOTE" "$BRANCH"

hr
log "Deploying on server..."
hr

ssh "${DEPLOY_HOST:?Set DEPLOY_HOST}" "
  set -euo pipefail
  cd $APP_DIR

  echo '[remote] Pulling...'
  git pull origin $BRANCH

  echo '[remote] Installing PHP dependencies...'
  composer install --no-interaction --no-dev --optimize-autoloader

  echo '[remote] Installing JS dependencies...'
  npm ci --prefer-offline

  echo '[remote] Building assets...'
  npm run build

  echo '[remote] Running migrations...'
  $PHP artisan migrate --force

  echo '[remote] Clearing caches...'
  $PHP artisan config:clear
  $PHP artisan route:clear
  $PHP artisan view:clear
  $PHP artisan event:clear

  echo '[remote] Caching for production...'
  $PHP artisan config:cache
  $PHP artisan route:cache
  $PHP artisan event:cache

  echo '[remote] Restarting queue workers...'
  $PHP artisan horizon:terminate || true
  sudo supervisorctl restart dply-horizon || true

  echo '[remote] Done.'
"

hr
log "Deploy complete."
hr
