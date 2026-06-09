<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceDatabases;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use Illuminate\Support\Collection;

/**
 * View-model for the server Databases workspace blade tree. Keeps banner/setup
 * and tab preamble logic out of {@see resources/views/livewire/servers/workspace-databases.blade.php}.
 */
final class DatabaseWorkspaceViewData
{
    /**
     * @param  Collection<string, ServerDatabaseEngine>  $engineRows
     * @param  array{mysql: bool, mariadb: bool, postgres: bool, sqlite: bool}  $capabilities
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        WorkspaceDatabases $component,
        Collection $engineRows,
        array $capabilities,
        bool $includeDiscoveryContext = false,
    ): array {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->ssh_private_key;
        $isDeployer = auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
        $engineLabels = collect(DatabaseWorkspaceEngines::ENGINE_TABS)
            ->mapWithKeys(fn (string $engine): array => [$engine => DatabaseWorkspaceEngines::label($engine)])
            ->all();
        $engines = DatabaseWorkspaceEngines::ENGINE_TABS;

        // Engine => coming-soon bool. MySQL / PostgreSQL / SQLite are always
        // available; MariaDB, MongoDB, and ClickHouse are gated behind
        // database.{engine} flags. Drives the Soon badge on the tab strip +
        // the coming-soon teaser in the engine overview panel.
        $comingSoonEngines = DatabaseEngineAvailability::comingSoonMap($engines);

        $engineWorking = $engineRows->contains(fn (ServerDatabaseEngine $row): bool => in_array($row->status, [
            ServerDatabaseEngine::STATUS_PENDING,
            ServerDatabaseEngine::STATUS_INSTALLING,
            ServerDatabaseEngine::STATUS_UNINSTALLING,
        ], true));

        $data = compact(
            'card',
            'opsReady',
            'isDeployer',
            'engineLabels',
            'engines',
            'comingSoonEngines',
            'engineWorking',
            'capabilities',
            'engineRows',
        );

        if ($includeDiscoveryContext) {
            $localMysql = $server->serverDatabases->where('engine', 'mysql')->pluck('name')->all();
            $localMariadb = $server->serverDatabases->where('engine', 'mariadb')->pluck('name')->all();
            $localPg = $server->serverDatabases->where('engine', 'postgres')->pluck('name')->all();
            $mysqlOnlyOnServer = array_values(array_diff($component->remote_mysql_databases, $localMysql));
            $mariadbOnlyOnServer = array_values(array_diff($component->remote_mysql_databases, $localMariadb));
            $localMongo = $server->serverDatabases->where('engine', 'mongodb')->pluck('name')->all();
            $localClickhouse = $server->serverDatabases->where('engine', 'clickhouse')->pluck('name')->all();
            $pgOnlyOnServer = array_values(array_diff($component->remote_postgres_databases, $localPg));
            $mongoOnlyOnServer = array_values(array_diff($component->remote_mongodb_databases, $localMongo));
            $clickhouseOnlyOnServer = array_values(array_diff($component->remote_clickhouse_databases, $localClickhouse));

            $data = array_merge($data, compact(
                'mysqlOnlyOnServer',
                'mariadbOnlyOnServer',
                'pgOnlyOnServer',
                'mongoOnlyOnServer',
                'clickhouseOnlyOnServer',
            ));
        }

        return $data;
    }
}
