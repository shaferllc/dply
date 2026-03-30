#!/usr/bin/env bash
# Run ON THE REMOTE SERVER after provisioning. Replace SIGNED_URL_* with values from:
#   php artisan dply:task-runner-verify-webhooks {task_ulid}
#
# Requirements: curl, jq optional. APP_URL must be reachable from this host (public URL or VPN).

set -euo pipefail

# Example: export from your laptop after running the artisan command (quote the full URL).
# SIGNED_UPDATE_OUTPUT_URL='https://your-app.test/webhook/task/update-output/01....?signature=...'
# SIGNED_FINISHED_URL='https://your-app.test/webhook/task/mark-as-finished/01....?signature=...'

if [[ -z "${SIGNED_UPDATE_OUTPUT_URL:-}" ]]; then
  echo "Set SIGNED_UPDATE_OUTPUT_URL to the signed update-output URL from artisan." >&2
  exit 1
fi

echo "Posting heartbeat to update-output…"
curl -sS -X POST "$SIGNED_UPDATE_OUTPUT_URL" \
  -H 'Content-Type: application/json' \
  -d "{\"output\":\"[$(date -u +%Y-%m-%dT%H:%M:%SZ)] callback test from $(hostname)\\n\"}"

echo
echo "OK. Check task_runner_tasks.output in the app DB."

if [[ -n "${SIGNED_FINISHED_URL:-}" ]]; then
  echo "Posting mark-as-finished…"
  curl -sS -X POST "$SIGNED_FINISHED_URL" \
    -H 'Content-Type: application/json' \
    -d '{}'
  echo
fi
