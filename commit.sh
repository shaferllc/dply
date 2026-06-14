#!/usr/bin/env bash
set -euo pipefail

# ===========================================================================
# Commit helper — stage, AI-generate a commit message + CHANGELOG entry, commit,
# and push. This does NOT deploy.
#
# The canonical deploy engine is AtomicSiteDeployer (reached via the dashboard
# Deploy button, the queue path RunSiteDeploymentJob, or `dply:site:deploy`),
# which pulls from origin. This script's only job is to get a tidy, changelog'd
# commit onto origin; the control plane takes it from there.
# ===========================================================================

# ---------------------------------------------------------------------------
# Usage: ./commit.sh ["commit message"]
#
# Optional env:
#   DEPLOY_REMOTE   Git remote (default: origin)
#   DEPLOY_BRANCH   Git branch (default: main)
#
# AI commit messages + CHANGELOG entries are generated automatically when the
# `claude` CLI (Claude Code) is available in PATH. No API key required.
# ---------------------------------------------------------------------------

# Load config from .deploy.env if it exists (not committed to git)
# shellcheck source=/dev/null
[ -f "$(dirname "$0")/.deploy.env" ] && source "$(dirname "$0")/.deploy.env"

COMMIT_MSG="${1:-chore: commit}"
REMOTE="${DEPLOY_REMOTE:-origin}"
BRANCH="${DEPLOY_BRANCH:-main}"

log() { echo "[commit] $*"; }
hr()  { echo "[commit] ────────────────────────────────────────"; }

# ---------------------------------------------------------------------------
# AI commit message + changelog generation via `claude -p` (Claude Code CLI).
#
# Sets globals: AI_COMMIT_MSG, AI_CL_TYPE, AI_CL_TITLE, AI_CL_ENTRY
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
# Commit and push
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

hr
log "Done. Pushed to $REMOTE/$BRANCH — no deploy performed."
hr
