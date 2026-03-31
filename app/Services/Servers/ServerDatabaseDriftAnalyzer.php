<?php

namespace App\Services\Servers;

use App\Models\Server;

class ServerDatabaseDriftAnalyzer
{
    public function __construct(
        protected ServerDatabaseProvisioner $provisioner,
        protected ServerDatabaseHostCapabilities $capabilities
    ) {}

    /**
     * @return array{
     *   mysql: array{only_in_dply: list<string>, only_on_server: list<string>},
     *   postgres: array{only_in_dply: list<string>, only_on_server: list<string>}
     * }
     */
    public function analyze(Server $server): array
    {
        $caps = $this->capabilities->forServer($server);
        $localMysql = $server->serverDatabases->where('engine', 'mysql')->pluck('name')->sort()->values()->all();
        $localPg = $server->serverDatabases->where('engine', 'postgres')->pluck('name')->sort()->values()->all();

        $remoteMysql = [];
        $remotePg = [];
        if ($caps['mysql']) {
            try {
                $remoteMysql = $this->provisioner->listMysqlDatabaseNames($server);
            } catch (\Throwable) {
                $remoteMysql = [];
            }
        }
        if ($caps['postgres']) {
            try {
                $remotePg = $this->provisioner->listPostgresDatabaseNames($server);
            } catch (\Throwable) {
                $remotePg = [];
            }
        }

        return [
            'mysql' => [
                'only_in_dply' => array_values(array_diff($localMysql, $remoteMysql)),
                'only_on_server' => array_values(array_diff($remoteMysql, $localMysql)),
            ],
            'postgres' => [
                'only_in_dply' => array_values(array_diff($localPg, $remotePg)),
                'only_on_server' => array_values(array_diff($remotePg, $localPg)),
            ],
        ];
    }
}
