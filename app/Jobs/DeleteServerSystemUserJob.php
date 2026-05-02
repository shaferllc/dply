<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteServerSystemUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $serverId,
        public string $username,
    ) {}

    public function handle(ServerSystemUserService $service): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }

        try {
            $service->deleteUserFromServer($server, $this->username);
        } catch (\Throwable $e) {
            Log::warning('DeleteServerSystemUserJob failed', [
                'server_id' => $server->id,
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
