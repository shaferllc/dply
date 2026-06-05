#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# deploy.sh — commit, push, and deploy dply to production
# Usage: ./deploy.sh "commit message"
#
# Required env:
#   DEPLOY_HOST          SSH host for the web server
#
# Optional env:
#   DEPLOY_WORKER_HOSTS  Space-separated SSH hosts for worker servers.
#                        Defaults to DEPLOY_HOST (single-server setup).
#   DEPLOY_REMOTE        Git remote (default: origin)
#   DEPLOY_BRANCH        Git branch (default: main)
#   DEPLOY_APP_DIR       App path on servers (default: /var/www/dply)
#   DEPLOY_PHP           PHP binary (default: php)
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
# Default workers to the web host for single-server setups
WORKER_HOSTS="${DEPLOY_WORKER_HOSTS:-$WEB_HOST}"

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

ssh "$WEB_HOST" "
  set -euo pipefail
  cd $APP_DIR

  echo '[web] Pulling...'
  git pull origin $BRANCH

  echo '[web] Installing PHP dependencies...'
  composer install --no-interaction --no-dev --optimize-autoloader

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
  sudo supervisorctl restart dply-reverb || true

  echo '[web] Done.'
"

# ---------------------------------------------------------------------------
# 3. Deploy worker servers (pull + restart Horizon)
# ---------------------------------------------------------------------------
for WORKER_HOST in $WORKER_HOSTS; do
  # Skip if this is the same host as the web server — already pulled above
  if [ "$WORKER_HOST" = "$WEB_HOST" ]; then
    hr
    log "Restarting Horizon on web/worker host ($WORKER_HOST)..."
    hr
    ssh "$WORKER_HOST" "
      set -euo pipefail
      cd $APP_DIR
      echo '[worker] Terminating Horizon gracefully...'
      $PHP artisan horizon:terminate || true
      echo '[worker] Restarting via supervisor...'
      sudo supervisorctl restart dply-horizon || true
      echo '[worker] Horizon status:'
      sudo supervisorctl status dply-horizon || true
    "
  else
    hr
    log "Deploying worker ($WORKER_HOST)..."
    hr
    ssh "$WORKER_HOST" "
      set -euo pipefail
      cd $APP_DIR

      echo '[worker] Pulling...'
      git pull origin $BRANCH

      echo '[worker] Installing PHP dependencies...'
      composer install --no-interaction --no-dev --optimize-autoloader

      echo '[worker] Syncing cached config...'
      $PHP artisan config:cache
      $PHP artisan route:cache
      $PHP artisan event:cache

      echo '[worker] Terminating Horizon gracefully...'
      $PHP artisan horizon:terminate || true
      echo '[worker] Restarting via supervisor...'
      sudo supervisorctl restart dply-horizon || true
      echo '[worker] Horizon status:'
      sudo supervisorctl status dply-horizon || true
    "
  fi
done

hr
log "Deploy complete."
hr
