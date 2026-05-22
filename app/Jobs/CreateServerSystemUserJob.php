<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
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

/**
 * Server-scoped Linux account creation. Triggered from the /servers/{server}/system-users
 * page; produces a console_actions row whose subject is the Server so the page-top
 * banner shows the run live. No site assignment happens here — that's a separate
 * per-site step ({@see AssignSystemUserToSiteJob}).
 */
class CreateServerSystemUserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<string>  $extraGroups  supplementary groups appended to `-G` (e.g. www-data)
     */
    public function __construct(
        public string $serverId,
        public string $username,
        public bool $grantSudo = false,
        public ?string $userId = null,
        public string $shell = '/bin/bash',
        public array $extraGroups = [],
    ) {}

    public function uniqueId(): string
    {
        return 'console-action:system_user:server:'.$this->serverId;
    }

    protected function consoleSubject(): Model
    {
        return Server::query()->findOrFail($this->serverId);
    }

    protected function consoleKind(): string
    {
        return 'system_user';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(ServerSystemUserService $service, ServerPasswdUserLister $lister): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('system_user', 'creating '.$this->username);
            $service->createUser($server, $this->username, $this->grantSudo, $this->shell, $this->extraGroups);

            // Refresh the persisted snapshot so the workspace table picks up the
            // new account (with the UID/groups useradd actually assigned) without
            // requiring the operator to click "Sync now" themselves.
            $emit->step('system_user', 'syncing user list to dply');
            $service->listPasswdUsersWithSiteCounts($server, $lister);

            $emit->success('system user '.$this->username.' created', 'system_user');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'system_user');
            $this->failConsoleAction($e->getMessage());

            Log::warning('CreateServerSystemUserJob failed', [
                'server_id' => $server->id,
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
