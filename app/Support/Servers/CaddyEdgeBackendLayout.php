<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Caddy layout when Traefik/HAProxy/Envoy owns :80 — backends listen on high
 * ports via *-backend.caddy (+ optional *-tls.caddy on :443).
 */
final class CaddyEdgeBackendLayout
{
    public const CADDYFILE_PATH = '/etc/caddy/Caddyfile';

    public const BACKUP_PATH = '/etc/caddy/Caddyfile.dply-edge-bak';

    /**
     * Global Caddyfile with no :80 listener — only imported site fragments.
     */
    public static function canonicalCaddyfile(): string
    {
        return <<<'CADDY'
# dply: Caddy is a per-site backend only — the active edge proxy owns :80.
{
	admin off
	auto_https off
}

import /etc/caddy/sites-enabled/*.caddy
CADDY;
    }

    /**
     * Bash: backup the existing Caddyfile once, then write the edge canonical.
     */
    public static function installCanonicalCaddyfileShell(): string
    {
        return <<<'BASH'
dply_install_edge_caddyfile() {
  if [ ! -f /etc/caddy/Caddyfile.dply-edge-bak ] && [ -f /etc/caddy/Caddyfile ]; then
    cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.dply-edge-bak
  fi
  cat > /etc/caddy/Caddyfile <<'DPLY_EDGE_CADDYFILE'
# dply: Caddy is a per-site backend only — the active edge proxy owns :80.
{
	admin off
	auto_https off
}

import /etc/caddy/sites-enabled/*.caddy
DPLY_EDGE_CADDYFILE
}
BASH;
    }

    /**
     * Drop legacy per-site :80 fragments; keep *-backend.caddy and *-tls.caddy.
     */
    public static function stripLegacySiteFragmentsShell(): string
    {
        return <<<'BASH'
dply_strip_legacy_caddy_site_fragments() {
  for f in /etc/caddy/sites-enabled/*.caddy; do
    [ -e "$f" ] || continue
    case "$f" in
      *-backend.caddy|*-tls.caddy) continue ;;
    esac
    rm -f "$f"
  done
  for f in /etc/caddy/sites-enabled/dply-custom-*.caddy; do
    [ -e "$f" ] || continue
    rm -f "$f"
  done
}
BASH;
    }

    /**
     * Remove any site fragment that still declares a :80 / plain-HTTP listener
     * (including misnamed *-tls.caddy left from a prior primary-Caddy install).
     */
    public static function stripPort80ListenerFragmentsShell(): string
    {
        return <<<'BASH'
dply_caddy_fragment_binds_port80() {
  local f="$1"
  grep -qE '(:80[[:space:]{]|^[[:space:]]*:80[[:space:]]*$)' "$f" 2>/dev/null && return 0
  grep -qE '\bhttp://' "$f" 2>/dev/null && return 0
  return 1
}
dply_strip_port80_caddy_site_fragments() {
  for f in /etc/caddy/sites-enabled/*.caddy; do
    [ -e "$f" ] || continue
    if dply_caddy_fragment_binds_port80 "$f"; then
      rm -f "$f"
    fi
  done
}
BASH;
    }

    /**
     * Remove legacy :80 site fragments, install the edge Caddyfile, restart Caddy,
     * and exit non-zero when Caddy still holds :80 (so Envoy cutover can abort).
     */
    public static function releasePort80Shell(): string
    {
        return self::installCanonicalCaddyfileShell()."\n"
            .self::stripLegacySiteFragmentsShell()."\n"
            .self::stripPort80ListenerFragmentsShell()."\n"
            .<<<'BASH'
dply_caddy_holds_port80() {
  ss -ltnpH 'sport = :80' 2>/dev/null | grep -qE '"caddy"|/caddy'
}
dply_release_caddy_port80() {
  dply_strip_legacy_caddy_site_fragments
  dply_strip_port80_caddy_site_fragments
  dply_install_edge_caddyfile
  if systemctl is-active --quiet caddy 2>/dev/null; then
    caddy validate --config /etc/caddy/Caddyfile || exit 11
    systemctl restart caddy
  else
    systemctl enable --now caddy || exit 13
  fi
  i=0
  while [ "$i" -lt 12 ]; do
    if ! dply_caddy_holds_port80; then
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done
  if dply_caddy_holds_port80; then
    echo "[dply] Caddy still binds :80 after restart — edge mode needs per-site *-backend.caddy only. Check dply-custom routes or a :80 block in /etc/caddy/Caddyfile." >&2
    ss -ltnpH 'sport = :80' 2>/dev/null | head -5 >&2 || true
    ls -la /etc/caddy/sites-enabled/ 2>/dev/null | head -20 >&2 || true
    exit 12
  fi
}
dply_release_caddy_port80

BASH;
    }
}
