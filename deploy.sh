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
    AI_CL_TITLE=""
    AI_CL_ENTRY=""

    command -v claude >/dev/null 2>&1 || return 1

    local diff
    diff=$(git diff --cached --stat && printf '\n---\n' && git diff --cached)

    # Truncate very large diffs — stays well under shell arg-size limits.
    if [ "${#diff}" -gt 14000 ]; then
        diff="${diff:0:14000}"$'\n... [truncated]'
    fi

    local prompt="Analyze this git diff and respond with EXACTLY four lines, no markdown, no extra text:
COMMIT: <conventional commit message, imperative mood, ≤72 chars>
TYPE: <Added|Changed|Fixed|Removed|Security|Deprecated>
TITLE: <short 3-6 word public-facing changelog title, title case>
CHANGELOG: <one concise sentence describing the user-visible change>

${diff}"

    local output
    output=$(claude -p "$prompt" 2>/dev/null) || return 1

    AI_COMMIT_MSG=$(printf '%s' "$output" | grep '^COMMIT:'    | sed 's/^COMMIT: *//')
    AI_CL_TYPE=$(   printf '%s' "$output" | grep '^TYPE:'      | sed 's/^TYPE: *//')
    AI_CL_TITLE=$(  printf '%s' "$output" | grep '^TITLE:'     | sed 's/^TITLE: *//')
    AI_CL_ENTRY=$(  printf '%s' "$output" | grep '^CHANGELOG:' | sed 's/^CHANGELOG: *//')

    [ -z "$AI_COMMIT_MSG" ] && return 1
    return 0
}

# ---------------------------------------------------------------------------
# Prepend a new entry into:
#   1. resources/views/changelog.blade.php  ($entries PHP array)
#   2. CHANGELOG.md                         (Keep a Changelog format)
# ---------------------------------------------------------------------------
_update_changelog() {
    local type="$1"
    local title="$2"
    local entry="$3"

    export _DPLY_CL_TYPE="$type"
    export _DPLY_CL_TITLE="$title"
    export _DPLY_CL_ENTRY="$entry"

    python3 << 'PYEOF'
import os, re, sys
from datetime import date

type_  = os.environ["_DPLY_CL_TYPE"]
title  = os.environ["_DPLY_CL_TITLE"].strip()
entry  = os.environ["_DPLY_CL_ENTRY"].lstrip("- ").strip()

def php_escape(s):
    return s.replace("\\", "\\\\").replace("'", "\\'")

TAG_MAP = {
    "Added": "new", "Changed": "improved", "Fixed": "fixed",
    "Removed": "improved", "Security": "security", "Deprecated": "improved",
}
tag   = TAG_MAP.get(type_, "improved")
today = date.today()
date_str = f"{today.strftime('%B')} {today.day}, {today.year}"

# ------------------------------------------------------------------
# 1. Update changelog.blade.php
# ------------------------------------------------------------------
blade_path = "resources/views/changelog.blade.php"
blade_entry = (
    "\n"
    "                [\n"
    f"                    'date'    => '{date_str}',\n"
    f"                    'tags'    => ['{tag}'],\n"
    f"                    'title'   => '{php_escape(title)}',\n"
    f"                    'summary' => '{php_escape(entry)}',\n"
    "                    'items'   => [],\n"
    "                ],"
)

marker = "$entries = ["
try:
    with open(blade_path) as f:
        blade = f.read()
    if marker in blade:
        idx = blade.index(marker) + len(marker)
        blade = blade[:idx] + blade_entry + blade[idx:]
        with open(blade_path, "w") as f:
            f.write(blade)
        print(f"  changelog.blade.php: [{tag}] {title}")
    else:
        print(f"  WARNING: could not find $entries in {blade_path}", file=sys.stderr)
except FileNotFoundError:
    print(f"  WARNING: {blade_path} not found", file=sys.stderr)

# ------------------------------------------------------------------
# 2. Update CHANGELOG.md
# ------------------------------------------------------------------
md_path = "CHANGELOG.md"
md_line = f"- {entry}"

try:
    with open(md_path) as f:
        md = f.read()
except FileNotFoundError:
    with open(md_path, "w") as f:
        f.write(f"# Changelog\n\n## [Unreleased]\n### {type_}\n{md_line}\n")
    print(f"  created {md_path}")
    sys.exit(0)

if "## [Unreleased]" in md:
    idx = md.index("## [Unreleased]") + len("## [Unreleased]")
    md = md[:idx] + f"\n### {type_}\n{md_line}" + md[idx:]
else:
    m = re.search(r"\n## ", md)
    pos = m.start() if m else len(md)
    md = md[:pos] + f"\n\n## [Unreleased]\n### {type_}\n{md_line}" + md[pos:]

with open(md_path, "w") as f:
    f.write(md)
print(f"  CHANGELOG.md: [{type_}] {md_line}")
PYEOF

    unset _DPLY_CL_TYPE _DPLY_CL_TITLE _DPLY_CL_ENTRY
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
            log "Changelog: [${AI_CL_TYPE:-Changed}] ${AI_CL_TITLE}"
            _update_changelog "${AI_CL_TYPE:-Changed}" "$AI_CL_TITLE" "$AI_CL_ENTRY"
            git add CHANGELOG.md resources/views/changelog.blade.php 2>/dev/null || true
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
