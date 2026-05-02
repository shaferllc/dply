<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SiteResetPermissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public string $siteId,
    ) {}

    public function handle(ServerSystemUserService $service): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        try {
            $service->resetSiteFilePermissions($site->fresh());
        } catch (\Throwable $e) {
            $this->recordFailure($site, $e->getMessage());

            Log::warning('SiteResetPermissionsJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function recordFailure(Site $site, string $message): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['system_user_operation'] = [
            'status' => 'error',
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $site->update(['meta' => $meta]);
    }
}
