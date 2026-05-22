<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabaseEngine;
use Illuminate\Support\Facades\DB;

/**
 * Mirror the cache + database engines declared in a server's provision
 * meta into the workspace tables (`server_cache_services`,
 * `server_database_engines`) so the Caches and Databases workspace pages
 * have a row to render after provisioning finishes.
 *
 * Why this exists: {@see \App\Services\Servers\ServerProvisionCommandBuilder}
 * installs the chosen apt packages on the host (redis-server, postgresql-18,
 * etc.) and writes their configs, but the post-provision hook
 * ({@see \App\Jobs\RunSetupScriptJob::applyProvisionOutcomeToServer()})
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
            $cacheCreated = $this->seedCacheService($server, (string) ($meta['cache_service'] ?? ''));
            $dbCreated = $this->seedDatabaseEngine($server, (string) ($meta['database'] ?? ''));

            return [
                'cache_created' => $cacheCreated,
                'database_created' => $dbCreated,
            ];
        });
    }

    private function seedCacheService(Server $server, string $engine): bool
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

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => $engine,
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => ServerCacheService::defaultPortFor($engine),
        ]);

        return true;
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
