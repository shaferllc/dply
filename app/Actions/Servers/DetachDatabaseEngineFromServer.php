<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Unregister a database engine from a server.
 *
 * Refuses when any site on the server still has its database_engine
 * column pinned to the engine being removed — the user would
 * silently lose routing to those sites' database. The caller's UI
 * should surface the conflicting sites so the user can re-pin them
 * before retrying.
 *
 * When the removed engine was the default, promotes another engine
 * (alphabetical first) to default so the server always has one
 * default while at least one engine is registered. Returns null
 * when no engines remain.
 *
 * Like {@see AttachDatabaseEngineToServer}, this is data-only — it
 * doesn't apt-remove the engine package. Operators do the package
 * removal separately when convenient; the data update happens
 * immediately so site-create stops offering the engine.
 */
class DetachDatabaseEngineFromServer
{
    /**
     * @return array{ok: bool, sites_using_engine?: list<string>}
     */
    public function execute(Server $server, string $engine): array
    {
        $engine = trim($engine);

        $row = ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->where('engine', $engine)
            ->first();

        if ($row === null) {
            return ['ok' => true];
        }

        $sitesUsing = Site::query()
            ->where('server_id', $server->id)
            ->where('database_engine', $engine)
            ->pluck('name')
            ->all();

        if ($sitesUsing !== []) {
            return [
                'ok' => false,
                'sites_using_engine' => $sitesUsing,
            ];
        }

        DB::transaction(function () use ($server, $row) {
            $wasDefault = (bool) $row->is_default;
            $row->delete();

            if ($wasDefault) {
                $next = ServerDatabaseEngine::query()
                    ->where('server_id', $server->id)
                    ->orderBy('engine')
                    ->first();
                if ($next !== null) {
                    $next->update(['is_default' => true]);
                }
            }
        });

        return ['ok' => true];
    }
}
