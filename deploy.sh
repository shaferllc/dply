#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# deploy.sh — commit, push, and deploy dply to production
# Usage: ./deploy.sh ["commit message"]
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
#
# AI commit messages + CHANGELOG entries are generated automatically when the
# `claude` CLI (Claude Code) is available in PATH. No API key required.
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
# AI commit message + changelog generation via `claude -p` (Claude Code CLI).
#
# Sets globals: AI_COMMIT_MSG, AI_CL_TYPE, AI_CL_ENTRY
# Returns 0 on success, 1 to fall back to the manual $COMMIT_MSG.
# ---------------------------------------------------------------------------
_ai_generate() {
    AI_COMMIT_MSG=""
    AI_CL_TYPE=""
    AI_CL_ENTRY=""

    command -v claude >/dev/null 2>&1 || return 1

    local diff
    diff=$(git diff --cached --stat && printf '\n---\n' && git diff --cached)

    # Truncate very large diffs — stays well under shell arg-size limits.
    if [ "${#diff}" -gt 14000 ]; then
        diff="${diff:0:14000}"$'\n... [truncated]'
    fi

    local prompt="Analyze this git diff and respond with EXACTLY three lines, no markdown, no extra text:
COMMIT: <conventional commit message, imperative mood, ≤72 chars>
TYPE: <Added|Changed|Fixed|Removed|Security|Deprecated>
CHANGELOG: <one concise sentence describing the user-visible change>

${diff}"

    local output
    output=$(claude -p "$prompt" 2>/dev/null) || return 1

    AI_COMMIT_MSG=$(printf '%s' "$output" | grep '^COMMIT:'    | sed 's/^COMMIT: *//')
    AI_CL_TYPE=$(   printf '%s' "$output" | grep '^TYPE:'      | sed 's/^TYPE: *//')
    AI_CL_ENTRY=$(  printf '%s' "$output" | grep '^CHANGELOG:' | sed 's/^CHANGELOG: *//')

    [ -z "$AI_COMMIT_MSG" ] && return 1
    return 0
}

# ---------------------------------------------------------------------------
# Prepend a new entry into CHANGELOG.md under ## [Unreleased].
# Creates the file if it doesn't exist yet.
# ---------------------------------------------------------------------------
_update_changelog() {
    local type="$1"
    local entry="$2"

    export _DPLY_CL_TYPE="$type"
    export _DPLY_CL_ENTRY="$entry"

    python3 << 'PYEOF'
import os, re, sys

type_  = os.environ["_DPLY_CL_TYPE"]
entry  = os.environ["_DPLY_CL_ENTRY"].lstrip("- ").strip()
path   = "CHANGELOG.md"
line   = f"- {entry}"

try:
    with open(path) as f:
        content = f.read()
except FileNotFoundError:
    with open(path, "w") as f:
        f.write(f"# Changelog\n\n## [Unreleased]\n### {type_}\n{line}\n")
    print(f"  created {path}")
    sys.exit(0)

marker = "## [Unreleased]"
if marker in content:
    idx = content.index(marker) + len(marker)
    content = content[:idx] + f"\n### {type_}\n{line}" + content[idx:]
else:
    m = re.search(r"\n## ", content)
    pos = m.start() if m else len(content)
    content = content[:pos] + f"\n\n## [Unreleased]\n### {type_}\n{line}" + content[pos:]

with open(path, "w") as f:
    f.write(content)
print(f"  [{type_}] {line}")
PYEOF

    unset _DPLY_CL_TYPE _DPLY_CL_ENTRY
}

# ---------------------------------------------------------------------------
# 1. Commit and push
# ---------------------------------------------------------------------------
hr
log "Committing and pushing..."
hr

git add -A

if ! git diff --cached --quiet; then
    if _ai_generate; then
        log "AI commit:  $AI_COMMIT_MSG"
        if [ -n "$AI_CL_ENTRY" ]; then
            log "Changelog: [${AI_CL_TYPE:-Changed}]"
            _update_changelog "${AI_CL_TYPE:-Changed}" "$AI_CL_ENTRY"
            git add CHANGELOG.md
        fi
        COMMIT_MSG="$AI_COMMIT_MSG"
    else
        log "⚠  AI generation failed — using: '$COMMIT_MSG'"
    fi
    git commit -m "$COMMIT_MSG"
else
    log "Nothing to commit, skipping."
fi

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
