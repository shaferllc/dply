<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteEdgeBackendProvisioner;

/**
 * CRUD for extra Envoy HTTP listeners stored in server meta and merged into
 * {@see EnvoyEdgeConfigBuilder} alongside the primary :80 listener.
 */
class EnvoyCustomListenersConfig
{
    public const MODE_SHARED = 'shared';

    public const MODE_CLUSTER = 'cluster';

    /**
     * @return list<array{name: string, address: string, port: int, mode: string, default_cluster: string}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, int|string>>
     */
    public function read(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $stored = $meta['envoy_custom_listeners'] ?? null;
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row): ?array => is_array($row) ? $this->normalizeRow($row) : null,
            $stored,
        )));
    }

    /**
     * @param  array<string, mixed> $listeners
     */
    public function save(Server $server, array $listeners, ?ConsoleEmitter $emitter = null): void
    {
        $normalized = [];
        foreach ($listeners as $row) {
            $parsed = $this->normalizeRow($row);
            if ($parsed !== null) {
                $normalized[] = $parsed;
            }
        }

        $this->assertValid($normalized);

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['envoy_custom_listeners'] = $normalized;
        $server->forceFill(['meta' => $meta])->save();

        app(SiteEdgeBackendProvisioner::class)->syncAllForServer($server, $emitter);
    }

    /**
     * @param  array<string, mixed> $fields
     */
    public function add(Server $server, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $row = $this->normalizeRow($fields);
        if ($row === null) {
            throw new \RuntimeException('Invalid listener fields.');
        }

        $rows = $this->read($server);
        foreach ($rows as $existing) {
            if (($existing['name'] ?? '') === $row['name']) {
                throw new \RuntimeException("A listener named `{$row['name']}` already exists.");
            }
            if ((int) ($existing['port']) === $row['port']) {
                throw new \RuntimeException("Port {$row['port']} is already used by listener `{$existing['name']}`.");
            }
        }

        $rows[] = $row;
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
            throw new \RuntimeException("No custom listener named `{$name}` found.");
        }

        $this->save($server, $rows, $emitter);
    }

    /**
     * @return array<string, mixed>
     */
    public static function listenersFromServer(Server $server): array
    {
        return app(self::class)->read($server);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{name: string, address: string, port: int, mode: string, default_cluster: string}|null
     */
    private function normalizeRow(array $row): ?array
    {
        try {
            $name = $this->normalizeName((string) ($row['name'] ?? ''));
        } catch (\InvalidArgumentException) {
            return null;
        }

        $address = trim((string) ($row['address'] ?? '0.0.0.0'));
        if ($address === '') {
            $address = '0.0.0.0';
        }

        $port = (int) ($row['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        $mode = strtolower(trim((string) ($row['mode'] ?? self::MODE_SHARED)));
        if (! in_array($mode, [self::MODE_SHARED, self::MODE_CLUSTER], true)) {
            $mode = self::MODE_SHARED;
        }

        return [
            'name' => $name,
            'address' => $address,
            'port' => $port,
            'mode' => $mode,
            'default_cluster' => trim((string) ($row['default_cluster'] ?? '')),
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \InvalidArgumentException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if ($name === 'dply_http') {
            throw new \InvalidArgumentException('The name `dply_http` is reserved for the primary ingress listener.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed> $listeners
     */
    private function assertValid(array $listeners): void
    {
        $ports = [];
        foreach ($listeners as $row) {
            $port = (int) ($row['port']);
            if (isset($ports[$port])) {
                throw new \RuntimeException("Port {$port} is used by more than one custom listener.");
            }
            $ports[$port] = true;

            if (($row['mode'] ?? self::MODE_SHARED) === self::MODE_CLUSTER && trim((string) ($row['default_cluster'] ?? '')) === '') {
                throw new \RuntimeException("Listener `{$row['name']}` uses cluster mode but has no default_cluster.");
            }
        }
    }
}
