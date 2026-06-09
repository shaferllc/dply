<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Cache;

/**
 * Parse the `INFO replication` section into a typed payload for the Overview tile +
 * Stats-subtab Replication card. Reads only — actual REPLICAOF wiring is the wizard's
 * job (Phase 4b). Memcached has no equivalent; callers guard via {@see ServerCacheService::engineSupportsAuth()}.
 */
class CacheServiceReplication
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @return array{
     *     reachable: bool,
     *     role: ?string,
     *     master_endpoint: ?string,
     *     master_link_status: ?string,
     *     master_last_io_seconds_ago: ?int,
     *     master_sync_in_progress: ?bool,
     *     master_replid: ?string,
     *     replication_offset: ?int,
     *     connected_replicas: int,
     *     replicas: list<array{address: string, state: string, offset: int, lag_seconds: int}>,
     * }
     */
    public function snapshot(Server $server, ServerCacheService $cacheService): array
    {
        if (! ServerCacheService::engineSupportsAuth($cacheService->engine)) {
            return $this->empty();
        }

        $ttl = max(0, (int) config('server_cache.replication_cache_ttl_seconds', 15));
        $key = 'server.'.$server->id.'.cache_replication_v1.'.$cacheService->engine;

        $compute = function () use ($server, $cacheService): array {
            $cli = CacheServiceStats::binaryFor($cacheService->engine);
            $authFlag = filled($cacheService->auth_password ?? null)
                ? '-a '.escapeshellarg((string) $cacheService->auth_password).' '
                : '';

            try {
                $output = $this->executor->runInlineBash(
                    $server,
                    'cache-service:info-replication:'.$cacheService->engine,
                    $authFlag.escapeshellarg($cli).' -p '.(int) $cacheService->port.' INFO replication 2>/dev/null',
                    timeoutSeconds: 30,
                    asRoot: false,
                );
            } catch (\Throwable) {
                return $this->empty();
            }

            if ($output->exitCode !== 0) {
                return $this->empty();
            }

            return $this->parse($output->buffer);
        };

        try {
            return $ttl === 0 ? $compute() : Cache::remember($key, $ttl, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    public function forget(Server $server, string $engine): void
    {
        Cache::forget('server.'.$server->id.'.cache_replication_v1.'.$engine);
    }

    /**
     * @return array{
     *     reachable: bool,
     *     role: ?string,
     *     master_endpoint: ?string,
     *     master_link_status: ?string,
     *     master_last_io_seconds_ago: ?int,
     *     master_sync_in_progress: ?bool,
     *     master_replid: ?string,
     *     replication_offset: ?int,
     *     connected_replicas: int,
     *     replicas: list<array{address: string, state: string, offset: int, lag_seconds: int}>,
     * }
     */
    private function parse(string $raw): array
    {
        $kv = [];
        $replicas = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            if ($key === '' || $value === '') {
                continue;
            }

            // `slave0:ip=10.0.0.5,port=6379,state=online,offset=123,lag=0`
            if (preg_match('/^slave\d+$/', $key)) {
                $parts = [];
                foreach (explode(',', $value) as $pair) {
                    $eq = strpos($pair, '=');
                    if ($eq !== false) {
                        $parts[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
                    }
                }
                $replicas[] = [
                    'address' => ($parts['ip'] ?? '').':'.($parts['port'] ?? ''),
                    'state' => (string) ($parts['state'] ?? 'unknown'),
                    'offset' => (int) ($parts['offset'] ?? 0),
                    'lag_seconds' => (int) ($parts['lag'] ?? 0),
                ];

                continue;
            }
            $kv[$key] = $value;
        }

        $masterEndpoint = isset($kv['master_host'], $kv['master_port'])
            ? $kv['master_host'].':'.$kv['master_port']
            : null;

        return [
            'reachable' => true,
            'role' => $kv['role'] ?? null,
            'master_endpoint' => $masterEndpoint,
            'master_link_status' => $kv['master_link_status'] ?? null,
            'master_last_io_seconds_ago' => isset($kv['master_last_io_seconds_ago']) ? (int) $kv['master_last_io_seconds_ago'] : null,
            'master_sync_in_progress' => isset($kv['master_sync_in_progress']) ? ((int) $kv['master_sync_in_progress']) === 1 : null,
            'master_replid' => $kv['master_replid'] ?? null,
            'replication_offset' => isset($kv['master_repl_offset']) ? (int) $kv['master_repl_offset'] : null,
            'connected_replicas' => isset($kv['connected_slaves']) ? (int) $kv['connected_slaves'] : count($replicas),
            'replicas' => $replicas,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'reachable' => false,
            'role' => null,
            'master_endpoint' => null,
            'master_link_status' => null,
            'master_last_io_seconds_ago' => null,
            'master_sync_in_progress' => null,
            'master_replid' => null,
            'replication_offset' => null,
            'connected_replicas' => 0,
            'replicas' => [],
        ];
    }
}
