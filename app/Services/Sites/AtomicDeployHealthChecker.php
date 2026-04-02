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

        for ($i = 1; $i <= $attempts; $i++) {
            $raw = trim($ssh->exec($curl, 45));
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

        throw new \RuntimeException(
            __('Deploy health check failed after :n attempts (expected HTTP :expect).', ['n' => $attempts, 'expect' => $expectStr])
        );
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
