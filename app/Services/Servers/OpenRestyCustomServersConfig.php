<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteEdgeBackendProvisioner;

/**
 * CRUD for operator-defined server blocks stored in server meta and merged
 * into {@see OpenRestyEdgeConfigBuilder} before the catch-all server.
 */
class OpenRestyCustomServersConfig
{
    /**
     * @return list<array{name: string, server_names: list<string>, upstream: string}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, list<string>|string>>
     */
    public function read(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $stored = $meta['openresty_custom_servers'] ?? null;
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row): ?array => is_array($row) ? $this->normalizeRow($row) : null,
            $stored,
        )));
    }

    /**
     * @param  list<array{name: string, server_names?: list<string>|string, upstream?: string}>  $servers
     */
    public function save(Server $server, array $servers, ?ConsoleEmitter $emitter = null): void
    {
        $normalized = [];
        foreach ($servers as $row) {
            $parsed = $this->normalizeRow($row);
            if ($parsed !== null) {
                $normalized[] = $parsed;
            }
        }

        $this->assertUpstreamsExist($server, $normalized);

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['openresty_custom_servers'] = $normalized;
        $server->forceFill(['meta' => $meta])->save();

        app(SiteEdgeBackendProvisioner::class)->syncAllForServer($server, $emitter);
    }

    /**
     * @param  array<string, mixed> $serverNames
     */
    public function add(
        Server $server,
        string $name,
        array $serverNames,
        string $upstream,
        ?ConsoleEmitter $emitter = null,
    ): void {
        $name = $this->normalizeName($name);
        $serverNames = $this->normalizeServerNames($serverNames);
        $upstream = trim($upstream);
        if ($upstream === '') {
            throw new \RuntimeException('A target upstream name is required.');
        }

        $rows = $this->read($server);
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $name) {
                throw new \RuntimeException("A server block named `{$name}` already exists.");
            }
        }

        $rows[] = ['name' => $name, 'server_names' => $serverNames, 'upstream' => $upstream];
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
            throw new \RuntimeException("No custom server block named `{$name}` found.");
        }

        $this->save($server, $rows, $emitter);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serversFromServer(Server $server): array
    {
        return app(self::class)->read($server);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{name: string, server_names: list<string>, upstream: string}|null
     */
    private function normalizeRow(array $row): ?array
    {
        try {
            $name = $this->normalizeName((string) ($row['name'] ?? ''));
        } catch (\InvalidArgumentException) {
            return null;
        }

        $serverNames = $this->normalizeServerNames($row['server_names'] ?? []);
        if ($serverNames === []) {
            return null;
        }

        $upstream = trim((string) ($row['upstream'] ?? ''));
        if ($upstream === '') {
            return null;
        }

        return [
            'name' => $name,
            'server_names' => $serverNames,
            'upstream' => $upstream,
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \InvalidArgumentException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if (str_starts_with($name, 'srv_')) {
            throw new \InvalidArgumentException('Names prefixed with `srv_` are reserved for dply site routing.');
        }

        return $name;
    }

    /**
     * @param  list<string>|string $serverNames
     * @return list<string>
     */
    private function normalizeServerNames(array|string $serverNames): array
    {
        if (is_string($serverNames)) {
            $serverNames = preg_split('/[\s,]+/', $serverNames) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($d): string => trim((string) $d),
            $serverNames,
        ), fn (string $d): bool => $d !== '' && $d !== '_')));
    }

    /**
     * @param  list<array{name: string, server_names: list<string>, upstream: string}>  $servers
     */
    private function assertUpstreamsExist(Server $server, array $servers): void
    {
        $known = OpenRestyCustomUpstreamsConfig::knownUpstreamNames($server);
        foreach ($servers as $row) {
            $upstream = (string) ($row['upstream'] ?? '');
            if ($upstream === '') {
                continue;
            }
            if (str_starts_with($upstream, 'bk_')) {
                continue;
            }
            if (! in_array($upstream, $known, true)) {
                throw new \RuntimeException(
                    "Upstream `{$upstream}` is not a custom upstream. Add it under Upstreams first, or reference an existing dply site upstream (`bk_*`).",
                );
            }
        }
    }
}
