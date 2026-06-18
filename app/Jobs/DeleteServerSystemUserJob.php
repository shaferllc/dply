<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\User;
use App\Modules\Notifications\Services\ServerSystemUserNotificationDispatcher;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Server-scoped Linux account removal. Emits a console_actions run keyed to the
 * Server so the workspace page-top banner reflects progress and failures in the
 * same surface used by the create flow.
 */
class DeleteServerSystemUserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $serverId,
        public string $username,
        public ?string $userId = null,
    ) {}

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'console-action:system_user:server:'.$this->serverId;
    }

    protected function consoleSubject(): Model
    {
        return Server::findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'system_user';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        ServerSystemUserService $service,
        ServerSystemUserNotificationDispatcher $notifications,
    ): void {
        $server = Server::find($this->serverId);
        if (! $server) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('system_user', 'removing '.$this->username);
            $service->deleteUserFromServer($server, $this->username);

            $emit->success('system user '.$this->username.' removed', 'system_user');
            $this->completeConsoleAction();

            $notifications->notify(
                $server,
                'removed',
                [$this->username],
                $this->userId ? User::find($this->userId) : null,
            );
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'system_user');
            $this->failConsoleAction($e->getMessage());

            Log::warning('DeleteServerSystemUserJob failed', [
                'server_id' => $server->id,
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
