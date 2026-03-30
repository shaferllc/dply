<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\UserSshKeyDeploymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionDefaultUserSshKeysToServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $serverId
    ) {}

    public function handle(UserSshKeyDeploymentService $deployment): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server || ! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        $deployment->provisionDefaultsForNewServer($server);
    }
}
