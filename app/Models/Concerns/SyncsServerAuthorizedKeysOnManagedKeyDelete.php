<?php

namespace App\Models\Concerns;

use App\Jobs\SyncServerAuthorizedKeysJob;

trait SyncsServerAuthorizedKeysOnManagedKeyDelete
{
    protected static function bootSyncsServerAuthorizedKeysOnManagedKeyDelete(): void
    {
        static::deleting(function (self $key): void {
            $serverIds = $key->serverAuthorizedKeys()->pluck('server_id')->unique()->values()->all();
            $key->serverAuthorizedKeys()->delete();
            foreach ($serverIds as $serverId) {
                SyncServerAuthorizedKeysJob::dispatch($serverId);
            }
        });
    }
}
