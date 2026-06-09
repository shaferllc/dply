<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Services\Servers\LiveState\EnvoyLiveStateProbe;

/**
 * Shared bash fragments for probing Envoy's localhost admin API (:9901).
 */
final class EnvoyAdminScript
{
    public const ADMIN_BASE = 'http://127.0.0.1:9901';

    /**
     * Bash that exits 0 when /server_info responds, else prints [dply] diagnostics.
     */
    public static function waitUntilReady(int $attempts = 20, int $sleepSeconds = 1): string
    {
        $attempts = max(1, $attempts);
        $sleepSeconds = max(1, $sleepSeconds);

        return self::sharedHelpers().str_replace(
            ['__ATTEMPTS__', '__SLEEP__'],
            [(string) $attempts, (string) $sleepSeconds],
            <<<'BASH'

i=0
while [ "$i" -lt __ATTEMPTS__ ]; do
  if dply_envoy_admin_ready; then
    exit 0
  fi
  i=$((i + 1))
  sleep __SLEEP__
done
dply_envoy_admin_failure "Envoy admin API did not become ready at 127.0.0.1:9901 within __ATTEMPTS__s"
BASH,
        );
    }

    /**
     * Bash for {@see EnvoyLiveStateProbe}.
     */
    public static function liveStateProbeScript(): string
    {
        return self::sharedHelpers().str_replace(
            ['__ATTEMPTS__', '__SLEEP__'],
            ['15', '1'],
            <<<'BASH'
set +e
if ! command -v curl >/dev/null 2>&1; then
  echo "[dply] curl not installed; cannot query envoy admin API" >&2
  exit 2
fi
if ! dply_envoy_admin_ready; then
  i=0
  while [ "$i" -lt __ATTEMPTS__ ]; do
    if dply_envoy_admin_ready; then
      break
    fi
    i=$((i + 1))
    sleep __SLEEP__
  done
fi
if ! dply_envoy_admin_ready; then
  dply_envoy_admin_failure "Envoy admin interface unavailable at 127.0.0.1:9901"
fi
fetch() {
  dply_envoy_admin_curl "$1" 2>/dev/null || echo '{}'
}
echo '###dply-section:listeners###'
fetch '/listeners?format=json'
echo '###dply-section:end###'
echo '###dply-section:clusters###'
fetch '/clusters?format=json'
echo '###dply-section:end###'
echo '###dply-section:runtime###'
fetch '/server_info'
echo '###dply-section:end###'
echo '###dply-section:config###'
fetch '/config_dump?include_eds=false'
echo '###dply-section:end###'
echo '###dply-section:stats###'
fetch '/stats/prometheus'
echo '###dply-section:end###'
BASH,
        );
    }

    /**
     * Bash run before `systemctl enable --now envoy` during edge cutover.
     */
    public static function prepareForCutoverScript(): string
    {
        return self::sharedHelpers().self::preparePort80Script().<<<'BASH'
set -e
systemctl reset-failed envoy 2>/dev/null || true
systemctl stop envoy 2>/dev/null || true
if ss -ltnH 'sport = :80' 2>/dev/null | grep -q ':80'; then
  echo "[dply] Port :80 is still in use before starting Envoy — another process must release it first:" >&2
  ss -ltnpH 'sport = :80' 2>/dev/null | head -5 >&2 || true
  dply_envoy_port80_still_blocked_hint
  exit 1
fi
BASH;
    }

    /**
     * Safe start/restart used by server manage actions (Overview → Service).
     */
    public static function startServiceScript(): string
    {
        return self::sharedHelpers().self::preparePort80Script().<<<'BASH'
set -e
if ! command -v curl >/dev/null 2>&1; then
  echo "[dply] curl not installed; cannot verify envoy admin API" >&2
  exit 2
fi
systemctl reset-failed envoy 2>/dev/null || true
systemctl stop envoy 2>/dev/null || true
if ss -ltnH 'sport = :80' 2>/dev/null | grep -q ':80'; then
  echo "[dply] Port :80 is still in use — stop the process below, then start Envoy again:" >&2
  ss -ltnpH 'sport = :80' 2>/dev/null | head -5 >&2 || true
  dply_envoy_port80_still_blocked_hint
  exit 1
fi
if command -v envoy >/dev/null 2>&1; then
  envoy --mode validate -c /etc/envoy/envoy.yaml
elif [ -x /usr/local/bin/envoy ]; then
  /usr/local/bin/envoy --mode validate -c /etc/envoy/envoy.yaml
fi
systemctl start envoy
i=0
while [ "$i" -lt 25 ]; do
  if dply_envoy_admin_ready; then
    echo "[dply] Envoy admin API is up at 127.0.0.1:9901"
    exit 0
  fi
  i=$((i + 1))
  sleep 1
done
dply_envoy_admin_failure "Envoy started but admin API at 127.0.0.1:9901 did not become ready"
BASH;
    }

    /**
     * Stop competing edge/primary webservers and release Caddy from :80.
     */
    public static function preparePort80Script(): string
    {
        return self::stopCompetingEdgeListenersScript()
            .self::stopCompetingPrimaryWebserverScript()
            .self::releaseCaddyFromPort80Script();
    }

    /**
     * Edge mode: Caddy is a backend on high ports only. Legacy sites-enabled/*.caddy
     * (without -backend suffix) bind :80 and block Envoy.
     */
    private static function releaseCaddyFromPort80Script(): string
    {
        return CaddyEdgeBackendLayout::releasePort80Shell();
    }

    private static function stopCompetingPrimaryWebserverScript(): string
    {
        return <<<'BASH'
for u in nginx apache2 httpd openlitespeed; do
  systemctl stop "$u" 2>/dev/null || true
done

BASH;
    }

    private static function stopCompetingEdgeListenersScript(): string
    {
        return <<<'BASH'
for u in haproxy traefik; do
  systemctl stop "$u" 2>/dev/null || true
done

BASH;
    }

    private static function sharedHelpers(): string
    {
        return str_replace('__ADMIN__', self::ADMIN_BASE, <<<'BASH'
dply_envoy_admin_curl() {
  curl -4sf --max-time 5 "__ADMIN__$1"
}
dply_envoy_admin_ready() {
  dply_envoy_admin_curl '/server_info' >/dev/null 2>&1
}
dply_envoy_port80_still_blocked_hint() {
  if ss -ltnpH 'sport = :80' 2>/dev/null | grep -qE '"caddy"|/caddy'; then
    echo "[dply] Caddy still binds :80 after cleanup — edge mode needs per-site *-backend.caddy only. Check dply-custom routes or a :80 block in /etc/caddy/Caddyfile." >&2
  fi
}
dply_envoy_admin_failure() {
  local headline="$1"
  SVC_STATE=$(systemctl is-active envoy 2>/dev/null || echo unknown)
  if [ "$SVC_STATE" = "inactive" ] || [ "$SVC_STATE" = "failed" ] || [ "$SVC_STATE" = "dead" ] || [ "$SVC_STATE" = "unknown" ]; then
    echo "[dply] Envoy is not running (envoy.service is $SVC_STATE). Start it from Overview lifecycle controls or run: journalctl -u envoy -n 50" >&2
    exit 1
  fi
  if [ "$SVC_STATE" = "activating" ] || [ "$SVC_STATE" = "deactivating" ]; then
    BIND_ERR=$(journalctl -u envoy -n 25 --no-pager 2>/dev/null | grep -Ei 'cannot bind|error initializing|critical' | tail -1 || true)
    echo "[dply] Envoy is crash-looping (envoy.service is $SVC_STATE) — the process exits before admin :9901 opens. Usually :80 is still taken or envoy.yaml fails at runtime." >&2
    if [ -n "$BIND_ERR" ]; then
      echo "[dply] Last envoy log: $BIND_ERR" >&2
    fi
    ss -ltnpH 'sport = :80' 2>/dev/null | head -3 >&2 || true
    exit 1
  fi
  if ! ss -ltnH 'sport = :9901' 2>/dev/null | grep -q ':9901'; then
    if ss -ltnH 'sport = :80' 2>/dev/null | grep -q ':80'; then
      echo "[dply] $headline — envoy.service is $SVC_STATE but admin :9901 is closed; port :80 is still occupied (Envoy exits when :80 cannot bind). Free :80, then restart envoy." >&2
      ss -ltnpH 'sport = :80' 2>/dev/null | head -3 >&2 || true
    else
      echo "[dply] $headline — envoy.service is $SVC_STATE but admin :9901 is not listening. Validate /etc/envoy/envoy.yaml and check journalctl -u envoy." >&2
    fi
  else
    echo "[dply] $headline — admin port is open but /server_info failed; check journalctl -u envoy." >&2
  fi
  journalctl -u envoy -n 8 --no-pager 2>/dev/null | tail -5 >&2 || true
  exit 1
}

BASH);
    }
}
