<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use Illuminate\Support\Facades\DB;

/**
 * Register a database engine as installed on a server.
 *
 * Per the strategy memo: "Adding/removing DB engines is a server-
 * management action, not site-create." This is the first half — the
 * data update that makes the engine available to sites via
 * Site::databaseEngine() and the engine-picker UI.
 *
 * Important: this action does NOT install the apt package. It assumes
 * the operator has already done that (via direct SSH, a one-shot
 * provisioner playbook, or the existing ServerProvisionCommandBuilder
 * if the engine was set at server-create time). The action records
 * the engine + version + default flag in `server_database_engines`
 * so sites can target it.
 *
 * Idempotent: re-running with the same (server_id, engine) updates
 * the existing row instead of failing the unique constraint.
 *
 * Default-engine handling: when $isDefault is true, the action
 * un-flags any other is_default rows on the server inside a
 * transaction so exactly one engine carries the default at any time.
 */
class AttachDatabaseEngineToServer
{
    public function execute(
        Server $server,
        string $engine,
        ?string $version = null,
        bool $isDefault = false,
    ): ServerDatabaseEngine {
        $engine = trim($engine);
        if ($engine === '') {
            throw new \InvalidArgumentException('Engine is required.');
        }

        return DB::transaction(function () use ($server, $engine, $version, $isDefault) {
            if ($isDefault) {
                ServerDatabaseEngine::query()
                    ->where('server_id', $server->id)
                    ->where('is_default', true)
                    ->where('engine', '!=', $engine)
                    ->update(['is_default' => false]);
            }

            $existing = ServerDatabaseEngine::query()
                ->where('server_id', $server->id)
                ->where('engine', $engine)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'version' => $version,
                    'is_default' => $isDefault || $existing->is_default,
                ]);

                return $existing->refresh();
            }

            // No prior default → make this the default automatically so the
            // server always has one when at least one engine is registered.
            $isFirstEngine = ServerDatabaseEngine::query()
                ->where('server_id', $server->id)
                ->doesntExist();

            return ServerDatabaseEngine::create([
                'server_id' => $server->id,
                'engine' => $engine,
                'version' => $version,
                'is_default' => $isDefault || $isFirstEngine,
            ]);
        });
    }
}
