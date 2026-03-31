<?php

namespace App\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class ServerDatabaseHostCapabilities
{
    public function __construct(
        protected ServerDatabaseRemoteExec $remoteExec
    ) {}

    /**
     * @return array{mysql: bool, postgres: bool, redis: bool}
     */
    public function forServer(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['mysql' => false, 'postgres' => false, 'redis' => false];
        }

        $ttl = max(0, (int) config('server_database.capabilities_cache_ttl_seconds', 120));
        $key = 'server.'.$server->id.'.database_host_capabilities_v2';

        if ($ttl === 0) {
            return $this->probe($server);
        }

        return Cache::remember($key, $ttl, fn () => $this->probe($server));
    }

    public function forget(Server $server): void
    {
        Cache::forget('server.'.$server->id.'.database_host_capabilities');
        Cache::forget('server.'.$server->id.'.database_host_capabilities_v2');
    }

    /**
     * @return array{mysql: bool, postgres: bool, redis: bool}
     */
    public function probe(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['mysql' => false, 'postgres' => false, 'redis' => false];
        }

        return [
            'mysql' => $this->remoteExec->probeMysql($server),
            'postgres' => $this->remoteExec->probePostgres($server),
            'redis' => $this->remoteExec->probeRedis($server),
        ];
    }
}
