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
     * @param  string|null  $certName  certbot lineage to pin via --cert-name;
     *                                 defaults to {@see lineageFor()}
     */
    public static function build(
        Site $site,
        array $domains,
        string $email,
        ?string $certName = null,
        bool $forceRenewal = false,
    ): string {
        $certbot = self::certbotInvocation($site, $domains, $email, $certName ?? self::lineageFor($domains), $forceRenewal);

        if (! self::usesWebrootPath($site)) {
            return self::ensureCertbotInstalled($site).self::wrapNginxPreflight($site, $certbot);
        }

        $webroot = escapeshellarg($site->effectiveDocumentRoot());
        $preflight = self::usesWebrootChallenge($site)
            ? self::acmePreflightScript($domains, $webroot)
            : '';

        return self::ensureCertbotInstalled($site)."set -e\nmkdir -p {$webroot}/.well-known/acme-challenge\n{$preflight}{$certbot}";
    }

    /**
     * The certbot lineage name for a domain set. Deliberately the first domain,
     * because that is what the TLS resolver derives /etc/letsencrypt/live/<dir>
     * from. Left unpinned, certbot invents its own name and silently appends
     * -0001 whenever the requested SAN set differs from an existing lineage —
     * so the material lands in a directory the generated vhost never points at,
     * and the site keeps serving the stale cert.
     *
     * @param  list<string>  $domains
     */
    public static function lineageFor(array $domains): string
    {
        return strtolower(trim($domains[0] ?? ''));
    }

    /**
     * Ensure certbot (and the matching plugin) is installed before issuance.
     * Idempotent: a no-op when certbot is already present (the default — it's
     * installed at provision time), so this is zero behaviour change. It exists
     * so provisioning can optionally defer certbot off its critical path
     * (server_provision.defer_certbot) without breaking the first cert request.
     */
    private static function ensureCertbotInstalled(Site $site): string
    {
        $plugin = match ($site->webserver()) {
            'apache' => ' python3-certbot-apache',
            'nginx' => ' python3-certbot-nginx',
            default => '',
        };

        return 'command -v certbot >/dev/null 2>&1 || { '
            ."echo '[dply] certbot not present — installing for cert issuance…'; "
            .'DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null 2>&1 || true; '
            .'DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends certbot'.$plugin.' || true; '
            ."}\n";
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
     * True when issuance runs `certbot certonly` — certbot only OBTAINS the
     * cert and dply must install it into the managed vhost itself (then reload).
     * False for the --apache/--nginx installer plugins, which wire the cert in
     * directly. Mirrors the certonly branches of certbotInvocation(): without
     * this, a plain-nginx custom domain gets a real per-host cert on disk but
     * its vhost is never re-pointed off the substitute (shared-wildcard) cert.
     */
    public static function usesCertonly(Site $site): bool
    {
        if (self::usesWebrootChallenge($site)) {
            return true;
        }

        return in_array($site->webserver(), ['nginx', 'openlitespeed', 'traefik', 'caddy'], true);
    }

    /**
     * @param  list<string>  $domains
     */
    private static function certbotInvocation(
        Site $site,
        array $domains,
        string $email,
        string $certName,
        bool $forceRenewal,
    ): string {
        $flags = collect($domains)
            ->map(fn (string $domain): string => '-d '.escapeshellarg($domain))
            ->implode(' ');

        $flags .= self::lineageFlags($certName, $forceRenewal);

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
     * --cert-name pins the lineage (see {@see lineageFor()}). --expand lets a
     * pinned lineage take on extra SANs (e.g. adding the www variant) instead of
     * erroring under --non-interactive. --keep-until-expiring makes a repeat
     * request a no-op rather than a needless re-issue against ACME rate limits;
     * a deliberate force-renew overrides it.
     */
    private static function lineageFlags(string $certName, bool $forceRenewal): string
    {
        if ($certName === '') {
            return $forceRenewal ? ' --force-renewal' : '';
        }

        return ' --cert-name '.escapeshellarg($certName).' --expand'
            .($forceRenewal ? ' --force-renewal' : ' --keep-until-expiring');
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
        $hostname = $domains[0];
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
