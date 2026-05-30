<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Builds the remote certbot invocation for a site, accounting for edge-proxy
 * layouts where HTTP is terminated by Envoy/Traefik/HAProxy and the docroot is
 * served by a Caddy backend — not the recorded primary webserver engine.
 */
final class LetsEncryptCertbotCommandBuilder
{
    /**
     * @param  list<string>  $domains
     */
    public static function build(Site $site, array $domains, string $email): string
    {
        $certbot = self::certbotInvocation($site, $domains, $email);

        if (! self::usesWebrootPath($site)) {
            return self::wrapNginxPreflight($site, $certbot);
        }

        $webroot = escapeshellarg($site->effectiveDocumentRoot());
        $preflight = self::usesWebrootChallenge($site)
            ? self::acmePreflightScript($domains, $webroot)
            : '';

        return "set -e\nmkdir -p {$webroot}/.well-known/acme-challenge\n{$preflight}{$certbot}";
    }

    private static function wrapNginxPreflight(Site $site, string $certbot): string
    {
        if ($site->webserver() !== 'nginx' || self::usesWebrootChallenge($site)) {
            return $certbot;
        }

        return "set -e\n".self::nginxPort80PreflightScript().$certbot;
    }

    public static function usesWebrootChallenge(Site $site): bool
    {
        $site->loadMissing('server');
        $edgeProxy = $site->server?->edgeProxy();

        return is_string($edgeProxy)
            && in_array($edgeProxy, ['traefik', 'haproxy', 'envoy', 'openresty'], true);
    }

    public static function usesWebrootPath(Site $site): bool
    {
        if (self::usesWebrootChallenge($site)) {
            return true;
        }

        return in_array($site->webserver(), ['nginx', 'openlitespeed', 'traefik', 'caddy'], true);
    }

    /**
     * @param  list<string>  $domains
     */
    private static function certbotInvocation(Site $site, array $domains, string $email): string
    {
        $flags = collect($domains)
            ->map(fn (string $domain): string => '-d '.escapeshellarg($domain))
            ->implode(' ');

        if (self::usesWebrootChallenge($site)) {
            return sprintf(
                'certbot certonly --webroot -w %s --preferred-challenges http %s --non-interactive --agree-tos -m %s 2>&1',
                escapeshellarg($site->effectiveDocumentRoot()),
                $flags,
                escapeshellarg($email),
            );
        }

        return match ($site->webserver()) {
            'apache' => sprintf(
                'certbot --apache %s --non-interactive --agree-tos -m %s --redirect 2>&1',
                $flags,
                escapeshellarg($email),
            ),
            'nginx', 'openlitespeed', 'traefik', 'caddy' => sprintf(
                'certbot certonly --webroot -w %s --preferred-challenges http %s --non-interactive --agree-tos -m %s 2>&1',
                escapeshellarg($site->effectiveDocumentRoot()),
                $flags,
                escapeshellarg($email),
            ),
            default => sprintf(
                'certbot --nginx %s --non-interactive --agree-tos -m %s --redirect 2>&1',
                $flags,
                escapeshellarg($email),
            ),
        };
    }

    /**
     * Plain nginx sites use certbot --nginx, which restarts nginx on :80. When
     * a failed edge-proxy install left Caddy on :80, stop Caddy and ensure
     * nginx owns the port before invoking certbot.
     */
    private static function nginxPort80PreflightScript(): string
    {
        return <<<'BASH'
dply_nginx_owns_port80() {
  ss -ltnpH 'sport = :80' 2>/dev/null | grep -qE '"nginx"|/nginx'
}
dply_caddy_owns_port80() {
  ss -ltnpH 'sport = :80' 2>/dev/null | grep -qE '"caddy"|/caddy'
}
if ! dply_nginx_owns_port80; then
  if dply_caddy_owns_port80; then
    echo "[dply] Caddy holds :80 on a plain nginx site — stopping Caddy so certbot can use nginx." >&2
    systemctl stop caddy 2>/dev/null || true
  fi
  if ! systemctl is-active --quiet nginx 2>/dev/null; then
    systemctl enable --now nginx || exit 21
  fi
  if ! dply_nginx_owns_port80; then
    systemctl restart nginx || exit 22
  fi
  if ! dply_nginx_owns_port80; then
    echo "[dply] nginx is not listening on :80 — another process may own the port." >&2
    ss -ltnpH 'sport = :80' 2>/dev/null | head -5 >&2 || true
    exit 23
  fi
fi

BASH;
    }

    /**
     * @param  list<string>  $domains
     */
    private static function acmePreflightScript(array $domains, string $webrootEscaped): string
    {
        $hostname = $domains[0] ?? '';
        if ($hostname === '') {
            return '';
        }

        $hostForCurl = addcslashes($hostname, "\\\"'`$!");

        return <<<BASH
PROBE="\$(openssl rand -hex 8 2>/dev/null || echo dplyprobe)"
printf '%s' "\$PROBE" > {$webrootEscaped}/.well-known/acme-challenge/dply-probe
CODE="\$(curl -fsS -o /dev/null -w '%{http_code}' -H "Host: {$hostForCurl}" 'http://127.0.0.1/.well-known/acme-challenge/dply-probe' 2>/dev/null || echo 000)"
rm -f {$webrootEscaped}/.well-known/acme-challenge/dply-probe
if [ "\$CODE" != "200" ]; then
  echo "[dply] ACME preflight failed: http://{$hostname}/.well-known/acme-challenge/ returned HTTP \$CODE via local port 80." >&2
  echo "[dply] Ensure the edge proxy is active, backend configs are applied, and the testing URL serves files from the document root." >&2
  exit 2
fi

BASH;
    }
}
