<?php

declare(strict_types=1);

namespace App\Services\Servers\LiveState;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\EnvoyAdminScript;
use Carbon\CarbonImmutable;

/**
 * Probes Envoy's live state via the admin HTTP interface on
 * 127.0.0.1:9901 (enabled in dply's envoy.yaml template):
 *
 *   - GET /listeners?format=json  → listeners tab
 *   - GET /clusters?format=json   → clusters tab
 *   - GET /server_info            → runtime tab
 */
class EnvoyLiveStateProbe extends AbstractEngineLiveStateProbe
{
    use PrivilegedRemoteFileWrites;

    public function engineKey(): string
    {
        return 'envoy';
    }

    protected function runFreshProbe(Server $server): EngineLiveState
    {
        if ($standby = $this->inactiveEdgeProxyLiveState($server)) {
            return $standby;
        }

        $ssh = new SshConnection($server);
        $script = $this->buildProbeScript();
        $output = $ssh->exec($this->privilegedCommand($server, $script), 30);
        $exit = $ssh->lastExecExitCode();

        $errors = [];
        if ($exit !== null && $exit !== 0) {
            $errors[] = $this->extractProbeError((string) $output)
                ?? sprintf('Envoy admin API SSH exited %d', $exit);
        }

        $sections = $this->splitSections((string) $output);

        return new EngineLiveState(
            engine: $this->engineKey(),
            capturedAt: CarbonImmutable::now(),
            isFresh: true,
            units: [
                'listeners' => $this->buildListenerUnits($sections['listeners'] ?? ''),
                'clusters' => $this->buildClusterUnits($sections['clusters'] ?? ''),
                'runtime' => $this->buildRuntimeUnits($sections['runtime'] ?? ''),
                'virtualhosts' => $this->buildVirtualHostUnits($sections['config'] ?? ''),
                'stats' => $this->buildStatsUnits($sections['stats'] ?? '', $sections['clusters'] ?? ''),
            ],
            engineSpecific: $errors === [] ? [] : ['errors' => $errors],
        );
    }

    private function buildProbeScript(): string
    {
        return EnvoyAdminScript::liveStateProbeScript();
    }

    private function extractProbeError(string $output): ?string
    {
        $messages = [];
        foreach (preg_split('/\r\n|\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[dply]')) {
                $messages[] = trim(substr($line, 6));
            }
        }

        return $messages === [] ? null : implode(' ', $messages);
    }

    /**
     * @return array<string, string>
     */
    private function splitSections(string $output): array
    {
        $heads = ['listeners', 'clusters', 'runtime', 'config', 'stats'];
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
     * @return list<array<string, mixed>>
     */
    private function buildListenerUnits(string $blob): array
    {
        $data = $this->decodeJson($blob);
        $statuses = $data['listener_statuses'] ?? $data;
        if (! is_array($statuses)) {
            return [];
        }

        $rows = [];
        foreach ($statuses as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->scalarString($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            try {
                $address = $this->formatSocketAddress($entry['local_address'] ?? $entry['address'] ?? null);
            } catch (\Throwable) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'address' => $address,
                'secure' => str_contains(strtolower($address), '443') || str_contains(strtolower($name), 'tls'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildClusterUnits(string $blob): array
    {
        $data = $this->decodeJson($blob);
        $statuses = $data['cluster_statuses'] ?? $data;
        if (! is_array($statuses)) {
            return [];
        }

        $rows = [];
        foreach ($statuses as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->scalarString($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $hosts = [];
            foreach ($entry['host_statuses'] ?? [] as $host) {
                if (! is_array($host)) {
                    continue;
                }
                try {
                    $addr = $this->formatSocketAddress($host['address'] ?? null);
                } catch (\Throwable) {
                    $addr = '';
                }
                $health = $host['health_status'] ?? null;
                $hosts[] = [
                    'name' => $addr !== '' ? $addr : 'host',
                    'status' => $this->formatHostHealthStatus($health),
                ];
            }
            $rows[] = [
                'name' => $name,
                'status' => $this->rollupHostStatus($hosts),
                'servers' => $hosts,
                'sessions_current' => count(array_filter($hosts, fn (array $h): bool => ($h['status'] ?? '') === 'UP')),
                'sessions_total' => count($hosts),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVirtualHostUnits(string $blob): array
    {
        $data = $this->decodeJson($blob);
        $configs = $data['configs'] ?? [];
        if (! is_array($configs)) {
            return [];
        }

        $rows = [];
        foreach ($configs as $config) {
            if (! is_array($config)) {
                continue;
            }
            foreach ($config['dynamic_route_configs'] ?? [] as $dynamic) {
                if (! is_array($dynamic)) {
                    continue;
                }
                $routeConfig = data_get($dynamic, 'route_config');
                if (! is_array($routeConfig)) {
                    continue;
                }
                foreach ($routeConfig['virtual_hosts'] ?? [] as $vh) {
                    if (! is_array($vh)) {
                        continue;
                    }
                    $name = $this->scalarString($vh['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $domains = $this->stringList($vh['domains'] ?? []);
                    $cluster = '';
                    foreach ($vh['routes'] ?? [] as $route) {
                        if (! is_array($route)) {
                            continue;
                        }
                        $cluster = $this->scalarString(data_get($route, 'route.cluster', ''));
                        if ($cluster !== '') {
                            break;
                        }
                    }
                    $siteId = null;
                    if (preg_match('/^vhost_dply-([0-9a-z]+)-/i', $name, $m) === 1) {
                        $siteId = strtolower($m[1]);
                    }
                    $rows[] = [
                        'name' => $name,
                        'domains' => $domains,
                        'cluster' => $cluster,
                        'site_id' => $siteId,
                        'dply_managed' => $siteId !== null || $name === 'dply_unmatched',
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildStatsUnits(string $prometheusBlob, string $clustersBlob): array
    {
        $metrics = $this->parsePrometheusClusterMetrics($prometheusBlob);
        $clusterNames = [];
        foreach ($this->buildClusterUnits($clustersBlob) as $cluster) {
            $name = (string) ($cluster['name'] ?? '');
            if ($name !== '') {
                $clusterNames[$name] = true;
            }
        }
        foreach (array_keys($metrics) as $name) {
            $clusterNames[$name] = true;
        }

        $rows = [];
        foreach (array_keys($clusterNames) as $clusterName) {
            $row = $metrics[$clusterName] ?? ['requests' => 0, 'errors_5xx' => 0, 'connections_active' => 0];
            $rows[] = [
                'name' => $clusterName,
                'requests' => (int) ($row['requests']),
                'errors_5xx' => (int) ($row['errors_5xx']),
                'connections_active' => (int) ($row['connections_active']),
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($b['requests']) <=> ($a['requests']));

        return $rows;
    }

    /**
     * @return array<string, array{requests: int, errors_5xx: int, connections_active: int}>
     */
    private function parsePrometheusClusterMetrics(string $blob): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n/', trim($blob)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([a-zA-Z0-9_:]+)(\{([^}]*)\})?\s+([0-9.eE+-]+)$/', $line, $m) !== 1) {
                continue;
            }
            $metric = $m[1];
            $labels = $m[3] ?? '';
            $value = str_contains($m[4], '.') ? (int) round((float) $m[4]) : (int) $m[4];
            $cluster = '';
            if ($labels !== '' && preg_match('/cluster_name="([^"]+)"/', $labels, $cm) === 1) {
                $cluster = $cm[1];
            }
            if ($cluster === '') {
                continue;
            }
            $out[$cluster] ??= ['requests' => 0, 'errors_5xx' => 0, 'connections_active' => 0];
            if (str_contains($metric, 'upstream_rq_total') || str_contains($metric, 'upstream_rq_completed')) {
                $out[$cluster]['requests'] = max($out[$cluster]['requests'], $value);
            }
            if (str_contains($metric, 'upstream_rq_5xx') || (str_contains($metric, 'upstream_rq_xx') && str_contains($labels, 'response_code_class="5"'))) {
                $out[$cluster]['errors_5xx'] += $value;
            }
            if (str_contains($metric, 'upstream_cx_active')) {
                $out[$cluster]['connections_active'] = max($out[$cluster]['connections_active'], $value);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v): string => $this->scalarString($v),
            $values,
        ), fn (string $s): bool => $s !== '' && $s !== '*'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRuntimeUnits(string $blob): array
    {
        $data = $this->decodeJson($blob);
        if ($data === []) {
            return [];
        }

        return [[
            'version' => (string) ($data['version'] ?? $data['envoy_version'] ?? '?'),
            'uptime_sec' => (int) ($data['uptime_current'] ?? $data['uptime_all_epochs'] ?? 0),
            'current_conns' => (int) ($data['connections_active'] ?? 0),
            'cum_conns' => (int) ($data['connections_total'] ?? 0),
            'cum_req' => (int) ($data['requests_total'] ?? 0),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $blob): array
    {
        $trimmed = trim($blob);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatSocketAddress(mixed $address): string
    {
        if ($address === null || $address === '') {
            return '';
        }

        if (! is_array($address)) {
            return is_string($address) ? trim($address) : $this->scalarString($address);
        }

        if (isset($address['socket_address']) && is_array($address['socket_address'])) {
            return $this->formatSocketAddress($address['socket_address']);
        }

        if (isset($address['pipe']) && is_array($address['pipe'])) {
            $path = $address['pipe']['path'] ?? $address['pipe']['address'] ?? null;

            return is_string($path) && $path !== '' ? $path : '';
        }

        if (isset($address['address']) && is_array($address['address'])) {
            return $this->formatSocketAddress($address['address']);
        }

        $host = $address['address'] ?? '*';
        if (! is_scalar($host)) {
            $encoded = json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '?';
        }

        $host = (string) $host;
        $port = (int) ($address['port_value'] ?? $address['port'] ?? 0);

        return $port > 0 ? $host.':'.$port : $host;
    }

    private function scalarString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            if (isset($value['message']) && is_scalar($value['message'])) {
                return trim((string) $value['message']);
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '';
        }

        return trim((string) $value);
    }

    private function formatHostHealthStatus(mixed $health): string
    {
        if (is_bool($health)) {
            return $health ? 'UP' : 'DOWN';
        }

        if (is_array($health)) {
            if (array_key_exists('healthy', $health)) {
                return $this->formatHostHealthStatus($health['healthy']);
            }

            foreach ([
                'failed_active_health_check',
                'failed_outlier_check',
                'failed_active_degraded_check',
                'excluded_via_immediate_hc_fail',
                'active_hc_timeout',
            ] as $failureFlag) {
                if (($health[$failureFlag] ?? false) === true) {
                    return 'DOWN';
                }
            }

            $eds = strtoupper($this->scalarString($health['eds_health_status'] ?? ''));
            if ($eds === 'UNHEALTHY') {
                return 'DOWN';
            }
            if ($eds === 'HEALTHY') {
                return 'UP';
            }

            // Static clusters without active health checks omit eds_health_status;
            // no failure flags means Envoy still routes to this host.
            if ($health !== []) {
                return 'UP';
            }

            return 'UNKNOWN';
        }

        $text = strtolower($this->scalarString($health));
        if ($text === '') {
            return 'UNKNOWN';
        }

        if (in_array($text, ['1', 'true', 'healthy', 'up'], true)) {
            return 'UP';
        }

        if (in_array($text, ['0', 'false', 'unhealthy', 'down'], true)) {
            return 'DOWN';
        }

        return strtoupper($text);
    }

    /**
     * @param  list<array<string, mixed>>  $hosts
     */
    private function rollupHostStatus(array $hosts): string
    {
        if ($hosts === []) {
            return 'UNKNOWN';
        }

        $statuses = array_map(fn (array $h): string => (string) ($h['status'] ?? 'UNKNOWN'), $hosts);
        $up = count(array_filter($statuses, fn (string $s): bool => $s === 'UP'));
        if ($up === count($hosts)) {
            return 'UP';
        }

        $down = count(array_filter($statuses, fn (string $s): bool => $s === 'DOWN'));
        if ($down === count($hosts)) {
            return 'DOWN';
        }
        if ($down > 0) {
            return 'DEGRADED';
        }

        return 'UNKNOWN';
    }
}
