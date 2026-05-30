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
        $heads = ['listeners', 'clusters', 'runtime'];
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
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $address = $this->formatSocketAddress($entry['local_address'] ?? $entry['address'] ?? null);
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
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $hosts = [];
            foreach ($entry['host_statuses'] ?? [] as $host) {
                if (! is_array($host)) {
                    continue;
                }
                $addr = $this->formatSocketAddress($host['address'] ?? null);
                $health = (string) data_get($host, 'health_status.healthy', data_get($host, 'health_status', ''));
                if (is_array($health)) {
                    $health = (string) ($health['healthy'] ?? 'unknown');
                }
                $hosts[] = [
                    'name' => $addr !== '' ? $addr : 'host',
                    'status' => is_bool($health) ? ($health ? 'UP' : 'DOWN') : strtoupper($health),
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
        if (! is_array($address)) {
            return is_string($address) ? $address : '';
        }
        if (isset($address['socket_address']) && is_array($address['socket_address'])) {
            $address = $address['socket_address'];
        }
        $host = (string) ($address['address'] ?? '*');
        $port = (int) ($address['port_value'] ?? 0);

        return $port > 0 ? $host.':'.$port : $host;
    }

    /**
     * @param  list<array<string, mixed>>  $hosts
     */
    private function rollupHostStatus(array $hosts): string
    {
        if ($hosts === []) {
            return 'UNKNOWN';
        }
        $up = count(array_filter($hosts, fn (array $h): bool => ($h['status'] ?? '') === 'UP'));
        if ($up === count($hosts)) {
            return 'UP';
        }
        if ($up === 0) {
            return 'DOWN';
        }

        return 'DEGRADED';
    }
}
