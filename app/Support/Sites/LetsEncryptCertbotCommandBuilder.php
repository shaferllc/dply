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
            return $certbot;
        }

        $webroot = escapeshellarg($site->effectiveDocumentRoot());
        $preflight = self::usesWebrootChallenge($site)
            ? self::acmePreflightScript($domains, $webroot)
            : '';

        return "set -e\nmkdir -p {$webroot}/.well-known/acme-challenge\n{$preflight}{$certbot}";
    }

    public static function usesWebrootChallenge(Site $site): bool
    {
        $site->loadMissing('server');
        $edgeProxy = $site->server?->edgeProxy();

        return is_string($edgeProxy)
            && in_array($edgeProxy, ['traefik', 'haproxy', 'envoy'], true);
    }

    public static function usesWebrootPath(Site $site): bool
    {
        if (self::usesWebrootChallenge($site)) {
            return true;
        }

        return in_array($site->webserver(), ['openlitespeed', 'traefik', 'caddy'], true);
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
            'openlitespeed', 'traefik', 'caddy' => sprintf(
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
