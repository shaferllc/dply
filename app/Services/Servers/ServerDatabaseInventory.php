<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Support\Servers\DatabaseCatalog;
use App\Support\Servers\DatabaseWorkspaceEngines;
use App\Support\Servers\ServerDatabaseHostCapabilities;

/**
 * Reconciles what a server's engines actually hold against what dply tracks.
 *
 * Answers two questions the control plane could not previously ask:
 *   - UNTRACKED — a real database on the box with no {@see ServerDatabase} row.
 *     Invisible everywhere in dply until adopted.
 *   - MISSING — a tracked row whose database is gone from the engine. It still
 *     shows in pickers, offers backups that will fail, and can be bound to a
 *     site that cannot connect.
 *
 * The scan is SSH-bound, so it never runs on a render path (CLAUDE.md). It is
 * invoked from a deferred loader / explicit rescan and its result is cached on
 * `server.meta['database_inventory']`, mirroring `meta['php_inventory']`, so
 * read-only surfaces (the site Database tab) can render from the cache alone.
 */
final class ServerDatabaseInventory
{
    public const META_KEY = 'database_inventory';

    public function __construct(
        private readonly ServerDatabaseRemoteExec $remote,
        private readonly ServerDatabaseHostCapabilities $capabilities,
    ) {}

    /**
     * Run the scan and cache it. SSH-bound — never call from render().
     *
     * @return array<string, mixed> the stored inventory
     */
    public function scan(Server $server): array
    {
        $present = array_keys(array_filter(
            $this->capabilities->forServer($server),
            static fn (bool $installed): bool => $installed,
        ));

        $engines = array_values(array_filter(
            $present,
            static fn (string $engine): bool => DatabaseCatalog::supports($engine),
        ));

        $results = $engines === [] ? [] : $this->remote->enumerateDatabases($server, $engines);

        $inventory = [
            'scanned_at' => now()->toIso8601String(),
            'engines' => $results,
        ];

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta[self::META_KEY] = $inventory;
        $server->forceFill(['meta' => $meta])->save();

        return $inventory;
    }

    /**
     * The cached inventory, or null when the server has never been scanned.
     * Read-only and cheap — safe on a render path.
     *
     * @return array<string, mixed>|null
     */
    public function cached(Server $server): ?array
    {
        $raw = data_get($server->meta, self::META_KEY);

        return is_array($raw) ? $raw : null;
    }

    /**
     * Databases present on the server that dply does not track.
     *
     * Reads the CACHE, so it is safe on a render path. Engines whose scan
     * failed contribute nothing: `ok => false` means "not checked", never
     * "empty", so a transient auth failure can never be read as "this engine
     * has no databases".
     *
     * @return list<array{engine: string, name: string}>
     */
    public function untracked(Server $server): array
    {
        $inventory = $this->cached($server);
        if ($inventory === null) {
            return [];
        }

        $tracked = $this->trackedByEngine($server);

        $out = [];
        foreach ((array) ($inventory['engines'] ?? []) as $engine => $result) {
            if (! is_array($result) || ($result['ok'] ?? false) !== true) {
                continue;
            }
            $engine = (string) $engine;
            $known = $tracked[$engine] ?? [];
            foreach ((array) ($result['databases'] ?? []) as $name) {
                $name = (string) $name;
                if ($name !== '' && ! in_array($name, $known, true)) {
                    $out[] = ['engine' => $engine, 'name' => $name];
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['engine'], $a['name']] <=> [$b['engine'], $b['name']]);

        return $out;
    }

    /**
     * Tracked rows whose database is no longer on the server.
     *
     * Same "not checked is not absent" rule, and deliberately never acts on its
     * own findings: a ServerDatabase row is the only record of a database's
     * credentials and site link, so removal is always an explicit operator
     * decision.
     *
     * @return list<ServerDatabase>
     */
    public function missing(Server $server): array
    {
        $inventory = $this->cached($server);
        if ($inventory === null) {
            return [];
        }

        $out = [];
        foreach ($server->serverDatabases()->get() as $db) {
            $engine = DatabaseWorkspaceEngines::family((string) $db->engine);

            // sqlite is a file, not a catalog entry — never enumerated, so it
            // can never be "missing" from an engine listing.
            if (! DatabaseCatalog::supports($engine)) {
                continue;
            }

            $result = data_get($inventory, "engines.{$engine}");
            if (! is_array($result) || ($result['ok'] ?? false) !== true) {
                continue;
            }

            if (! in_array((string) $db->name, array_map(strval(...), (array) ($result['databases'] ?? [])), true)) {
                $out[] = $db;
            }
        }

        return $out;
    }

    /**
     * Record an existing server database as a {@see ServerDatabase}.
     *
     * `credentials_known` is false: dply did not create this database and holds
     * no password for it. Admin-path operations still work; .env wiring and the
     * credential link stay gated until the operator supplies or rotates one.
     *
     * When $site is given the row is linked by `site_id` ONLY — no SiteBinding.
     * A binding owns DB_* and injects at deploy, and injecting an empty
     * DB_PASSWORD would break the running app. The binding is offered once the
     * password is known.
     */
    public function adopt(Server $server, string $engine, string $name, ?Site $site = null): ServerDatabase
    {
        $engine = DatabaseWorkspaceEngines::family($engine);

        return ServerDatabase::query()->create([
            'server_id' => $server->id,
            'site_id' => $site?->id,
            'name' => $name,
            'engine' => $engine,
            'username' => $this->discoverOwner($server, $engine, $name),
            'password' => '',
            'credentials_known' => false,
            'host' => '127.0.0.1',
            'description' => __('Adopted from :engine on this server', [
                'engine' => DatabaseWorkspaceEngines::label($engine),
            ]),
        ]);
    }

    /**
     * The role that owns a database, when the engine can tell us. Best-effort:
     * an empty username is honest ("we don't know") and does not block
     * adoption, since nothing can authenticate as it anyway without a password.
     */
    private function discoverOwner(Server $server, string $engine, string $name): string
    {
        if ($engine !== 'postgres') {
            return '';
        }

        try {
            [$raw, $exit] = $this->remote->postgresTuples(
                $server,
                'SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '.$this->quote($name),
            );
        } catch (\Throwable) {
            return '';
        }

        if ($exit !== null && $exit !== 0) {
            return '';
        }

        $owner = trim(strtok(trim((string) $raw), "\n") ?: '');

        return preg_match('/^[A-Za-z0-9_$-]{1,63}$/', $owner) === 1 ? $owner : '';
    }

    /** Single-quote a literal for SQL, doubling embedded quotes. */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Tracked database names on this server, grouped by engine family.
     *
     * @return array<string, list<string>>
     */
    private function trackedByEngine(Server $server): array
    {
        $out = [];
        foreach ($server->serverDatabases()->get(['engine', 'name']) as $db) {
            $family = DatabaseWorkspaceEngines::family((string) $db->engine);
            $out[$family][] = (string) $db->name;
        }

        return $out;
    }
}
