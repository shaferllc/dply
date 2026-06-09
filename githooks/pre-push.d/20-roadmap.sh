#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# 20-roadmap.sh — refresh the AI roadmap from the commits being pushed.
#
# Runs `dply:roadmap:ai-update` synchronously, pinned to the tip of the push.
# Writes to the database (roadmap_ai_runs + roadmap items), so there is nothing
# to commit. No-ops when ROADMAP_AI_ENABLED is false or the LLM isn't configured
# (the command handles that). Best-effort: never fails the push.
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

# Hard wall-clock cap so a hung LLM call can't wedge `git push`.
_to() { # _to <seconds> <cmd...>
  local s="$1"; shift
  if command -v timeout >/dev/null 2>&1; then timeout "$s" "$@"
  elif command -v gtimeout >/dev/null 2>&1; then gtimeout "$s" "$@"
  else perl -e 'alarm shift; exec @ARGV' "$s" "$@"
  fi
}

[ -n "${DPLY_SKIP_AI_HOOKS:-}" ] && { echo "[roadmap] skipped (DPLY_SKIP_AI_HOOKS)."; exit 0; }

RANGE="${DPLY_PUSH_RANGE:-}"
# Tip being pushed: right side of "a..b", else the lone sha.
tip="${RANGE##*..}"
[ -z "$tip" ] && tip="$(git rev-parse HEAD)"

command -v php >/dev/null 2>&1 || { echo "[roadmap] php not found — skipping."; exit 0; }

echo "[roadmap] updating from $tip ..."
_to "${DPLY_ROADMAP_TIMEOUT:-120}" php artisan dply:roadmap:ai-update --sync --commit="$tip" </dev/null 2>/dev/null \
  || echo "[roadmap] skipped/failed/timed out (non-fatal)."

exit 0
