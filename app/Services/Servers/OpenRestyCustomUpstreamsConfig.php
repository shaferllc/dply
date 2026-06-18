<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteEdgeBackendProvisioner;

/**
 * CRUD for operator-defined upstream pools stored in server meta and merged
 * into {@see OpenRestyEdgeConfigBuilder}.
 */
class OpenRestyCustomUpstreamsConfig
{
    /**
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [];

    /**
     * @return list<array{name: string, servers: list<string>}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, list<string>|string>>
     */
    public function read(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $stored = $meta['openresty_custom_upstreams'] ?? null;
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row): ?array => is_array($row) ? $this->normalizeRow($row) : null,
            $stored,
        )));
    }

    /**
     * @param  list<array{name: string, servers?: list<string>|string}>  $upstreams
     */
    public function save(Server $server, array $upstreams, ?ConsoleEmitter $emitter = null): void
    {
        $normalized = [];
        foreach ($upstreams as $row) {
            $parsed = $this->normalizeRow($row);
            if ($parsed !== null) {
                $normalized[] = $parsed;
            }
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['openresty_custom_upstreams'] = $normalized;
        $server->forceFill(['meta' => $meta])->save();

        app(SiteEdgeBackendProvisioner::class)->syncAllForServer($server, $emitter);
    }

    /**
     * @param  array<string, mixed> $servers
     */
    public function add(
        Server $server,
        string $name,
        array $servers,
        ?ConsoleEmitter $emitter = null,
    ): void {
        $name = $this->normalizeName($name);
        $servers = $this->normalizeServers($servers);
        if ($servers === []) {
            throw new \RuntimeException('At least one upstream server (host:port) is required.');
        }

        $rows = $this->read($server);
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $name) {
                throw new \RuntimeException("An upstream named `{$name}` already exists.");
            }
        }

        $rows[] = ['name' => $name, 'servers' => $servers];
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
            throw new \RuntimeException("No custom upstream named `{$name}` found.");
        }

        $this->save($server, $rows, $emitter);
    }

    /**
     * @return array<string, mixed>
     */
    public static function upstreamsFromServer(Server $server): array
    {
        return app(self::class)->read($server);
    }

    /**
     * @return list<string>
     */
    public static function knownUpstreamNames(Server $server): array
    {
        return array_values(array_filter(array_map(
            fn (array $row): string => (string) ($row['name'] ?? ''),
            self::upstreamsFromServer($server),
        ), fn (string $n): bool => $n !== ''));
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{name: string, servers: list<string>}|null
     */
    private function normalizeRow(array $row): ?array
    {
        try {
            $name = $this->normalizeName((string) ($row['name'] ?? ''));
        } catch (\InvalidArgumentException) {
            return null;
        }

        $servers = $this->normalizeServers($row['servers'] ?? []);
        if ($servers === []) {
            return null;
        }

        return ['name' => $name, 'servers' => $servers];
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \InvalidArgumentException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if (str_starts_with($name, 'bk_')) {
            throw new \InvalidArgumentException('Names prefixed with `bk_` are reserved for dply site upstreams.');
        }

        return $name;
    }

    /**
     * @param  list<string>|string $servers
     * @return list<string>
     */
    private function normalizeServers(array|string $servers): array
    {
        if (is_string($servers)) {
            $servers = preg_split('/[\s,]+/', $servers) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($s): string => trim((string) $s),
            $servers,
        ), fn (string $s): bool => $s !== '')));
    }
}
