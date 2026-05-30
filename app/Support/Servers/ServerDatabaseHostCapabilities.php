<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Services\Servers\ServerDatabaseRemoteExec;
use Illuminate\Support\Facades\Cache;

/**
 * SSH-probe whether each database engine is reachable on a server. Mirrors
 * {@see ServerCacheServiceHostCapabilities} and is consumed by the WorkspaceDatabases
 * Livewire component to gate the per-engine status UI.
 */
class ServerDatabaseHostCapabilities
{
    public function __construct(
        protected ServerDatabaseRemoteExec $remoteExec
    ) {}

    /**
     * @return array{mysql: bool, mariadb: bool, postgres: bool, mongodb: bool, clickhouse: bool, sqlite: bool}
     */
    public function forServer(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return DatabaseWorkspaceEngines::defaultCapabilities();
        }

        $ttl = max(0, (int) config('server_database.capabilities_cache_ttl_seconds', 120));
        $key = 'server.'.$server->id.'.database_host_capabilities_v5';

        if ($ttl === 0) {
            return $this->probe($server);
        }

        return Cache::remember($key, $ttl, fn () => $this->probe($server));
    }

    public function forget(Server $server): void
    {
        Cache::forget('server.'.$server->id.'.database_host_capabilities_v3');
        Cache::forget('server.'.$server->id.'.database_host_capabilities_v4');
    }

    /**
     * @return array{mysql: bool, mariadb: bool, postgres: bool, mongodb: bool, clickhouse: bool, sqlite: bool}
     */
    public function probe(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return DatabaseWorkspaceEngines::defaultCapabilities();
        }

        return [
            'mysql' => $this->remoteExec->probeMysql($server),
            'mariadb' => $this->remoteExec->probeMariadb($server),
            'postgres' => $this->remoteExec->probePostgres($server),
            'mongodb' => $this->remoteExec->probeMongodb($server),
            'clickhouse' => $this->remoteExec->probeClickhouse($server),
            'sqlite' => $this->remoteExec->probeSqlite($server),
        ];
    }
}
