<?php

declare(strict_types=1);

namespace App\Events\Debug;

use App\Livewire\Debug\TaskRunnerPanel;
use App\Support\Debug\TaskRunnerBroadcastBridge;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcasts a TaskRunner / SSH / Process activity event to the org-scoped
 * Reverb channel, fed by {@see TaskRunnerBroadcastBridge}.
 *
 * The TaskRunner debug panel ({@see TaskRunnerPanel})
 * subscribes to organization.{id} (already authorised via routes/channels.php)
 * and only renders for users who pass the viewPlatformAdmin gate. Members of
 * the same org who are not platform admins still receive the websocket frames
 * but the panel UI is skipped at the layout level via @can('viewPlatformAdmin').
 */
final class TaskRunnerActivityBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $organizationId  ULID of the org this activity belongs to.
     * @param  string  $kind  task.started | task.completed | task.retrying | task.error | process.output
     * @param  array<string, mixed>  $payload  Free-form payload, see broadcastWith().
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $kind,
        public readonly array $payload,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('organization.'.$this->organizationId)];
    }

    public function broadcastAs(): string
    {
        return 'debug.task-runner.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind,
            'organization_id' => $this->organizationId,
            'payload' => $this->payload,
            'at' => now()->toISOString(),
        ];
    }
}
