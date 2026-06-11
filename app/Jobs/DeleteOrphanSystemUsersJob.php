<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\User;
use App\Services\Notifications\ServerSystemUserNotificationDispatcher;
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
 * Bulk variant of {@see DeleteServerSystemUserJob}. Takes a precomputed list of
 * orphan usernames from the workspace page and walks them sequentially, tolerating
 * per-user failures so one wedged userdel doesn't strand the rest. Single console
 * run, single SSH connection reuse via the service layer.
 *
 * Bulk-as-one-job (rather than dispatching N individual deletes) is required
 * because {@see DeleteServerSystemUserJob} shares the same console-action unique
 * key per server — back-to-back dispatches would dedup, not serialise.
 */
class DeleteOrphanSystemUsersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<string>  $usernames  orphan candidates as seen by the dispatcher;
     *                                   the policy check at delete time is the source of truth
     */
    public function __construct(
        public string $serverId,
        public array $usernames,
        public ?string $userId = null,
    ) {}

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 900;

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

    public function handle(
        ServerSystemUserService $service,
        ServerSystemUserNotificationDispatcher $notifications,
    ): void {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }

        $emit = $this->beginConsoleAction();

        $deleted = [];
        $skipped = [];

        try {
            foreach ($this->usernames as $username) {
                $username = (string) $username;
                if ($username === '') {
                    continue;
                }

                $emit->step('system_user', 'removing '.$username);

                try {
                    $service->deleteUserFromServer($server, $username);
                    $deleted[] = $username;
                } catch (\Throwable $e) {
                    $skipped[] = $username;
                    $emit->step('system_user', 'skipped '.$username.': '.$e->getMessage());

                    Log::info('DeleteOrphanSystemUsersJob skipped user', [
                        'server_id' => $server->id,
                        'username' => $username,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }

            $summary = sprintf(
                'removed %d orphan%s%s',
                count($deleted),
                count($deleted) === 1 ? '' : 's',
                $skipped === [] ? '' : sprintf(', skipped %d (%s)', count($skipped), implode(', ', $skipped)),
            );

            $emit->success($summary, 'system_user');
            $this->completeConsoleAction();

            // Only the accounts that actually came off the box are reported; the
            // dispatcher no-ops when $deleted is empty (everything was skipped).
            $notifications->notify(
                $server,
                'removed',
                $deleted,
                $this->userId ? User::query()->find($this->userId) : null,
                ['skipped' => $skipped, 'orphan_cleanup' => true],
            );
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'system_user');
            $this->failConsoleAction($e->getMessage());

            Log::warning('DeleteOrphanSystemUsersJob failed', [
                'server_id' => $server->id,
                'usernames' => $this->usernames,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
