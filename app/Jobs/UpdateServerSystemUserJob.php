<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\User;
use App\Modules\Notifications\Services\ServerSystemUserNotificationDispatcher;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateServerSystemUserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $serverId,
        public string $username,
        public ?string $shell = null,
        public ?bool $grantSudo = null,
        public ?bool $addWebGroup = null,
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
        ServerPasswdUserLister $lister,
        ServerSystemUserNotificationDispatcher $notifications,
    ): void {
        $server = Server::find($this->serverId);
        if (! $server) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('system_user', 'updating '.$this->username);
            $service->updateUser(
                $server,
                $this->username,
                $this->shell,
                $this->grantSudo,
                $this->addWebGroup,
            );

            $emit->step('system_user', 'syncing user list to dply');
            $service->listPasswdUsersWithSiteCounts($server, $lister);

            $emit->success('system user '.$this->username.' updated', 'system_user');
            $this->completeConsoleAction();

            $notifications->notify(
                $server,
                'updated',
                [$this->username],
                $this->userId ? User::find($this->userId) : null,
            );
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'system_user');
            $this->failConsoleAction($e->getMessage());

            Log::warning('UpdateServerSystemUserJob failed', [
                'server_id' => $server->id,
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
