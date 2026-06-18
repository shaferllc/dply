<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\CaddyAdminUrl;
use App\Support\Servers\CaddyPhpFpmUpstreamAddress;
use Carbon\CarbonImmutable;

/**
 * Probes Caddy's live state via its admin API (default localhost:2019).
 * When `admin off` is set in the global Caddyfile block the probe is skipped.
 *
 * Admin endpoints:
 *   - GET /config/                       — full active config (JSON)
 *   - GET /reverse_proxy/upstreams       — reverse_proxy backends + health
 *   - GET /pki/ca/local                  — internal CA info
 *   - GET /pki/ca/                       — CA inventory
 *   - GET /metrics                       — Prometheus exposition
 *   - GET /id/                           — instance identifier
 *
 * Parsed into four sub-tabs:
 *   - routes    — every route across http.servers.*
 *   - upstreams — reverse_proxy + php_fastcgi unix sockets w/ live health
 *   - certs     — TLS automation policies, local CA, on-disk issued certs
 *   - admin     — version, admin listen, metrics, instance id
 */
class CaddyLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'caddy';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        $ssh = new SshConnection($server);
        $output = $ssh->exec($this->privilegedCommand($server, $this->buildProbeScript()), 45);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = sprintf('Caddy admin API SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);
        $adminListen = trim($sections['admin_listen'] ?? '');
        $adminOff = trim($sections['admin_off'] ?? '') === '1';
        $caddyVersion = trim($sections['version'] ?? '');
        $config = $this->decodeJson($sections['config'] ?? '');
        $upstreams = $this->decodeJson($sections['upstreams'] ?? '');
        $caInfo = $this->decodeJson($sections['ca'] ?? '');
        $caList = $this->decodeJson($sections['ca_list'] ?? '');
        $instanceId = trim($sections['id'] ?? '');
        $metricsBlob = trim($sections['metrics'] ?? '');
        $phpSocketsRaw = trim($sections['php_sockets'] ?? '');
        $issuedCertsRaw = trim($sections['issued_certs'] ?? '');

        $adminUrl = $adminOff
            ? null
            : CaddyAdminUrl::fromListenDirective($adminListen !== '' ? $adminListen : null)
                ?? CaddyAdminUrl::fromLoadedConfig($config);

        if ($adminOff) {
            $errors[] = 'Caddy admin API is disabled (`admin off`). Live-state probe skipped.';
        } elseif ($config === [] && empty($errors)) {
            $errors[] = 'Caddy admin API unreachable'
                .($adminUrl !== null ? ' at '.$adminUrl : ' on 127.0.0.1:2019')
                .'. Set `admin off` deliberately? Then probe is disabled.';
        }

        $upstreamUnits = $this->buildUpstreamUnits($upstreams);
        $upstreamUnits = $this->mergePhpSocketUnits($upstreamUnits, $phpSocketsRaw, $config);

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'routes' => $this->buildRouteUnits($config),
                'upstreams' => $upstreamUnits,
                'certs' => $this->buildCertUnits($config, $caInfo, $caList, $issuedCertsRaw),
                'admin' => $this->buildAdminUnits(
                    $config,
                    $caddyVersion,
                    $adminUrl,
                    $instanceId,
                    $metricsBlob,
                    $sections,
                ),
            ],
            engineSpecific: array_filter([
                'admin_url' => $adminUrl,
                'config_bytes' => strlen(trim($sections['config'] ?? '')),
                'errors' => $errors !== [] ? $errors : null,
            ], static fn ($v) => $v !== null && $v !== ''),
        );
    }

    private function buildProbeScript(): string
    {
        return <<<'BASH'
set +e
CADDYFILE="/etc/caddy/Caddyfile"
ADMIN_LISTEN=""
ADMIN_OFF=0
if [ -r "$CADDYFILE" ]; then
  admin_line=$(grep -E '^\s*admin\s+' "$CADDYFILE" 2>/dev/null | head -1 || true)
  if echo "$admin_line" | grep -qiE '\soff\s*$'; then ADMIN_OFF=1; fi
  ADMIN_LISTEN=$(echo "$admin_line" | awk '{print $2}')
fi
[ -z "$ADMIN_LISTEN" ] && ADMIN_LISTEN="localhost:2019"
echo '###dply-section:admin_off###'
echo "$ADMIN_OFF"
echo '###dply-section:end###'
echo '###dply-section:admin_listen###'
echo "$ADMIN_LISTEN"
echo '###dply-section:end###'
if [ "$ADMIN_OFF" = "1" ]; then exit 0; fi
host="${ADMIN_LISTEN%%:*}"
port="${ADMIN_LISTEN##*:}"
[ "$host" = "localhost" ] && host="127.0.0.1"
[ "$host" = "::1" ] && host="127.0.0.1"
[ "$port" = "$ADMIN_LISTEN" ] && port="2019"
URL="http://${host}:${port}"
echo '###dply-section:version###'
caddy version 2>/dev/null | head -n 1
echo '###dply-section:end###'
echo '###dply-section:config###'
curl -fsS --max-time 8 "$URL/config/" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:upstreams###'
curl -fsS --max-time 8 "$URL/reverse_proxy/upstreams" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:ca###'
curl -fsS --max-time 8 "$URL/pki/ca/local" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:ca_list###'
curl -fsS --max-time 8 "$URL/pki/ca/" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:id###'
curl -fsS --max-time 5 "$URL/id/" 2>/dev/null
echo
echo '###dply-section:end###'
echo '###dply-section:metrics###'
curl -fsS --max-time 8 "$URL/metrics" 2>/dev/null | head -n 400
echo
echo '###dply-section:end###'
echo '###dply-section:php_sockets###'
for sock_path in $(grep -rohE '/run/php/php[0-9.]+-fpm\.sock' /etc/caddy 2>/dev/null | sort -u); do
  healthy=0
  [ -S "$sock_path" ] && healthy=1
  ver=$(echo "$sock_path" | sed -n 's#.*/php\([0-9.]*\)-fpm\.sock#\1#p')
  active=unknown
  if [ -n "$ver" ] && command -v systemctl >/dev/null 2>&1; then
    active=$(systemctl is-active "php${ver}-fpm" 2>/dev/null || echo unknown)
  fi
  echo "${sock_path}|${healthy}|${active}"
done
echo '###dply-section:end###'
echo '###dply-section:issued_certs###'
find /var/lib/caddy/.local/share/caddy/certificates -name '*.crt' -type f 2>/dev/null | head -n 40
echo '###dply-section:end###'
BASH;
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = [
            'admin_off', 'admin_listen', 'version', 'config', 'upstreams', 'ca',
            'ca_list', 'id', 'metrics', 'php_sockets', 'issued_certs',
        ];
        $end = '###dply-section:end###';
        $out = [];
        foreach ($heads as $name) {
            $head = '###dply-section:'.$name.'###';
            $start = strpos($output, $head);
            if ($start === false) {
                continue;
            }
            $start += strlen($head);
            $stop = strpos($output, $end, $start);
            $out[$name] = $stop === false ? substr($output, $start) : substr($output, $start, $stop - $start);
        }

        return $out;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeJson(string $blob): array
    {
        $blob = trim($blob);
        if ($blob === '') {
            return [];
        }
        $decoded = json_decode($blob, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function buildRouteUnits(array $config): array
    {
        $servers = $config['apps']['http']['servers'] ?? [];
        if (! is_array($servers)) {
            return [];
        }
        $rows = [];
        foreach ($servers as $serverName => $serverCfg) {
            if (! is_array($serverCfg)) {
                continue;
            }
            $listen = is_array($serverCfg['listen'] ?? null) ? array_map('strval', $serverCfg['listen']) : [];
            foreach (($serverCfg['routes'] ?? []) as $idx => $route) {
                if (! is_array($route)) {
                    continue;
                }
                $hostMatch = $this->extractHostMatcher($route);
                $handlers = $this->summariseHandlers($route);
                $rows[] = [
                    'server' => (string) $serverName,
                    'listen' => $listen,
                    'host' => $hostMatch,
                    'handlers' => $handlers,
                    'terminal' => (bool) ($route['terminal'] ?? false),
                    'index' => (int) $idx,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed> $route
     * @return list<string>
     */
    private function extractHostMatcher(array $route): array
    {
        $matches = $route['match'] ?? [];
        if (! is_array($matches)) {
            return [];
        }
        foreach ($matches as $m) {
            if (is_array($m) && isset($m['host']) && is_array($m['host'])) {
                return array_values(array_map('strval', $m['host']));
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed> $route
     * @return list<string>
     */
    private function summariseHandlers(array $route): array
    {
        $out = [];
        foreach (($route['handle'] ?? []) as $h) {
            if (! is_array($h)) {
                continue;
            }
            $kind = (string) ($h['handler'] ?? '');
            if ($kind === '') {
                continue;
            }
            if ($kind === 'subroute' && is_array($h['routes'] ?? null)) {
                foreach ($h['routes'] as $sub) {
                    if (is_array($sub)) {
                        foreach ($this->summariseHandlers($sub) as $k) {
                            $out[] = $k;
                        }
                    }
                }

                continue;
            }
            $out[] = $kind;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, array<string, mixed>>  $upstreams
     * @return list<array<string, mixed>>
     */
    private function buildUpstreamUnits(array $upstreams): array
    {
        if ($upstreams === [] || ! isset($upstreams[0])) {
            return [];
        }
        $rows = [];
        foreach ($upstreams as $u) {
            if (! is_array($u)) {
                continue;
            }
            $rows[] = [
                'address' => (string) ($u['address'] ?? '?'),
                'healthy' => (bool) ($u['healthy'] ?? false),
                'num_requests' => (int) ($u['num_requests'] ?? 0),
                'fails' => (int) ($u['fails'] ?? 0),
                'kind' => 'reverse_proxy',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function mergePhpSocketUnits(array $existing, string $phpSocketsRaw, array $config): array
    {
        $byAddress = [];
        foreach ($existing as $row) {
            $canonical = CaddyPhpFpmUpstreamAddress::normalizeUpstreamAddress((string) ($row['address'] ?? ''));
            if ($canonical === '') {
                continue;
            }
            $row['address'] = $canonical;
            $byAddress[$canonical] = $this->mergeUpstreamRow($byAddress[$canonical] ?? null, $row);
        }

        foreach ($this->parsePhpSocketProbeLines($phpSocketsRaw) as $row) {
            $canonical = CaddyPhpFpmUpstreamAddress::normalizeUpstreamAddress((string) ($row['address'] ?? ''));
            if ($canonical === '') {
                continue;
            }
            $row['address'] = $canonical;
            $byAddress[$canonical] = $this->mergeUpstreamRow($byAddress[$canonical] ?? null, $row);
        }

        foreach ($this->extractPhpFastcgiFromConfig($config) as $addr) {
            $canonical = CaddyPhpFpmUpstreamAddress::normalizeUpstreamAddress($addr);
            if ($canonical === '') {
                continue;
            }
            if (! isset($byAddress[$canonical])) {
                $byAddress[$canonical] = [
                    'address' => $canonical,
                    'healthy' => false,
                    'num_requests' => 0,
                    'fails' => 0,
                    'kind' => 'php_fastcgi',
                ];
            }
        }

        return array_values($byAddress);
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeUpstreamRow(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        if (empty($existing['healthy']) && ! empty($incoming['healthy'])) {
            $existing['healthy'] = true;
        }

        $existing['num_requests'] = max((int) ($existing['num_requests'] ?? 0), (int) ($incoming['num_requests'] ?? 0));
        $existing['fails'] = max((int) ($existing['fails'] ?? 0), (int) ($incoming['fails'] ?? 0));

        if (($incoming['kind'] ?? '') === 'php_fastcgi') {
            $existing['kind'] = 'php_fastcgi';
        }

        if (isset($incoming['fpm_active'])) {
            $existing['fpm_active'] = $incoming['fpm_active'];
        }

        return $existing;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parsePhpSocketProbeLines(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '|')) {
                continue;
            }
            [$path, $healthy, $active] = array_pad(explode('|', $line, 3), 3, '');
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $version = CaddyPhpFpmUpstreamAddress::phpVersionFromUpstream($path);
            $display = $version !== null
                ? CaddyPhpFpmUpstreamAddress::normalizeUpstreamAddress($path)
                : $path;
            $rows[] = [
                'address' => $display,
                'healthy' => trim($healthy) === '1',
                'num_requests' => 0,
                'fails' => trim($healthy) === '1' ? 0 : 1,
                'kind' => 'php_fastcgi',
                'fpm_active' => trim($active),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed> $config
     * @return list<string>
     */
    private function extractPhpFastcgiFromConfig(array $config): array
    {
        $found = [];
        $this->walkConfigForPhpUpstreams($config, $found);

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed> $node
     * @param  array<string, mixed> $found
     */
    private function walkConfigForPhpUpstreams(array $node, array &$found): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'dial' && is_string($value) && str_contains($value, 'php') && str_contains($value, 'fpm')) {
                $found[] = $value;
            }
            if ($key === 'upstreams' && is_array($value)) {
                foreach ($value as $upstream) {
                    if (is_array($upstream) && isset($upstream['dial']) && is_string($upstream['dial'])) {
                        $dial = $upstream['dial'];
                        if (str_contains($dial, 'php') && str_contains($dial, 'fpm')) {
                            $found[] = $dial;
                        }
                    }
                }
            }
            if (is_array($value)) {
                $this->walkConfigForPhpUpstreams($value, $found);
            }
        }
    }

    /**
     * @param  array<string, mixed> $config
     * @param  array<string, mixed> $caInfo
     * @param  array<string, mixed> $caList
     * @return list<array<string, mixed>>
     */
    private function buildCertUnits(array $config, array $caInfo, array $caList, string $issuedCertsRaw): array
    {
        $rows = [];
        $policies = $config['apps']['tls']['automation']['policies'] ?? [];
        if (is_array($policies)) {
            foreach ($policies as $idx => $p) {
                if (! is_array($p)) {
                    continue;
                }
                $subjects = is_array($p['subjects'] ?? null) ? array_map('strval', $p['subjects']) : [];
                $issuer = $this->extractIssuerName($p);
                $rows[] = [
                    'kind' => 'policy',
                    'name' => 'policy#'.$idx,
                    'subjects' => $subjects,
                    'issuer' => $issuer,
                    'on_demand' => (bool) ($p['on_demand'] ?? false),
                    'status' => 'configured',
                ];
            }
        }

        if (($caInfo) && ($caInfo['id'] ?? null) !== null) {
            $rows[] = [
                'kind' => 'local_ca',
                'name' => (string) ($caInfo['name'] ?? 'local'),
                'subjects' => array_filter([(string) ($caInfo['root_common_name'] ?? '')]),
                'issuer' => (string) ($caInfo['intermediate_common_name'] ?? ''),
                'on_demand' => false,
                'status' => 'active',
            ];
        }

        if ($caList !== []) {
            foreach ($caList as $id => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $rows[] = [
                    'kind' => 'ca',
                    'name' => is_string($id) ? $id : (string) ($entry['id'] ?? 'ca'),
                    'subjects' => [],
                    'issuer' => (string) ($entry['name'] ?? ''),
                    'on_demand' => false,
                    'status' => 'registered',
                ];
            }
        }

        foreach (preg_split('/\R/', trim($issuedCertsRaw)) ?: [] as $path) {
            $path = trim($path);
            if ($path === '' || ! str_ends_with($path, '.crt')) {
                continue;
            }
            $rows[] = [
                'kind' => 'issued',
                'name' => basename($path, '.crt'),
                'subjects' => [basename(dirname($path))],
                'issuer' => '',
                'on_demand' => false,
                'status' => 'on_disk',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed> $policy
     */
    private function extractIssuerName(array $policy): string
    {
        $issuers = $policy['issuers'] ?? [];
        if (! is_array($issuers)) {
            return '';
        }
        foreach ($issuers as $i) {
            if (is_array($i) && isset($i['module'])) {
                return (string) $i['module'];
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed> $config
     * @param  array<string, mixed> $sections
     * @return list<array<string, string>>
     */
    private function buildAdminUnits(
        array $config,
        string $caddyVersion,
        ?string $adminUrl,
        string $instanceId,
        string $metricsBlob,
        array $sections,
    ): array {
        $rows = [];
        $rows[] = ['key' => 'version', 'value' => $caddyVersion !== '' ? $caddyVersion : '?'];
        $rows[] = ['key' => 'admin', 'value' => $adminUrl ?? 'unreachable'];
        if ($instanceId !== '') {
            $rows[] = ['key' => 'instance_id', 'value' => $instanceId];
        }

        $listens = [];
        $servers = $config['apps']['http']['servers'] ?? [];
        if (is_array($servers)) {
            foreach ($servers as $sCfg) {
                if (is_array($sCfg) && is_array($sCfg['listen'] ?? null)) {
                    foreach ($sCfg['listen'] as $l) {
                        $listens[] = (string) $l;
                    }
                }
            }
        }
        $rows[] = ['key' => 'listeners', 'value' => $listens !== [] ? implode(', ', array_unique($listens)) : '—'];

        $autoHttps = $config['apps']['http']['http_port'] ?? null;
        if ($autoHttps !== null) {
            $rows[] = ['key' => 'http_port', 'value' => (string) $autoHttps];
        }
        if (isset($config['apps']['http']['https_port'])) {
            $rows[] = ['key' => 'https_port', 'value' => (string) $config['apps']['http']['https_port']];
        }

        $configBytes = strlen(trim($sections['config'] ?? ''));
        $rows[] = ['key' => 'config_json', 'value' => number_format($configBytes).' B'];

        foreach ($this->parsePrometheusMetrics($metricsBlob) as $key => $value) {
            $rows[] = ['key' => 'metric:'.$key, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function parsePrometheusMetrics(string $blob): array
    {
        if ($blob === '') {
            return [];
        }

        $want = [
            'caddy_admin_http_requests_total' => 'admin_requests',
            'caddy_http_requests_total' => 'http_requests',
            'caddy_http_request_errors_total' => 'http_errors',
            'caddy_http_requests_in_flight' => 'in_flight',
        ];
        $out = [];

        foreach (preg_split('/\R/', $blob) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            foreach ($want as $metric => $label) {
                if (! str_starts_with($line, $metric)) {
                    continue;
                }
                if (preg_match('/\s(\S+)\s*$/', $line, $m) === 1) {
                    $out[$label] = $m[1];
                }
                break;
            }
        }

        return $out;
    }
}
