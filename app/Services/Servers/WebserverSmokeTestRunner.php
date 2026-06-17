<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use Carbon\CarbonImmutable;

/**
 * One-shot smoke test for every Site on a server: SSH to the box, curl
 * each site's primary hostname through localhost (resolving the host
 * header to 127.0.0.1 so the request hits the local webserver no matter
 * what public DNS says), and report HTTP status + response time + TLS
 * cert subject.
 *
 * Two probes per site:
 *   - HTTP  → http://127.0.0.1/   with `-H "Host: <name>"`
 *   - HTTPS → https://<name>/     with `--resolve <name>:443:127.0.0.1`
 *
 * Result rows are sorted by urgency (errors first → 5xx → 3xx → 2xx),
 * so the top of the table is what the operator should look at first.
 */
class WebserverSmokeTestRunner
{
    /**
     * Per-curl timeout in seconds. Generous enough for cold-start PHP
     * but small enough that 50 sites don't blow PHP's 30s execution
     * budget — the bash loop runs them serially.
     */
    private const PER_CURL_TIMEOUT = 4;

    /**
     * Cap on sites probed per run. Most servers stay well under this;
     * outliers get a "+N more" indicator in the UI rather than a 5-minute
     * SSH call.
     */
    private const MAX_SITES_PER_RUN = 50;

    /**
     * @return array{
     *     results: list<array{site_id: string, site_name: string, hostname: string, http_status: ?int, http_time_ms: ?int, http_error: ?string, https_status: ?int, https_time_ms: ?int, https_tls_ok: bool, https_error: ?string, urgency: string}>,
     *     total_sites: int,
     *     probed: int,
     *     scanned_at: CarbonImmutable,
     *     truncated: bool,
     * }
     */
    /** @return array<string, mixed> */
    public function run(Server $server, ?ConsoleEmitter $emitter = null): array
    {
        $emit = $emitter ?? new ConsoleEmitter(null);

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->where('status', '!=', 'deleted')
            ->with(['domains', 'domainAliases'])
            ->orderBy('name')
            ->get();

        $total = $sites->count();
        $candidates = $sites->take(self::MAX_SITES_PER_RUN);
        $truncated = $total > self::MAX_SITES_PER_RUN;

        $emit->step('smoke-test', sprintf('Probing %d site(s)%s', $candidates->count(), $truncated ? ' (truncated)' : ''));

        // Pre-compute the (site, hostname) tuples we'll test, picking the
        // best hostname per site: primary domain → first alias → fall back
        // to the site slug. Skip sites with no usable hostname.
        $probes = [];
        foreach ($candidates as $site) {
            $hostname = $this->primaryHostnameFor($site);
            if ($hostname === null) {
                continue;
            }
            $probes[] = ['site' => $site, 'hostname' => $hostname];
        }

        if ($probes === []) {
            $emit->warn('No sites with a resolvable hostname.');

            return [
                'results' => [],
                'total_sites' => $total,
                'probed' => 0,
                'scanned_at' => CarbonImmutable::now(),
                'truncated' => $truncated,
            ];
        }

        $script = $this->buildCurlScript(array_column($probes, 'hostname'));

        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($script, self::PER_CURL_TIMEOUT * count($probes) * 2 + 5);
        } catch (\Throwable $e) {
            $emit->error('SSH failed: '.$e->getMessage());

            return [
                'results' => [],
                'total_sites' => $total,
                'probed' => 0,
                'scanned_at' => CarbonImmutable::now(),
                'truncated' => $truncated,
            ];
        }

        $byHostname = $this->parseCurlOutput((string) $output);

        $results = [];
        foreach ($probes as $probe) {
            $hostname = $probe['hostname'];
            $row = $byHostname[$hostname] ?? ['http_status' => null, 'http_time_ms' => null, 'http_error' => 'no result', 'https_status' => null, 'https_time_ms' => null, 'https_tls_ok' => false, 'https_error' => 'no result'];
            $row['site_id'] = (string) $probe['site']->id;
            $row['site_name'] = (string) $probe['site']->name;
            $row['hostname'] = $hostname;
            $row['urgency'] = $this->urgencyFor($row);
            $results[] = $row;

            $emit->info(sprintf(
                '[smoke-test] %s — HTTP %s%s · HTTPS %s%s',
                $hostname,
                $row['http_status'] ?? '—',
                $row['http_error'] ? ' ('.$row['http_error'].')' : '',
                $row['https_status'] ?? '—',
                $row['https_error'] ? ' ('.$row['https_error'].')' : '',
            ));
        }

        usort($results, fn (array $a, array $b): int => $this->urgencyRank($a['urgency']) <=> $this->urgencyRank($b['urgency']));

        return [
            'results' => $results,
            'total_sites' => $total,
            'probed' => count($results),
            'scanned_at' => CarbonImmutable::now(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Build one bash heredoc that probes every hostname in sequence.
     * Curl emits a structured line per probe: `<host>|<scheme>|<status>|<time>|<tls_subject>|<errmsg>`.
     * Errors get a 0 status and the curl --write-out catches `errormsg`
     * via a trailing pipe.
     *
     * @param  array<string, mixed> $hostnames
     */
    private function buildCurlScript(array $hostnames): string
    {
        $timeout = self::PER_CURL_TIMEOUT;
        $hosts = implode("\n", array_map(fn (string $h): string => '  '.escapeshellarg($h), $hostnames));

        return <<<BASH
set +e
hosts=(
{$hosts}
)
for host in "\${hosts[@]}"; do
    # HTTP probe — loopback :80 with Host header.
    out=\$(curl -sS -o /dev/null \
        --connect-timeout 2 --max-time {$timeout} \
        --resolve "\${host}:80:127.0.0.1" \
        --write-out '%{http_code}|%{time_total}|%{errormsg}' \
        "http://\${host}/" 2>/dev/null)
    printf '%s|http|%s\n' "\${host}" "\${out}"

    # HTTPS probe — loopback :443 with Host header + --resolve so SNI matches.
    out=\$(curl -sSk -o /dev/null \
        --connect-timeout 2 --max-time {$timeout} \
        --resolve "\${host}:443:127.0.0.1" \
        --write-out '%{http_code}|%{time_total}|%{ssl_verify_result}|%{errormsg}' \
        "https://\${host}/" 2>/dev/null)
    printf '%s|https|%s\n' "\${host}" "\${out}"
done
BASH;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseCurlOutput(string $output): array
    {
        $out = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line);
            $host = $parts[0] ?? '';
            $scheme = $parts[1] ?? '';
            if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
                continue;
            }
            if (! isset($out[$host])) {
                $out[$host] = [
                    'http_status' => null, 'http_time_ms' => null, 'http_error' => null,
                    'https_status' => null, 'https_time_ms' => null, 'https_tls_ok' => false, 'https_error' => null,
                ];
            }

            if ($scheme === 'http') {
                $status = (int) ($parts[2] ?? 0);
                $time = (float) ($parts[3] ?? 0);
                $err = trim($parts[4] ?? '');
                $out[$host]['http_status'] = $status > 0 ? $status : null;
                $out[$host]['http_time_ms'] = $time > 0 ? (int) round($time * 1000) : null;
                $out[$host]['http_error'] = $status === 0 ? ($err !== '' ? $err : 'connection failed') : null;
            } else {
                $status = (int) ($parts[2] ?? 0);
                $time = (float) ($parts[3] ?? 0);
                $sslVerify = (int) ($parts[4] ?? -1);
                $err = trim($parts[5] ?? '');
                $out[$host]['https_status'] = $status > 0 ? $status : null;
                $out[$host]['https_time_ms'] = $time > 0 ? (int) round($time * 1000) : null;
                // ssl_verify_result == 0 means OpenSSL accepted the cert.
                // With -k (insecure) we still capture the verify result for display.
                $out[$host]['https_tls_ok'] = $sslVerify === 0 && $status > 0;
                $out[$host]['https_error'] = $status === 0 ? ($err !== '' ? $err : 'connection failed') : null;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $row
     */
    private function urgencyFor(array $row): string
    {
        $http = $row['http_status'] ?? null;
        $https = $row['https_status'] ?? null;
        $httpErr = $row['http_error'] ?? null;
        $httpsErr = $row['https_error'] ?? null;

        // Both schemes errored → connection-level failure.
        if ($http === null && $https === null && $httpErr !== null && $httpsErr !== null) {
            return 'down';
        }
        // 5xx on either scheme → server-side problem.
        if (($http !== null && $http >= 500) || ($https !== null && $https >= 500)) {
            return 'error';
        }
        // 4xx on either scheme → routing problem (often a missing site / wrong vhost).
        if (($http !== null && $http >= 400) || ($https !== null && $https >= 400)) {
            return 'warn';
        }
        // 3xx on HTTP that doesn't have HTTPS responding → likely TLS not configured.
        if ($http !== null && $http >= 300 && $http < 400 && $https === null) {
            return 'warn';
        }
        // Anything else (2xx/3xx with both working) is healthy.
        if ($http !== null && $http < 400) {
            return 'ok';
        }
        if ($https !== null && $https < 400) {
            return 'ok';
        }

        return 'unknown';
    }

    private function urgencyRank(string $urgency): int
    {
        return match ($urgency) {
            'down' => 0,
            'error' => 1,
            'warn' => 2,
            'unknown' => 3,
            'ok' => 4,
            default => 5,
        };
    }

    private function primaryHostnameFor(Site $site): ?string
    {
        // dply's Site model exposes a `webserverHostnames()` array that the
        // per-site config builders already use. First entry is the primary.
        if (method_exists($site, 'webserverHostnames')) {
            try {
                $names = $site->webserverHostnames();
                if (($names) && $names !== []) {
                    $first = (string) reset($names);
                    if ($first !== '') {
                        return $first;
                    }
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        // Fallback: primary domain → first alias.
        $primary = $site->domains->first();
        if ($primary !== null) {
            return (string) $primary->name;
        }
        $alias = $site->domainAliases->first();
        if ($alias !== null) {
            return (string) $alias->name;
        }

        return null;
    }
}
