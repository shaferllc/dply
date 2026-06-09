<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteProvisioningRestarter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued wrapper around {@see SiteProvisioningRestarter::restart()} so the
 * Restart-fresh action can return immediately from the Livewire request
 * instead of holding the UI hostage for 30–60s of synchronous SSH calls
 * (vhost teardown, placeholder cleanup, testing-DNS delete, etc.).
 */
class RestartSiteProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $siteId) {}

    public function handle(SiteProvisioningRestarter $restarter, SiteProvisioner $siteProvisioner): void
    {
        $site = Site::query()->with(['server', 'domains', 'previewDomains', 'certificates'])->find($this->siteId);
        if ($site === null) {
            return;
        }

        try {
            $restarter->restart($site);
        } catch (\Throwable $e) {
            Log::warning('RestartSiteProvisioningJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $siteProvisioner->markFailed($site, $e);
            throw $e;
        }
    }
}
