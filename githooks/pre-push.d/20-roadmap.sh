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

RANGE="${DPLY_PUSH_RANGE:-}"
# Tip being pushed: right side of "a..b", else the lone sha.
tip="${RANGE##*..}"
[ -z "$tip" ] && tip="$(git rev-parse HEAD)"

command -v php >/dev/null 2>&1 || { echo "[roadmap] php not found — skipping."; exit 0; }

echo "[roadmap] updating from $tip ..."
php artisan dply:roadmap:ai-update --sync --commit="$tip" 2>/dev/null \
  || echo "[roadmap] skipped/failed (non-fatal)."

exit 0
