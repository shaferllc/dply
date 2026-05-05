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
     * @return array{mysql: bool, postgres: bool, sqlite: bool}
     */
    public function forServer(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['mysql' => false, 'postgres' => false, 'sqlite' => false];
        }

        $ttl = max(0, (int) config('server_database.capabilities_cache_ttl_seconds', 120));
        $key = 'server.'.$server->id.'.database_host_capabilities_v3';

        if ($ttl === 0) {
            return $this->probe($server);
        }

        return Cache::remember($key, $ttl, fn () => $this->probe($server));
    }

    public function forget(Server $server): void
    {
        Cache::forget('server.'.$server->id.'.database_host_capabilities_v3');
    }

    /**
     * @return array{mysql: bool, postgres: bool, sqlite: bool}
     */
    public function probe(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['mysql' => false, 'postgres' => false, 'sqlite' => false];
        }

        return [
            'mysql' => $this->remoteExec->probeMysql($server),
            'postgres' => $this->remoteExec->probePostgres($server),
            'sqlite' => $this->remoteExec->probeSqlite($server),
        ];
    }
}
