<?php

declare(strict_types=1);

namespace App\Events\Servers;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class ServerCronRunCompletedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $serverId,
        public readonly string $runId,
        public readonly bool $success,
        public readonly ?string $error,
        public readonly ?string $flashSuccess,
        public readonly ?string $finalOutput,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('server.'.$this->serverId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'server.cron.run.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'run_id' => $this->runId,
            'success' => $this->success,
            'error' => $this->error,
            'flash_success' => $this->flashSuccess,
            'final_output' => $this->finalOutput,
        ];
    }
}
