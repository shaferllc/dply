<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Sites\SiteNginxProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InstallSiteNginxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Site $site
    ) {}

    public function handle(SiteNginxProvisioner $provisioner): void
    {
        $this->site = $this->site->fresh();
        if (! $this->site) {
            return;
        }

        try {
            $provisioner->provision($this->site);
        } catch (\Throwable $e) {
            $this->site->update([
                'status' => Site::STATUS_ERROR,
                'meta' => array_merge($this->site->meta ?? [], ['nginx_error' => $e->getMessage()]),
            ]);
            Log::warning('InstallSiteNginxJob failed', ['site_id' => $this->site->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
