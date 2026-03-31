<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncServerAuthorizedKeysJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public string $serverId
    ) {}

    public function handle(ServerAuthorizedKeysSynchronizer $synchronizer): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server || ! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        $synchronizer->sync($server, null, null);
    }
}
