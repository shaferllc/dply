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
 * Reads /etc/passwd over SSH and rewrites the server_system_users snapshot
 * for this server. Emits a console_actions run keyed to the Server so the
 * workspace banner shows progress (queued → running → completed/failed) in
 * the same surface the create / remove flows already feed.
 */
class SyncServerSystemUsersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $serverId,
        public ?string $userId = null,
    ) {}

    /**
     * Same key Create/Remove use, so the three flows serialize against each
     * other — a sync running while a create finishes is the kind of race that
     * yields a stale snapshot, and the operator only cares about the latest
     * action anyway.
     */
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

    public function handle(ServerSystemUserService $service, ServerPasswdUserLister $lister): void
    {
        $server = Server::find($this->serverId);
        if (! $server) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('system_user', 'probing /etc/passwd on '.$server->getSshConnectionString());
            $rows = $service->listPasswdUsersWithSiteCounts($server, $lister);

            $emit->success(count($rows).' system users synced', 'system_user');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'system_user');
            $this->failConsoleAction($e->getMessage());

            Log::warning('SyncServerSystemUsersJob failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
