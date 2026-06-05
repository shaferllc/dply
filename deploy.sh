#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# deploy.sh — commit, push, and deploy dply to production
# Usage: ./deploy.sh "commit message"
#
# Required env:
#   DEPLOY_HOST             SSH host for the web server
#
# Optional env:
#   DEPLOY_WORKER_HOSTS     Space-separated SSH aliases for worker servers
#   DEPLOY_APP_DIR          App path on the web server (default: /var/www/dply)
#   DEPLOY_WORKER_APP_DIR   App path on worker servers (defaults to DEPLOY_APP_DIR)
#   DEPLOY_REMOTE           Git remote (default: origin)
#   DEPLOY_BRANCH           Git branch (default: main)
#   DEPLOY_PHP              PHP binary (default: php)
# ---------------------------------------------------------------------------

# Load deploy config from .deploy.env if it exists (not committed to git)
# shellcheck source=/dev/null
[ -f "$(dirname "$0")/.deploy.env" ] && source "$(dirname "$0")/.deploy.env"

COMMIT_MSG="${1:-deploy}"
REMOTE="${DEPLOY_REMOTE:-origin}"
BRANCH="${DEPLOY_BRANCH:-main}"
APP_DIR="${DEPLOY_APP_DIR:-/var/www/dply}"
PHP="${DEPLOY_PHP:-php}"
WEB_HOST="${DEPLOY_HOST:?Set DEPLOY_HOST}"
WORKER_HOSTS="${DEPLOY_WORKER_HOSTS:-}"
WORKER_APP_DIR="${DEPLOY_WORKER_APP_DIR:-$APP_DIR}"
COMPOSER="${DEPLOY_COMPOSER:-composer}"
WORKER_COMPOSER="${DEPLOY_WORKER_COMPOSER:-$COMPOSER}"

log() { echo "[deploy] $*"; }
hr()  { echo "[deploy] ────────────────────────────────────────"; }

# ---------------------------------------------------------------------------
# 1. Commit and push
# ---------------------------------------------------------------------------
hr
log "Committing and pushing..."
hr

git add -A
git diff --cached --quiet && log "Nothing to commit, skipping." || git commit -m "$COMMIT_MSG"
git push "$REMOTE" "$BRANCH"

# ---------------------------------------------------------------------------
# 2. Deploy web server (assets, cache, migrations)
# ---------------------------------------------------------------------------
hr
log "Deploying web server ($WEB_HOST)..."
hr

/usr/bin/ssh "$WEB_HOST" "
  set -euo pipefail
  cd $APP_DIR

  echo '[web] Pulling...'
  git pull origin $BRANCH

  echo '[web] Installing PHP dependencies...'
  $COMPOSER install --no-interaction --no-dev --optimize-autoloader

  echo '[web] Installing JS dependencies...'
  npm ci --prefer-offline

  echo '[web] Building assets...'
  npm run build

  echo '[web] Running migrations...'
  $PHP artisan migrate --force

  echo '[web] Clearing caches...'
  $PHP artisan config:clear
  $PHP artisan route:clear
  $PHP artisan view:clear
  $PHP artisan event:clear

  echo '[web] Caching for production...'
  $PHP artisan config:cache
  $PHP artisan route:cache
  $PHP artisan event:cache

  echo '[web] Restarting Reverb...'
  sudo supervisorctl restart dply-reverb 2>/dev/null || true

  echo '[web] Done.'
"

# ---------------------------------------------------------------------------
# 3. Deploy worker servers in parallel (pull + restart Horizon)
# ---------------------------------------------------------------------------
if [ -n "$WORKER_HOSTS" ]; then
  deploy_worker() {
    local HOST="$1"
    local DIR="$2"
    hr
    log "Deploying worker ($HOST)..."
    hr
    /usr/bin/ssh "$HOST" "
      set -euo pipefail
      cd $DIR

      echo '[worker] Pulling...'
      git pull origin $BRANCH

      echo '[worker] Installing PHP dependencies...'
      $WORKER_COMPOSER install --no-interaction --no-dev --optimize-autoloader

      echo '[worker] Caching config...'
      $PHP artisan config:cache
      $PHP artisan route:cache
      $PHP artisan event:cache

      echo '[worker] Restarting Horizon gracefully...'
      $PHP artisan horizon:terminate || true
      sudo supervisorctl restart dply-horizon || true

      echo '[worker] Horizon status:'
      sudo supervisorctl status dply-horizon || true
    "
  }

  # Fan out — deploy all workers in parallel
  pids=()
  for WORKER_HOST in $WORKER_HOSTS; do
    deploy_worker "$WORKER_HOST" "$WORKER_APP_DIR" &
    pids+=($!)
  done
  for pid in "${pids[@]}"; do
    wait "$pid"
  done
fi

hr
log "Deploy complete."
hr
