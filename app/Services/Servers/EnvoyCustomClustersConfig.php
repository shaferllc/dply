<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteEdgeBackendProvisioner;
use App\Services\SshConnection;
use App\Support\Servers\EnvoyConfigParser;

/**
 * CRUD for operator-defined Envoy clusters stored in server meta and merged
 * into {@see EnvoyEdgeConfigBuilder} output on every edge routing rebuild.
 */
class EnvoyCustomClustersConfig
{
    use PrivilegedRemoteFileWrites;

    private const REMOTE_PATH = '/etc/envoy/envoy.yaml';

    /**
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'connect_timeout' => [
            'type' => 'string',
            'default' => '5s',
            'label' => 'connect_timeout',
            'help' => 'Upstream connect timeout (e.g. `5s`, `250ms`).',
        ],
        'lb_policy' => [
            'type' => 'string',
            'default' => 'ROUND_ROBIN',
            'label' => 'lb_policy',
            'help' => '`ROUND_ROBIN`, `LEAST_REQUEST`, `RING_HASH`, or `RANDOM`.',
        ],
    ];

    /**
     * @return list<array{name: string, connect_timeout: string, lb_policy: string, endpoints: list<string>}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, list<string>|string>>
     */
    public function read(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $stored = $meta['envoy_custom_clusters'] ?? null;
        if (is_array($stored)) {
            return array_values(array_filter(array_map(
                fn ($row): ?array => is_array($row) ? $this->normalizeClusterRow($row) : null,
                $stored,
            )));
        }

        $contents = $this->readRemote($server);
        if ($contents === null) {
            return [];
        }

        return EnvoyConfigParser::customClusters($contents);
    }

    /**
     * @param  list<array{name: string, connect_timeout?: string, lb_policy?: string, endpoints?: list<string>}>  $clusters
     */
    public function save(Server $server, array $clusters, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $normalized = [];
        foreach ($clusters as $cluster) {
            $row = $this->normalizeClusterRow($cluster);
            if ($row !== null) {
                $normalized[] = $row;
            }
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['envoy_custom_clusters'] = $normalized;
        $server->forceFill(['meta' => $meta])->save();

        app(SiteEdgeBackendProvisioner::class)->syncAllForServer($server, $emitter);
        $emit->success('Custom clusters saved and edge routing regenerated.');
    }

    /**
     * @param  array<string, mixed> $endpoints
     * @param  array<string, mixed> $values
     */
    public function addCluster(
        Server $server,
        string $name,
        array $endpoints,
        array $values,
        ?ConsoleEmitter $emitter = null,
    ): void {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if (str_starts_with($name, 'cluster_')) {
            throw new \RuntimeException('Names prefixed with `cluster_` are reserved for dply site backends.');
        }

        $endpoints = array_values(array_filter(array_map('trim', $endpoints), fn (string $e): bool => $e !== ''));
        if ($endpoints === []) {
            throw new \RuntimeException('At least one endpoint is required (e.g. `127.0.0.1:8080`).');
        }

        $clusters = $this->read($server);
        foreach ($clusters as $cluster) {
            if (($cluster['name'] ?? '') === $name) {
                throw new \RuntimeException("A cluster named `{$name}` already exists.");
            }
        }

        $clusters[] = [
            'name' => $name,
            'connect_timeout' => trim((string) ($values['connect_timeout'] ?? '5s')) ?: '5s',
            'lb_policy' => trim((string) ($values['lb_policy'] ?? 'ROUND_ROBIN')) ?: 'ROUND_ROBIN',
            'endpoints' => $endpoints,
        ];

        $this->save($server, $clusters, $emitter);
    }

    public function removeCluster(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $clusters = array_values(array_filter(
            $this->read($server),
            fn (array $c): bool => ($c['name'] ?? '') !== $name,
        ));

        if (count($clusters) === count($this->read($server))) {
            throw new \RuntimeException("No custom cluster named `{$name}` found.");
        }

        $this->save($server, $clusters, $emitter);
    }

    /**
     * @return array<string, mixed>
     */
    public static function clustersFromServer(Server $server): array
    {
        return app(self::class)->read($server);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{name: string, connect_timeout: string, lb_policy: string, endpoints: list<string>}|null
     */
    private function normalizeClusterRow(array $row): ?array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $endpoints = array_values(array_filter(array_map(
            'trim',
            (array) ($row['endpoints'] ?? []),
        ), fn (string $e): bool => $e !== ''));

        return [
            'name' => $name,
            'connect_timeout' => trim((string) ($row['connect_timeout'] ?? '5s')) ?: '5s',
            'lb_policy' => trim((string) ($row['lb_policy'] ?? 'ROUND_ROBIN')) ?: 'ROUND_ROBIN',
            'endpoints' => $endpoints,
        ];
    }

    private function readRemote(Server $server): ?string
    {
        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec($this->privilegedCommand($server, 'cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null'), 15);
            if ($contents === '' || ($ssh->lastExecExitCode() ?? 1) !== 0) {
                return null;
            }

            return (string) $contents;
        } catch (\Throwable) {
            return null;
        }
    }
}
