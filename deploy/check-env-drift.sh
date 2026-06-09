#!/usr/bin/env bash
set -euo pipefail

# Force byte-wise collation everywhere. sort/comm MUST agree on ordering or comm
# produces phantom diffs — and the remote `sort` (server locale) and the local
# `comm` (macOS locale) order keys like DOMAINS vs DOMAIN_STRATEGY differently
# under a UTF-8 locale. C locale = pure byte order, identical on every box.
export LC_ALL=C

# ---------------------------------------------------------------------------
# check-env-drift.sh — detect SHARED env keys missing on web or worker boxes.
#
# Why this exists: each role keeps its own hand-maintained shared/.env, and they
# drift. A key that lands only on the web box but is needed by a queued job (which
# runs on the worker) fails *silently* — e.g. testing-hostname provisioning,
# edge/Cloudflare publishes, Stripe billing jobs all broke this way because the
# worker .env was missing the relevant secrets.
#
# This compares KEY NAMES ONLY across hosts (never values — nothing secret is
# read, transferred, or printed). Keys in deploy/env/app-only.keys and
# deploy/env/worker-only.keys are treated as intentional per-role and ignored.
# Everything else is SHARED and must exist on every role.
#
# Usage:
#   ./deploy/check-env-drift.sh            # report drift, exit 0 (warn only)
#   DEPLOY_STRICT_ENV=1 ./deploy/check-env-drift.sh   # exit 1 if drift found
#
# Config (same vars as deploy.sh; .deploy.env is sourced if present):
#   DEPLOY_HOST            web/app SSH host                 (required)
#   DEPLOY_WORKER_HOSTS    space-separated worker SSH hosts (optional)
#   DEPLOY_APP_DIR         app root on web    (default /var/www/dply)
#   DEPLOY_WORKER_APP_DIR  app root on worker (default = DEPLOY_APP_DIR)
# ---------------------------------------------------------------------------

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
[ -f "$HERE/../.deploy.env" ] && source "$HERE/../.deploy.env"

WEB_HOST="${DEPLOY_HOST:?Set DEPLOY_HOST}"
WORKER_HOSTS="${DEPLOY_WORKER_HOSTS:-}"
APP_DIR="${DEPLOY_APP_DIR:-/var/www/dply}"
WORKER_APP_DIR="${DEPLOY_WORKER_APP_DIR:-$APP_DIR}"
STRICT="${DEPLOY_STRICT_ENV:-0}"

# Keys whose VALUE (not just presence) must be byte-identical on every role.
# APP_KEY is the canonical example: three different APP_KEYs across web/workers
# silently broke decryption (DecryptException "MAC is invalid") and the Livewire
# asset route. Names always match — only a value-hash compare catches this.
CRITICAL_VALUE_KEYS="${DEPLOY_CRITICAL_VALUE_KEYS:-APP_KEY}"

APP_ONLY_FILE="$HERE/env/app-only.keys"
WORKER_ONLY_FILE="$HERE/env/worker-only.keys"

# Read an allowlist file into a sorted, comment-stripped key list.
read_allow() {
  [ -f "$1" ] || { echo ""; return; }
  grep -vE '^\s*(#|$)' "$1" | tr -d ' \t' | sort -u
}

# Pull KEY NAMES ONLY from a host's shared/.env (left of the first '='). No
# values ever leave the box.
remote_keys() {
  local host="$1" root="$2"
  /usr/bin/ssh "$host" "LC_ALL=C grep -oE '^[A-Z][A-Z0-9_]*=' '$root/shared/.env' 2>/dev/null | sed 's/=\$//' | LC_ALL=C sort -u"
}

# SHA-256 of a single key's VALUE on a host. The value is hashed remotely and
# only the digest leaves the box — the secret itself never transits anywhere.
# Empty / missing key → empty string.
remote_value_hash() {
  local host="$1" root="$2" key="$3"
  /usr/bin/ssh "$host" "v=\$(grep -E '^$key=' '$root/shared/.env' 2>/dev/null | head -1 | cut -d= -f2-); [ -n \"\$v\" ] && printf %s \"\$v\" | sha256sum | cut -c1-16 || echo ''"
}

APP_ONLY="$(read_allow "$APP_ONLY_FILE")"
WORKER_ONLY="$(read_allow "$WORKER_ONLY_FILE")"

echo "[env-drift] Reading key names from web host ($WEB_HOST) ..."
WEB_KEYS="$(remote_keys "$WEB_HOST" "$APP_DIR")"

drift_found=0

if [ -z "$WORKER_HOSTS" ]; then
  echo "[env-drift] No DEPLOY_WORKER_HOSTS set — nothing to compare against. Done."
  exit 0
fi

for wh in $WORKER_HOSTS; do
  echo "[env-drift] Reading key names from worker ($wh) ..."
  WORKER_KEYS="$(remote_keys "$wh" "$WORKER_APP_DIR")"

  # SHARED universe = (web ∪ worker) − app_only − worker_only
  UNIVERSE="$(printf '%s\n%s\n' "$WEB_KEYS" "$WORKER_KEYS" | sort -u)"
  SHARED="$(comm -23 <(printf '%s\n' "$UNIVERSE") <(printf '%s\n' "$APP_ONLY"))"
  SHARED="$(comm -23 <(printf '%s\n' "$SHARED")   <(printf '%s\n' "$WORKER_ONLY"))"

  MISSING_ON_WORKER="$(comm -23 <(printf '%s\n' "$SHARED") <(printf '%s\n' "$WORKER_KEYS"))"
  MISSING_ON_WEB="$(comm -23    <(printf '%s\n' "$SHARED") <(printf '%s\n' "$WEB_KEYS"))"

  if [ -n "$MISSING_ON_WORKER" ]; then
    drift_found=1
    echo "  ✗ SHARED keys present on web but MISSING on worker $wh:"
    printf '      %s\n' $MISSING_ON_WORKER
  fi
  if [ -n "$MISSING_ON_WEB" ]; then
    drift_found=1
    echo "  ✗ SHARED keys present on worker $wh but MISSING on web:"
    printf '      %s\n' $MISSING_ON_WEB
  fi
  if [ -z "$MISSING_ON_WORKER" ] && [ -z "$MISSING_ON_WEB" ]; then
    echo "  ✓ $wh in sync with web (shared key names)."
  fi

  # Critical VALUE parity — names matching isn't enough for keys like APP_KEY.
  for key in $CRITICAL_VALUE_KEYS; do
    wv="$(remote_value_hash "$WEB_HOST" "$APP_DIR" "$key")"
    kv="$(remote_value_hash "$wh" "$WORKER_APP_DIR" "$key")"
    if [ "$wv" != "$kv" ]; then
      drift_found=1
      echo "  ✗ CRITICAL: $key VALUE differs (web=${wv:-<empty>} $wh=${kv:-<empty>}) — must be identical."
    else
      echo "  ✓ $key value matches on $wh."
    fi
  done
done

echo "[env-drift] ────────────────────────────────────────"
if [ "$drift_found" = "1" ]; then
  echo "[env-drift] DRIFT DETECTED. Reconcile per deploy/ENV_SYNC.md before relying on either box."
  [ "$STRICT" = "1" ] && exit 1
  echo "[env-drift] (warn-only; set DEPLOY_STRICT_ENV=1 to fail)"
else
  echo "[env-drift] No drift in shared keys. ✓"
fi
exit 0
