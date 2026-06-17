<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
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
 * Assigns an *existing* server-side Linux user as the file owner / FPM pool
 * user for a single site. Site-scoped — produces a console_actions row whose
 * subject is the Site so the per-site banner streams progress.
 *
 * Creating the underlying account is a separate, server-level concern and
 * lives in {@see CreateServerSystemUserJob}; this job assumes the username
 * already exists on the host (the picker only shows users that are present
 * in /etc/passwd).
 */
class AssignSystemUserToSiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $siteId,
        public string $username,
        public ?string $userId = null,
    ) {}

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'console-action:system_user:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'system_user';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(ServerSystemUserService $service): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('system_user', 'assigning '.$this->username);
            $service->assignExistingUserToSite($site->fresh(), $this->username);

            $emit->success('site files assigned to '.$this->username, 'system_user');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'system_user');
            $this->failConsoleAction($e->getMessage());

            // Side-channel for the system-user UI banner — keep behavior parity.
            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['system_user_operation'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'at' => now()->toIso8601String(),
            ];
            $site->update(['meta' => $meta]);

            Log::warning('AssignSystemUserToSiteJob failed', [
                'site_id' => $site->id,
                'username' => $this->username,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
