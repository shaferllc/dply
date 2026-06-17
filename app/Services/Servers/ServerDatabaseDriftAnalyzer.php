<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Support\Servers\ServerDatabaseHostCapabilities;

class ServerDatabaseDriftAnalyzer
{
    public function __construct(
        protected ServerDatabaseProvisioner $provisioner,
        protected ServerDatabaseHostCapabilities $capabilities,
    ) {}

    /**
     * @return array<string, array{only_in_dply: list<string>, only_on_server: list<string>}>
     */
    /** @return array<string, mixed> */
    public function analyze(Server $server): array
    {
        $caps = $this->capabilities->forServer($server);

        $localMysql = $server->serverDatabases->where('engine', 'mysql')->pluck('name')->sort()->values()->all();
        $localMariadb = $server->serverDatabases->where('engine', 'mariadb')->pluck('name')->sort()->values()->all();
        $localPg = $server->serverDatabases->where('engine', 'postgres')->pluck('name')->sort()->values()->all();
        $localMongo = $server->serverDatabases->where('engine', 'mongodb')->pluck('name')->sort()->values()->all();
        $localClickhouse = $server->serverDatabases->where('engine', 'clickhouse')->pluck('name')->sort()->values()->all();

        $remoteMysql = [];
        if (($caps['mysql']) || ($caps['mariadb'])) {
            try {
                $remoteMysql = $this->provisioner->listMysqlDatabaseNames($server);
            } catch (\Throwable) {
                $remoteMysql = [];
            }
        }

        $remotePg = [];
        if ($caps['postgres']) {
            try {
                $remotePg = $this->provisioner->listPostgresDatabaseNames($server);
            } catch (\Throwable) {
                $remotePg = [];
            }
        }

        $remoteMongo = [];
        if ($caps['mongodb']) {
            try {
                $remoteMongo = $this->provisioner->listMongodbDatabaseNames($server);
            } catch (\Throwable) {
                $remoteMongo = [];
            }
        }

        $remoteClickhouse = [];
        if ($caps['clickhouse']) {
            try {
                $remoteClickhouse = $this->provisioner->listClickhouseDatabaseNames($server);
            } catch (\Throwable) {
                $remoteClickhouse = [];
            }
        }

        $empty = ['only_in_dply' => [], 'only_on_server' => []];

        return [
            'mysql' => ($caps['mysql']) ? [
                'only_in_dply' => array_values(array_diff($localMysql, $remoteMysql)),
                'only_on_server' => array_values(array_diff($remoteMysql, $localMysql)),
            ] : $empty,
            'mariadb' => ($caps['mariadb']) ? [
                'only_in_dply' => array_values(array_diff($localMariadb, $remoteMysql)),
                'only_on_server' => array_values(array_diff($remoteMysql, $localMariadb)),
            ] : $empty,
            'postgres' => ($caps['postgres']) ? [
                'only_in_dply' => array_values(array_diff($localPg, $remotePg)),
                'only_on_server' => array_values(array_diff($remotePg, $localPg)),
            ] : $empty,
            'mongodb' => ($caps['mongodb']) ? [
                'only_in_dply' => array_values(array_diff($localMongo, $remoteMongo)),
                'only_on_server' => array_values(array_diff($remoteMongo, $localMongo)),
            ] : $empty,
            'clickhouse' => ($caps['clickhouse']) ? [
                'only_in_dply' => array_values(array_diff($localClickhouse, $remoteClickhouse)),
                'only_on_server' => array_values(array_diff($remoteClickhouse, $localClickhouse)),
            ] : $empty,
        ];
    }
}
