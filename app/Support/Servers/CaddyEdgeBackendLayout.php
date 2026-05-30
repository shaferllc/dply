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
     * Remove legacy :80 site fragments and install the edge Caddyfile.
     */
    public static function releasePort80Shell(): string
    {
        return self::installCanonicalCaddyfileShell()."\n".self::stripLegacySiteFragmentsShell()."\n".<<<'BASH'
dply_caddy_holds_port80() {
  ss -ltnpH 'sport = :80' 2>/dev/null | grep -qE '"caddy"|/caddy'
}
dply_release_caddy_port80() {
  local changed=0
  dply_strip_legacy_caddy_site_fragments
  changed=1
  if [ ! -f /etc/caddy/Caddyfile ] || grep -qF ':80 {' /etc/caddy/Caddyfile 2>/dev/null || grep -qF 'http://' /etc/caddy/Caddyfile 2>/dev/null; then
    dply_install_edge_caddyfile
    changed=1
  elif dply_caddy_holds_port80; then
    echo "[dply] Caddy holds :80 — installing edge-only Caddyfile (no :80 listener) and reloading…" >&2
    dply_install_edge_caddyfile
    changed=1
  fi
  if [ "$changed" -eq 1 ] || dply_caddy_holds_port80; then
    if systemctl is-active --quiet caddy 2>/dev/null; then
      caddy validate --config /etc/caddy/Caddyfile
      systemctl reload caddy
      sleep 2
    fi
  fi
}
dply_release_caddy_port80

BASH;
    }
}
