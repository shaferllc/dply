<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\Site;

/**
 * Lets the Environment editor pull connection settings from managed resources
 * (databases, redis/cache) on any server the operator can reach, and turn the
 * chosen one into env vars to insert into the site's .env — the same key set
 * the deploy-time bindings inject, but materialised for editing.
 *
 * Resource ids are namespaced: "db:<id>" / "cache:<id>".
 */
class SiteEnvResourceImporter
{
    /**
     * Resources the operator may import from: databases + cache services on
     * servers in the site's organization or owned by the current user.
     *
     * @return list<array{id: string, type: string, label: string, server: string, same_server: bool}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, bool|string>>
     */
    public function candidates(Site $site): array
    {
        $serverIds = $this->accessibleServerIds($site);
        if ($serverIds === []) {
            return [];
        }

        $out = [];

        ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->with('server:id,name')
            ->orderBy('name')
            ->get()
            ->each(function (ServerDatabase $db) use (&$out, $site): void {
                $out[] = [
                    'id' => 'db:'.$db->id,
                    'type' => 'database',
                    'label' => trim((string) $db->name).' ('.$db->engine.')',
                    'server' => (string) ($db->server->name ?? '—'),
                    'same_server' => (string) $db->server_id === (string) $site->server_id,
                ];
            });

        ServerCacheService::query()
            ->whereIn('server_id', $serverIds)
            ->with('server:id,name')
            ->get()
            ->each(function (ServerCacheService $cache) use (&$out, $site): void {
                $out[] = [
                    'id' => 'cache:'.$cache->id,
                    'type' => 'cache',
                    'label' => $cache->engine.' ('.($cache->name ?: 'default').')',
                    'server' => (string) ($cache->server->name ?? '—'),
                    'same_server' => (string) $cache->server_id === (string) $site->server_id,
                ];
            });

        return $out;
    }

    /**
     * The candidate env key => value map for a chosen resource, or [] when the
     * id is unknown or out of the operator's reach.
     *
     * @return list<array<string, bool|string>>
     */
    /** @return array<string, mixed> */
    public function envFor(Site $site, string $resourceId): array
    {
        $serverIds = $this->accessibleServerIds($site);
        [$kind, $id] = array_pad(explode(':', $resourceId, 2), 2, '');

        if ($kind === 'db' && $id !== '') {
            $db = ServerDatabase::query()->with('server')->whereKey($id)->first();
            if ($db && in_array((string) $db->server_id, $serverIds, true)) {
                return $this->databaseEnv($db, $site);
            }
        }

        if ($kind === 'cache' && $id !== '') {
            $cache = ServerCacheService::query()->with('server')->whereKey($id)->first();
            if ($cache && in_array((string) $cache->server_id, $serverIds, true)) {
                return $this->cacheEnv($cache, $site);
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnv(ServerDatabase $db, Site $site): array
    {
        if ($db->engine === 'sqlite') {
            return array_filter([
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => (string) $db->host,
                'DATABASE_URL' => (string) $db->connectionUrl(),
            ], fn ($v) => $v !== '');
        }

        $connection = match ($db->engine) {
            'postgres' => 'pgsql',
            'mysql' => 'mysql',
            default => (string) $db->engine,
        };

        return array_filter([
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $this->resolveHost($db->server, (string) $db->host, $site),
            'DB_PORT' => (string) $db->defaultPort(),
            'DB_DATABASE' => (string) $db->name,
            'DB_USERNAME' => (string) $db->username,
            'DB_PASSWORD' => (string) $db->password,
            'DATABASE_URL' => (string) $db->connectionUrl(),
        ], fn ($v) => $v !== '');
    }

    /**
     * @return array<string, string>
     */
    private function cacheEnv(ServerCacheService $cache, Site $site): array
    {
        $sameServer = (string) $cache->server_id === (string) $site->server_id;
        $host = $sameServer ? '127.0.0.1' : $this->serverIp($cache->server);

        $env = array_filter([
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => $host,
            'REDIS_PORT' => (string) ($cache->port ?: 6379),
        ], fn ($v) => $v !== '');

        if (filled($cache->auth_password)) {
            $env['REDIS_PASSWORD'] = (string) $cache->auth_password;
        }

        return $env;
    }

    /**
     * A DB on the site's own server is reached at its stored host (usually
     * 127.0.0.1); on another server, prefer the stored host but fall back to
     * that server's IP so the connection actually resolves cross-box.
     */
    private function resolveHost(?Server $server, string $storedHost, Site $site): string
    {
        if ($server !== null && (string) $server->id !== (string) $site->server_id) {
            if ($storedHost === '' || $storedHost === '127.0.0.1' || $storedHost === 'localhost') {
                return $this->serverIp($server) ?: $storedHost;
            }
        }

        return $storedHost !== '' ? $storedHost : '127.0.0.1';
    }

    private function serverIp(?Server $server): string
    {
        if ($server === null) {
            return '';
        }

        return (string) ($server->public_ip ?? $server->ip_address ?? '');
    }

    /**
     * Server ids the operator may import from: the site's org plus any servers
     * they personally own.
     *
     * @return list<string>
     */
    private function accessibleServerIds(Site $site): array
    {
        return Server::query()
            ->where(function ($q) use ($site): void {
                if ($site->organization_id) {
                    $q->where('organization_id', $site->organization_id);
                }
                $q->orWhere('user_id', auth()->id());
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
