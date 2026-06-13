<?php

declare(strict_types=1);

namespace App\Services\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoFunctionsDatabases
{


    /**
     * Create a DigitalOcean Functions (serverless) namespace. The returned
     * api_host + access_key are the OpenWhisk credentials a function deploy
     * needs — stored on the serverless host Server's meta.
     *
     * @return array{api_host: string, namespace: string, access_key: string, region: string}
     */
    public function createFunctionsNamespace(string $region, string $label): array
    {
        $response = $this->request('post', '/functions/namespaces', [
            'region' => $region,
            'label' => $label,
        ]);
        $this->assertSuccess($response, 'create functions namespace');

        $ns = $response->json('namespace');
        $ns = is_array($ns) ? $ns : [];

        // OpenWhisk (which backs DO Functions) authenticates with a
        // `uuid:key` pair — the deployer splits the access key on the colon.
        // DO returns `uuid` and `key` separately, so recombine them here.
        $uuid = (string) ($ns['uuid'] ?? '');
        $key = (string) ($ns['key'] ?? '');
        $accessKey = ($uuid !== '' && $key !== '') ? $uuid.':'.$key : $key;

        return [
            'api_host' => (string) ($ns['api_host'] ?? ''),
            'namespace' => (string) ($ns['namespace'] ?? $ns['uuid'] ?? ''),
            'access_key' => $accessKey,
            'region' => (string) ($ns['region'] ?? $region),
        ];
    }

    /**
     * List the DigitalOcean Functions scheduled triggers in a namespace.
     *
     * @return list<array<string, mixed>>
     */
    public function functionTriggers(string $namespace): array
    {
        $response = $this->request('get', "/functions/namespaces/{$namespace}/triggers");
        $this->assertSuccess($response, 'list function triggers');

        $triggers = $response->json('triggers');

        return is_array($triggers) ? array_values($triggers) : [];
    }

    /**
     * Create a SCHEDULED trigger — DigitalOcean fires `$function` on the cron
     * (evaluated in UTC). `body` must be a JSON object, so an empty payload is
     * sent as `{}` (a PHP `[]` would serialize to `[]` and DO rejects it).
     *
     * @return array<string, mixed> the created trigger
     */
    public function createScheduledFunctionTrigger(string $namespace, string $name, string $function, string $cron): array
    {
        $response = $this->request('post', "/functions/namespaces/{$namespace}/triggers", [
            'name' => $name,
            'function' => $function,
            'type' => 'SCHEDULED',
            'is_enabled' => true,
            'scheduled_details' => ['cron' => $cron, 'body' => (object) []],
        ]);
        $this->assertSuccess($response, 'create scheduled function trigger');

        return (array) $response->json('trigger');
    }

    /**
     * Delete a function trigger. A 404 (already gone) is treated as success
     * so removal is idempotent.
     */
    public function deleteFunctionTrigger(string $namespace, string $name): void
    {
        $response = $this->request('delete', "/functions/namespaces/{$namespace}/triggers/{$name}");

        if (! $response->successful() && $response->status() !== 404) {
            $this->assertSuccess($response, 'delete function trigger');
        }
    }

    /**
     * Create a DigitalOcean Managed Database cluster. It returns immediately
     * with status `creating`; poll {@see getDatabaseCluster()} until `online`.
     *
     * @return array{id: string, status: string, engine: string, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    public function createDatabaseCluster(string $engine, string $region, string $size, string $name): array
    {
        $response = $this->request('post', '/databases', [
            'name' => $name,
            'engine' => $engine,
            'region' => $region,
            'size' => $size,
            'num_nodes' => 1,
        ]);
        $this->assertSuccess($response, 'create database cluster');

        return $this->normalizeDatabaseCluster($response->json('database'));
    }

    /**
     * @return array{id: string, status: string, engine: string, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    public function getDatabaseCluster(string $id): array
    {
        $response = $this->request('get', '/databases/'.$id);
        $this->assertSuccess($response, 'get database cluster');

        return $this->normalizeDatabaseCluster($response->json('database'));
    }

    /**
     * Create a transaction-mode connection pool (PgBouncer) on a Postgres
     * cluster. Serverless functions open a fresh connection on every cold
     * start; a pool multiplexes those onto a small set of backend
     * connections so the cluster's connection limit is not exhausted.
     *
     * @return array{name: string, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    public function createDatabaseConnectionPool(string $clusterId, string $name, string $database, string $user, int $size = 10): array
    {
        $response = $this->request('post', '/databases/'.$clusterId.'/pools', [
            'name' => $name,
            'mode' => 'transaction',
            'size' => $size,
            'db' => $database,
            'user' => $user,
        ]);
        $this->assertSuccess($response, 'create database connection pool');

        $pool = $response->json('pool');
        $pool = is_array($pool) ? $pool : [];
        $connection = is_array($pool['connection'] ?? null) ? $pool['connection'] : [];

        return [
            'name' => (string) ($pool['name'] ?? $name),
            'connection' => [
                'host' => (string) ($connection['host'] ?? ''),
                'port' => (int) ($connection['port'] ?? 0),
                'user' => (string) ($connection['user'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
                'database' => (string) ($connection['database'] ?? ''),
                'ssl' => (bool) ($connection['ssl'] ?? true),
            ],
        ];
    }

    /**
     * Delete a DigitalOcean Managed Database cluster. Returns true on a
     * successful delete (204), false on a 404 (already gone) so teardown
     * is idempotent — mirrors {@see deleteKubernetesCluster()}.
     */
    public function deleteDatabaseCluster(string $clusterId): bool
    {
        $response = $this->request('delete', '/databases/'.$clusterId);
        if ($response->status() === 404) {
            return false;
        }
        $this->assertSuccess($response, 'delete database cluster');

        return true;
    }

    /**
     * @return array{id: string, status: string, engine: string, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    private function normalizeDatabaseCluster(mixed $database): array
    {
        $database = is_array($database) ? $database : [];
        $connection = is_array($database['connection'] ?? null) ? $database['connection'] : [];

        return [
            'id' => (string) ($database['id'] ?? ''),
            'status' => (string) ($database['status'] ?? ''),
            'engine' => (string) ($database['engine'] ?? ''),
            'connection' => [
                'host' => (string) ($connection['host'] ?? ''),
                'port' => (int) ($connection['port'] ?? 0),
                'user' => (string) ($connection['user'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
                'database' => (string) ($connection['database'] ?? ''),
                'uri' => (string) ($connection['uri'] ?? ''),
                'ssl' => (bool) ($connection['ssl'] ?? true),
            ],
        ];
    }
}
