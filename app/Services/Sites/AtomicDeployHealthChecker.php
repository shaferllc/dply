<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

/**
 * After an atomic cutover, optionally verifies HTTP from the app server to 127.0.0.1 with the site Host header.
 */
final class AtomicDeployHealthChecker
{
    /**
     * Runs the configured health check and returns log lines to append, or an empty string if disabled.
     *
     * @throws \RuntimeException when enabled and checks fail
     */
    public function verify(Site $site, SshConnection $ssh): string
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        if (! ($meta['deploy_health_enabled'] ?? false)) {
            return '';
        }

        $domain = $site->primaryDomain();
        if ($domain === null || trim($domain->hostname) === '') {
            throw new \RuntimeException(
                __('Deploy health check is enabled but this site has no primary domain. Add a primary domain or disable the check.')
            );
        }

        $hostHeader = strtolower(trim($domain->hostname));
        $path = $this->normalizePath((string) ($meta['deploy_health_path'] ?? '/health'));
        $expect = (int) ($meta['deploy_health_expect_status'] ?? 200);
        $attempts = max(1, min(30, (int) ($meta['deploy_health_attempts'] ?? 5)));
        $delayMs = max(0, min(10000, (int) ($meta['deploy_health_delay_ms'] ?? 500)));

        $scheme = strtolower((string) ($meta['deploy_health_scheme'] ?? 'http'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'http';
        }
        $targetHost = trim((string) ($meta['deploy_health_host'] ?? '127.0.0.1'));
        if ($targetHost === '') {
            $targetHost = '127.0.0.1';
        }
        $portRaw = $meta['deploy_health_port'] ?? null;
        $port = is_numeric($portRaw) ? max(1, min(65535, (int) $portRaw)) : null;

        $url = $scheme.'://'.$targetHost;
        if ($port !== null) {
            $url .= ':'.$port;
        }
        $url .= $path;

        $curl = 'curl -sS --max-time 20 -o /dev/null -w \'%{http_code}\' -H '
            .escapeshellarg('Host: '.$hostHeader).' '
            .escapeshellarg($url).' 2>&1';

        $log = "\n--- deploy health check ---\n";
        $log .= 'GET '.$url.' (Host: '.$hostHeader.")\n";
        $log .= 'Expect HTTP '.$expect.', up to '.$attempts." attempt(s)\n";

        $expectStr = (string) $expect;
        $lastRaw = '';

        for ($i = 1; $i <= $attempts; $i++) {
            $raw = trim($ssh->exec($curl, 45));
            $lastRaw = $raw;
            $code = preg_match('/^\d{3}$/', $raw) ? $raw : '';

            if ($code === $expectStr) {
                $log .= 'attempt '.$i.': HTTP '.$code." (ok)\n";

                return $log;
            }

            $log .= 'attempt '.$i.': got '.($raw !== '' ? $raw : '(empty)')." (expected {$expectStr})\n";

            if ($i < $attempts && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        // Never make the operator guess: report the ACTUAL status received, and
        // pull the real cause off the server — the response body (a Laravel 500
        // carries the exception; a 502 means PHP-FPM is down), the PHP-FPM unit
        // state, and the tail of the nginx + PHP-FPM error logs.
        $lastCode = preg_match('/^\d{3}$/', $lastRaw) ? $lastRaw : '';
        $gotStr = $lastCode !== '' ? 'HTTP '.$lastCode : ($lastRaw !== '' ? $lastRaw : '(no response)');

        throw new \RuntimeException(
            __('Deploy health check failed after :n attempts — last response was :got (expected HTTP :expect).', [
                'n' => $attempts,
                'got' => $gotStr,
                'expect' => $expectStr,
            ])."\n\n".$this->diagnose($ssh, $site, $url, $hostHeader)
        );
    }

    /**
     * Gather the real cause from the server so the failure is self-explanatory.
     * For a 5xx the source of truth is: the nginx error log (the exact upstream
     * failure — "connect() failed", "no input file", bad socket), the PHP-FPM
     * unit state + its journal, `php -v` / `php-fpm -t` (extension/config fatals),
     * and the site's own laravel.log (an app exception). Privileged probes use
     * `sudo -n` and fall back; every probe is guarded so one missing source never
     * hides the rest. The response body itself is dropped — on a 502 it's only
     * dply's branded fallback page, which says nothing about the cause.
     */
    private function diagnose(SshConnection $ssh, Site $site, string $url, string $hostHeader): string
    {
        $repo = $site->conventionalRepositoryPath();
        // Candidate Laravel log locations across release layouts.
        $logCandidates = implode(' ', array_map(fn ($p) => $this->sh($repo.$p), [
            '/current/storage/logs/laravel.log',
            '/storage/logs/laravel.log',
            '/shared/storage/logs/laravel.log',
        ]));

        // The nginx error-log filter is an ERE alternation, so the host (which is
        // user-controlled) must be regex-escaped before it joins the pattern —
        // otherwise a metachar in the hostname would alter the match. The whole
        // pattern then ships as one escapeshellarg-quoted argument.
        $logFilter = $this->ereLiteral($hostHeader)
            .'|fastcgi|upstream|connect\\(\\)|No such file|Primary script';

        $script = <<<BASH
echo "── response headers ──"
curl -sS --max-time 20 -o /dev/null -D - -H {$this->sh('Host: '.$hostHeader)} {$this->sh($url)} 2>&1 | head -n 12

echo
echo "── php cli (extension/load fatals) ──"
php -v 2>&1 | head -n 6
printf 'redis loaded: %s\n' "\$(php -m 2>/dev/null | grep -qi '^redis\$' && echo yes || echo NO)"

echo
echo "── php-fpm ──"
units=\$(systemctl list-units --type=service --all --no-legend 'php*-fpm*' 2>/dev/null | awk '{print \$1}')
[ -z "\$units" ] && units=\$(ls -1 /etc/php/ 2>/dev/null | sed 's/.*/php&-fpm.service/')
for u in \$units; do
  printf '%s: active=%s\n' "\$u" "\$(systemctl is-active "\$u" 2>/dev/null || echo unknown)"
  ver=\$(echo "\$u" | grep -oE '[0-9]+\.[0-9]+')
  [ -n "\$ver" ] && { command -v "php-fpm\${ver}" >/dev/null 2>&1 && (sudo -n "php-fpm\${ver}" -t 2>&1 || "php-fpm\${ver}" -t 2>&1) | tail -n 4; }
  (sudo -n journalctl -u "\$u" -n 15 --no-pager 2>/dev/null | tail -n 15) || echo "  (journal needs privileges)"
done

echo "── nginx config + site vhost ──"
(sudo -n nginx -t 2>&1 || nginx -t 2>&1 || echo "(nginx -t needs privileges)") | tail -n 3
printf 'enabled vhost for %s: ' {$this->sh($hostHeader)}
match=\$( (sudo -n grep -rls {$this->sh($hostHeader)} /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null || grep -rls {$this->sh($hostHeader)} /etc/nginx/sites-enabled 2>/dev/null) | paste -sd' ' - )
[ -n "\$match" ] && echo "\$match" || echo "NONE — request falls through to the default server (the 502 page)"

echo "── release / docroot ──"
printf 'current -> %s\n' "\$(readlink -f {$this->sh($repo)}/current 2>/dev/null || echo MISSING)"
ls -la {$this->sh($repo)}/current/public/index.php 2>/dev/null || echo "  no current/public/index.php — release not activated"

echo "── nginx error log (recent) ──"
(sudo -n tail -n 40 /var/log/nginx/error.log 2>/dev/null || tail -n 40 /var/log/nginx/error.log 2>/dev/null || echo "(no access to /var/log/nginx/error.log)") | grep -E {$this->sh($logFilter)} | tail -n 10

echo "── site laravel.log (tail) ──"
for p in {$logCandidates}; do
  if [ -f "\$p" ]; then echo "\$p:"; tail -n 25 "\$p"; break; fi
done
BASH;

        $out = trim($ssh->exec($script, 90));

        return $out !== ''
            ? "Server diagnostics:\n".$out
            : 'Server diagnostics: (could not gather — SSH probe returned nothing)';
    }

    /** Shell-quote for embedding in the diagnostic heredoc. */
    private function sh(string $value): string
    {
        return escapeshellarg($value);
    }

    /** Escape POSIX ERE metacharacters so $value matches literally inside a grep -E pattern. */
    private function ereLiteral(string $value): string
    {
        return preg_replace('/[.^$*+?()\[\]{}|\\\\]/', '\\\\$0', $value);
    }

    public function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            return '/'.$path;
        }

        return $path;
    }
}
