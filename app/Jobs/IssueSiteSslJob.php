<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Sites\SiteSslProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IssueSiteSslJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Site $site,
        public ?string $email = null
    ) {}

    public function handle(SiteSslProvisioner $provisioner): void
    {
        $this->site = $this->site->fresh();
        if (! $this->site) {
            return;
        }

        try {
            $provisioner->provision($this->site, $this->email);
        } catch (\Throwable $e) {
            Log::warning('IssueSiteSslJob failed', ['site_id' => $this->site->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
