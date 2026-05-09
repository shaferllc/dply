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

class SiteResetPermissionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'console-action:permissions:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'permissions';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(ServerSystemUserService $service): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('permissions', 'resetting site file permissions');
            $service->resetSiteFilePermissions($site->fresh());
            $emit->success('permissions reset', 'permissions');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'permissions');
            $this->failConsoleAction($e->getMessage());

            // Old job stamped a `system_user_operation` site.meta entry on
            // failure as a side-channel for the system-user UI. Preserve that.
            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['system_user_operation'] = [
                'status' => 'error',
                'message' => $e->getMessage(),
                'at' => now()->toIso8601String(),
            ];
            $site->update(['meta' => $meta]);

            Log::warning('SiteResetPermissionsJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
