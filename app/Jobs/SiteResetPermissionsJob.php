<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesSiteApplyState;
use App\Models\Site;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SiteResetPermissionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesSiteApplyState;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public string $siteId,
    ) {}

    public function uniqueId(): string
    {
        return 'site-reset-permissions:'.$this->siteId;
    }

    protected function applyKind(): string
    {
        return 'permissions';
    }

    public function handle(ServerSystemUserService $service): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $runId = $this->beginApplyRun($site);

        try {
            $service->resetSiteFilePermissions($site->fresh());
            $this->completeApplyRun($site);
        } catch (\Throwable $e) {
            $this->cacheApplyOutput($runId, $e->getMessage());
            $this->failApplyRun(
                $site,
                $e->getMessage(),
                extraMeta: ['system_user_operation' => [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'at' => now()->toIso8601String(),
                ]],
            );

            Log::warning('SiteResetPermissionsJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
