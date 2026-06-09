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

# Skip when opted out, or when the push was initiated from inside Claude Code
# (a nested LLM call contends with the parent session and stalls).
if [ -n "${DPLY_SKIP_AI_HOOKS:-}" ] || [ -n "${CLAUDECODE:-}" ]; then
  echo "[roadmap] skipped (Claude Code session or DPLY_SKIP_AI_HOOKS)."
  exit 0
fi

RANGE="${DPLY_PUSH_RANGE:-}"
# Tip being pushed: right side of "a..b", else the lone sha.
tip="${RANGE##*..}"
[ -z "$tip" ] && tip="$(git rev-parse HEAD)"

command -v php >/dev/null 2>&1 || { echo "[roadmap] php not found — skipping."; exit 0; }

# A timeout (when available) backstops a slow/wedged model so it can't stall the push.
TIMEOUT_BIN="$(command -v timeout || command -v gtimeout || true)"
echo "[roadmap] updating from $tip ..."
${TIMEOUT_BIN:+$TIMEOUT_BIN 150} php artisan dply:roadmap:ai-update --sync --commit="$tip" </dev/null 2>/dev/null \
  || echo "[roadmap] skipped/failed/timed out (non-fatal)."

exit 0
