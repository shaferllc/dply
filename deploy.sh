#!/usr/bin/env bash
set -euo pipefail

# ===========================================================================
# RETIRED — BREAK-GLASS ONLY. Do NOT use for routine deploys.
#
# The canonical deploy engine is AtomicSiteDeployer (reached via the dashboard
# Deploy button, the queue path RunSiteDeploymentJob, or `dply:site:deploy`).
# It clones into a fresh releases/<ts> dir, writes .env via the site's
# env_file_path, atomically flips `current`, and health-checks the result.
#
# Running this script alongside that engine is what created prod's hybrid
# layout (a flat checkout + an atomic tree in one dir) and the recurring .env
# clobber: deploy.sh symlinks current/.env → shared/.env, while the engine
# writes .env from the DB. Pick ONE. For self-managed dply, the engine wins.
#
# Keep this only as a last-resort recovery tool when the control plane itself
# cannot deploy. See deploy/ATOMIC_RELEASES.md.
# ===========================================================================

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
# Atomic-release deploy
#
# Both the web host and every worker use the same immutable-release layout so a
# deploy is an atomic symlink swap — never an in-place mutation of running code.
# That is what fixes queued-job deserialization breaking after a deploy: a
# long-running Horizon never half-sees new code, and a restart relaunches it
# against a complete, self-consistent release.
#
#   $ROOT/repo/            persistent bare git mirror (the fetch target)
#   $ROOT/shared/.env      the live environment — NEVER lives inside a release
#   $ROOT/shared/storage/  persistent storage (logs, keys, uploads)
#   $ROOT/releases/<ts>/   immutable build artifact for one deploy
#   $ROOT/current -> releases/<ts>   the pointer nginx + supervisor read
#
# One-time bootstrap (seed shared/, repoint nginx + supervisor at current/):
#   see deploy/ATOMIC_RELEASES.md
#
# The remote script is fed over stdin via a QUOTED heredoc, so $vars inside it
# stay remote; the few local values are passed as positional args (no AcceptEnv
# dependency on the servers).
# ---------------------------------------------------------------------------
ORIGIN_URL="$(git remote get-url "$REMOTE")"
KEEP_RELEASES="${DEPLOY_KEEP_RELEASES:-5}"

deploy_release() {
  local HOST="$1" ROLE="$2" ROOT="$3" CMP="$4"
  /usr/bin/ssh "$HOST" 'bash -s' -- \
    "$ROLE" "$ROOT" "$BRANCH" "$PHP" "$CMP" "$ORIGIN_URL" "$KEEP_RELEASES" <<'REMOTE'
set -euo pipefail
ROLE="$1"; ROOT="$2"; BRANCH="$3"; PHP="$4"; COMPOSER="$5"; ORIGIN_URL="$6"; KEEP="$7"
REPO="$ROOT/repo"; SHARED="$ROOT/shared"; RELEASES="$ROOT/releases"
TS="$(date +%Y%m%d%H%M%S)"; NEW="$RELEASES/$TS"
p() { echo "[$ROLE] $*"; }

mkdir -p "$REPO" "$SHARED" "$RELEASES"

# Refuse to deploy without a shared env. Shipping a release with an empty/missing
# .env would null out APP_KEY and break decryption of every stored secret (SSH
# keys, provider tokens) — the exact prod hazard we must never trigger silently.
if [ ! -f "$SHARED/.env" ]; then
  echo "[$ROLE] FATAL: $SHARED/.env missing — run the one-time bootstrap in deploy/ATOMIC_RELEASES.md first." >&2
  exit 1
fi

p "Fetching origin/$BRANCH ..."
# A bare repo keeps HEAD as a *file* (not a dir), so test -e, not -d — otherwise
# the guard misfires on every deploy after the first and tries to re-clone over
# a populated repo/ ("destination already exists").
if [ ! -e "$REPO/HEAD" ] && [ ! -e "$REPO/.git" ]; then
  # Prefer the URL the server already authenticates with (e.g. an HTTPS remote
  # on the flat checkout) over the laptop's remote, which may be an SSH URL the
  # server has no key for.
  SRC_URL="$ORIGIN_URL"
  if git -C "$ROOT" remote get-url origin >/dev/null 2>&1; then
    SRC_URL="$(git -C "$ROOT" remote get-url origin)"
  fi
  git clone --bare "$SRC_URL" "$REPO"
  git --git-dir="$REPO" config remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'
fi
git --git-dir="$REPO" fetch origin "$BRANCH" --prune
COMMIT="$(git --git-dir="$REPO" rev-parse "refs/remotes/origin/$BRANCH")"

p "Building release $TS ($COMMIT) ..."
mkdir -p "$NEW"
git --git-dir="$REPO" archive "$COMMIT" | tar -x -C "$NEW"
echo "$COMMIT" > "$NEW/.release-commit"

# Wire shared state into the immutable release.
ln -sfn "$SHARED/.env" "$NEW/.env"
rm -rf "$NEW/storage"
ln -sfn "$SHARED/storage" "$NEW/storage"

cd "$NEW"
p "composer install ..."
$COMPOSER install --no-interaction --no-dev --optimize-autoloader --prefer-dist

if [ "$ROLE" = "web" ]; then
  p "npm ci && npm run build ..."
  npm ci --prefer-offline
  npm run build
fi

p "Caching framework (config/route/event/view) ..."
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan event:cache
$PHP artisan view:cache
$PHP artisan pennant:clear
# Rebuild the front-matter docs manifest + search index from this release's docs/.
$PHP artisan docs:flush
$PHP artisan docs:index

if [ "$ROLE" = "web" ]; then
  # public/storage is per-release; relink it to the (shared) storage each build.
  p "storage:link ..."
  $PHP artisan storage:link 2>/dev/null || true

  # Migrations run once, from the web release, against the shared DB before the
  # swap so new code never serves an old schema.
  p "migrate --force ..."
  $PHP artisan migrate --force
fi

# Atomic pointer swap: build the new symlink under a temp name, then mv -T over
# the live one — a single rename(2), so there is no window with a missing/half
# 'current'.
p "Swapping current -> releases/$TS ..."
ln -sfn "$NEW" "$ROOT/current.tmp"
mv -Tf "$ROOT/current.tmp" "$ROOT/current"

p "Restarting daemons onto the new release ..."
if [ "$ROLE" = "web" ]; then
  sudo supervisorctl restart dply-reverb 2>/dev/null || true
  sudo supervisorctl restart dply-pulse 2>/dev/null || true
  # php-fpm caches the realpath of 'current'; reload so it serves the new target.
  for svc in php8.5-fpm php8.4-fpm php8.3-fpm php-fpm; do
    if sudo systemctl reload "$svc" 2>/dev/null; then p "reloaded $svc"; break; fi
  done
else
  # Worker daemons are dply-managed systemd units (the canonical runtime for a
  # managed worker site): dply-site-<id>-horizon.service + dply-scheduler.service,
  # both with WorkingDirectory=current. They DON'T reload on a current/ swap on
  # their own — a long-running Horizon keeps the old release's code in memory —
  # so bounce them here. (Legacy supervisor programs are restarted best-effort
  # too, for any box not yet migrated off supervisor.)
  restarted_any=0
  for u in $(systemctl list-units --plain --no-legend 'dply-site-*-horizon.service' 2>/dev/null | awk '{print $1}'); do
    if sudo systemctl restart "$u" 2>/dev/null; then p "restarted $u"; restarted_any=1; fi
  done
  sudo systemctl restart dply-scheduler.service 2>/dev/null || true
  # Best-effort legacy supervisor fallback (no-op once migrated to systemd).
  sudo supervisorctl restart dply-horizon 2>/dev/null && restarted_any=1 || true
  sudo supervisorctl restart dply-scheduler 2>/dev/null || true
  sleep 3
  hz_ok=0
  for u in $(systemctl list-units --plain --no-legend 'dply-site-*-horizon.service' 2>/dev/null | awk '{print $1}'); do
    [ "$(systemctl is-active "$u" 2>/dev/null)" = "active" ] && hz_ok=1
  done
  sudo supervisorctl status dply-horizon 2>/dev/null | grep -q RUNNING && hz_ok=1 || true
  if [ "$hz_ok" = "1" ]; then
    p "OK: a Horizon master is running on releases/$TS."
  else
    echo "[$ROLE] ERROR: no Horizon master active after restart — investigate before trusting this box." >&2
    exit 1
  fi
fi

# Prune old releases (keep newest $KEEP), never deleting the live target.
p "Pruning old releases (keep $KEEP) ..."
LIVE="$(readlink -f "$ROOT/current")"
ls -1dt "$RELEASES"/*/ 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
  [ "$(readlink -f "$old")" = "$LIVE" ] && continue
  # sudo: root-running processes (certbot's acme-challenge webroot under
  # current/public, and the privileged managed-error-page writer under
  # current/.dply) drop root-owned files into whatever release is live at the
  # time. A plain `rm` as the deploy user then hits "Permission denied" on
  # prune. The path is guarded above to never be the live target.
  sudo rm -rf "$old"
done

# Post-deploy AI roadmap update (web only, best-effort). No-ops unless
# ROADMAP_AI_ENABLED=true and the LLM is configured; never fails the deploy.
# Runs synchronously so it's deterministic and reads the just-deployed commit.
if [ "$ROLE" = "web" ]; then
  p "Roadmap AI update (best-effort) ..."
  $PHP artisan dply:roadmap:ai-update --sync --commit="$COMMIT" 2>/dev/null || p "roadmap AI update skipped/failed (non-fatal)"
fi

p "Done: live on releases/$TS ($COMMIT)."
REMOTE
}

# ---------------------------------------------------------------------------
# 1b. Env-drift preflight — compare SHARED env KEY NAMES (never values) across
# web + workers so a key that exists only on one box (and silently breaks the
# queued jobs that run on the other) is caught before we ship. Warn-only by
# default; set DEPLOY_STRICT_ENV=1 to abort the deploy on drift.
# ---------------------------------------------------------------------------
if [ -n "$WORKER_HOSTS" ] && [ -x "$(dirname "$0")/deploy/check-env-drift.sh" ]; then
  hr
  log "Checking env drift across web + workers ..."
  hr
  "$(dirname "$0")/deploy/check-env-drift.sh" || {
    log "Env drift preflight failed (DEPLOY_STRICT_ENV=1). Aborting."
    exit 1
  }
fi

# ---------------------------------------------------------------------------
# 2. Deploy web server
# ---------------------------------------------------------------------------
hr
log "Deploying web server ($WEB_HOST)..."
hr
deploy_release "$WEB_HOST" web "$APP_DIR" "$COMPOSER"

# ---------------------------------------------------------------------------
# 3. Deploy worker servers in parallel
# ---------------------------------------------------------------------------
if [ -n "$WORKER_HOSTS" ]; then
  pids=()
  for WORKER_HOST in $WORKER_HOSTS; do
    (
      hr
      log "Deploying worker ($WORKER_HOST)..."
      hr
      deploy_release "$WORKER_HOST" worker "$WORKER_APP_DIR" "$WORKER_COMPOSER"
    ) &
    pids+=($!)
  done
  for pid in "${pids[@]}"; do
    wait "$pid"
  done
fi

hr
log "Deploy complete."
hr
