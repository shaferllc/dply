<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerHealthProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckServerHealthJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 15;

    public function __construct(
        public Server $server
    ) {}

    public function handle(ServerHealthProbe $probe): void
    {
        $server = $this->server->fresh();
        if (! $server || $server->status !== Server::STATUS_READY || empty($server->ip_address)) {
            return;
        }

        $result = $probe->probe($server);

        $server->update([
            'last_health_check_at' => now(),
            'health_status' => $result['ok'] ? Server::HEALTH_REACHABLE : Server::HEALTH_UNREACHABLE,
        ]);
    }
}
