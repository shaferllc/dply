<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerProvisionCommandBuilder;
use App\Support\Servers\CacheServiceNetworkExposure;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Mirror the cache + database engines declared in a server's provision
 * meta into the workspace tables (`server_cache_services`,
 * `server_database_engines`) so the Caches and Databases workspace pages
 * have a row to render after provisioning finishes.
 *
 * Why this exists: {@see ServerProvisionCommandBuilder}
 * installs the chosen apt packages on the host (redis-server, postgresql-18,
 * etc.) and writes their configs, but the post-provision hook
 * ({@see RunSetupScriptJob::applyProvisionOutcomeToServer()})
 * historically only seeded firewall / systemd / system-user state — never
 * the engine rows. Operators landed on an empty workspace even though the
 * engine was running on the host. This action plugs that gap; it's invoked
 * both from the forward post-provision hook (new servers) and the
 * `dply:servers:backfill-engine-rows` command (already-provisioned servers).
 *
 * Idempotency: every insert is guarded by an existence check on the natural
 * key — (server_id, engine) for both tables. Re-running is a no-op for rows
 * that already exist; the action never mutates an existing row, so manual
 * UI installs are preserved as-is.
 */
class SeedProvisionedEnginesForServer
{
    /**
     * @return array{cache_created: bool, database_created: bool} What was newly inserted.
     */
    public function execute(Server $server): array
    {
        $meta = $server->meta ?? [];

        return DB::transaction(function () use ($server, $meta): array {
            $cacheCreated = $this->seedCacheService($server, (string) ($meta['cache_service'] ?? ''), $meta);
            $dbCreated = $this->seedDatabaseEngine($server, (string) ($meta['database'] ?? ''));

            return [
                'cache_created' => $cacheCreated,
                'database_created' => $dbCreated,
            ];
        });
    }

    private function seedCacheService(Server $server, string $engine, array $meta): bool
    {
        $engine = trim($engine);
        if ($engine === '' || $engine === 'none' || ! in_array($engine, ServerCacheService::ENGINES, true)) {
            return false;
        }

        $exists = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->where('engine', $engine)
            ->exists();

        if ($exists) {
            return false;
        }

        $cacheServerMeta = is_array($meta['cache_server'] ?? null) ? $meta['cache_server'] : [];
        $authPassword = $this->resolveCacheAuthPassword($cacheServerMeta);

        $row = ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => $engine,
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => ServerCacheService::defaultPortFor($engine),
            'auth_password' => $authPassword,
        ]);

        $this->seedCacheFirewallRuleFromMeta($server, $row, $cacheServerMeta);

        return true;
    }

    /**
     * @param  array<string, mixed>  $cacheServerMeta
     */
    private function resolveCacheAuthPassword(array $cacheServerMeta): ?string
    {
        if (! ($cacheServerMeta['require_password'] ?? false)) {
            return null;
        }

        $encrypted = $cacheServerMeta['password_encrypted'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $cacheServerMeta
     */
    private function seedCacheFirewallRuleFromMeta(Server $server, ServerCacheService $row, array $cacheServerMeta): void
    {
        if (! ($cacheServerMeta['remote_access'] ?? false)) {
            return;
        }

        if (! DedicatedCacheServerProvisionConfig::engineSupportsRemoteAccess($row->engine)) {
            return;
        }

        $source = trim((string) ($cacheServerMeta['allowed_from'] ?? ''));
        if (! DedicatedCacheServerProvisionConfig::isAllowedSourceCidr($source)) {
            return;
        }

        $tag = CacheServiceNetworkExposure::firewallRuleTag($row);
        $exists = ServerFirewallRule::query()
            ->where('server_id', $server->id)
            ->whereJsonContains('tags', $tag)
            ->exists();

        if ($exists) {
            return;
        }

        ServerFirewallRule::query()->create([
            'server_id' => $server->id,
            'name' => sprintf('Cache · %s (%s)', ucfirst($row->engine), $row->name),
            'port' => (int) $row->port,
            'protocol' => 'tcp',
            'source' => $source,
            'action' => 'allow',
            'enabled' => true,
            'sort_order' => (int) (ServerFirewallRule::query()->where('server_id', $server->id)->max('sort_order') ?? 0) + 1,
            'tags' => ['dply-cache', $tag],
        ]);
    }

    private function seedDatabaseEngine(Server $server, string $engine): bool
    {
        $engine = trim($engine);
        if ($engine === '' || $engine === 'none') {
            return false;
        }

        $exists = ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->where('engine', $engine)
            ->exists();

        if ($exists) {
            return false;
        }

        // First engine on this server becomes the default — matches what
        // AttachDatabaseEngineToServer does, kept inline here so the cache
        // and database seeds share one transaction.
        $isFirstEngine = ! ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->exists();

        ServerDatabaseEngine::query()->create([
            'server_id' => $server->id,
            'engine' => $engine,
            'is_default' => $isFirstEngine,
            'status' => ServerDatabaseEngine::STATUS_RUNNING,
            'port' => ServerDatabaseEngine::defaultPortFor($engine),
        ]);

        return true;
    }
}
