<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteEdgeBackendProvisioner;

/**
 * CRUD for operator-defined Envoy virtual hosts stored in server meta and
 * merged into {@see EnvoyEdgeConfigBuilder} before the catch-all vhost.
 */
class EnvoyCustomVirtualHostsConfig
{
    /**
     * @return list<array{name: string, domains: list<string>, cluster: string}>
     */
    public function read(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $stored = $meta['envoy_custom_virtual_hosts'] ?? null;
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row): ?array => is_array($row) ? $this->normalizeRow($row) : null,
            $stored,
        )));
    }

    /**
     * @param  list<array{name: string, domains?: list<string>|string, cluster?: string}>  $virtualHosts
     */
    public function save(Server $server, array $virtualHosts, ?ConsoleEmitter $emitter = null): void
    {
        $normalized = [];
        foreach ($virtualHosts as $row) {
            $parsed = $this->normalizeRow($row);
            if ($parsed !== null) {
                $normalized[] = $parsed;
            }
        }

        $this->assertClustersExist($server, $normalized);

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['envoy_custom_virtual_hosts'] = $normalized;
        $server->forceFill(['meta' => $meta])->save();

        app(SiteEdgeBackendProvisioner::class)->syncAllForServer($server, $emitter);
    }

    /**
     * @param  list<string>  $domains
     */
    public function add(
        Server $server,
        string $name,
        array $domains,
        string $cluster,
        ?ConsoleEmitter $emitter = null,
    ): void {
        $name = $this->normalizeName($name);
        $domains = $this->normalizeDomains($domains);
        $cluster = trim($cluster);
        if ($cluster === '') {
            throw new \RuntimeException('A target cluster name is required.');
        }

        $rows = $this->read($server);
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $name) {
                throw new \RuntimeException("A virtual host named `{$name}` already exists.");
            }
        }

        $rows[] = ['name' => $name, 'domains' => $domains, 'cluster' => $cluster];
        $this->save($server, $rows, $emitter);
    }

    public function remove(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $name = $this->normalizeName($name);
        $rows = array_values(array_filter(
            $this->read($server),
            fn (array $row): bool => ($row['name'] ?? '') !== $name,
        ));

        if (count($rows) === count($this->read($server))) {
            throw new \RuntimeException("No custom virtual host named `{$name}` found.");
        }

        $this->save($server, $rows, $emitter);
    }

    /**
     * @return list<array{name: string, domains: list<string>, cluster: string}>
     */
    public static function virtualHostsFromServer(Server $server): array
    {
        return app(self::class)->read($server);
    }

    /**
     * @return list<string>
     */
    public static function knownClusterNames(Server $server): array
    {
        $names = array_map(
            fn (array $c): string => (string) ($c['name'] ?? ''),
            EnvoyCustomClustersConfig::clustersFromServer($server),
        );

        return array_values(array_filter(array_unique($names), fn (string $n): bool => $n !== ''));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, domains: list<string>, cluster: string}|null
     */
    private function normalizeRow(array $row): ?array
    {
        try {
            $name = $this->normalizeName((string) ($row['name'] ?? ''));
        } catch (\InvalidArgumentException) {
            return null;
        }

        $domains = $this->normalizeDomains($row['domains'] ?? []);
        if ($domains === []) {
            return null;
        }

        $cluster = trim((string) ($row['cluster'] ?? ''));
        if ($cluster === '') {
            return null;
        }

        return [
            'name' => $name,
            'domains' => $domains,
            'cluster' => $cluster,
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \InvalidArgumentException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if (str_starts_with($name, 'vhost_') || $name === 'dply_unmatched') {
            throw new \InvalidArgumentException('Names prefixed with `vhost_` and `dply_unmatched` are reserved for dply site routing.');
        }

        return $name;
    }

    /**
     * @param  list<string>|string  $domains
     * @return list<string>
     */
    private function normalizeDomains(array|string $domains): array
    {
        if (is_string($domains)) {
            $domains = preg_split('/[\s,]+/', $domains) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($d): string => trim((string) $d),
            $domains,
        ), fn (string $d): bool => $d !== '' && $d !== '*')));
    }

    /**
     * @param  list<array{name: string, domains: list<string>, cluster: string}>  $virtualHosts
     */
    private function assertClustersExist(Server $server, array $virtualHosts): void
    {
        $known = self::knownClusterNames($server);
        foreach ($virtualHosts as $row) {
            $cluster = (string) ($row['cluster'] ?? '');
            if ($cluster === '') {
                continue;
            }
            if (str_starts_with($cluster, 'cluster_')) {
                continue;
            }
            if (! in_array($cluster, $known, true)) {
                throw new \RuntimeException(
                    "Cluster `{$cluster}` is not a custom cluster. Add it under Clusters first, or reference an existing dply site cluster (`cluster_*`).",
                );
            }
        }
    }
}
