<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public string $siteId,
        public int $probeAttempt = 0,
    ) {}

    public function handle(SiteProvisioner $siteProvisioner): void
    {
        $site = Site::query()->with(['server', 'domains'])->find($this->siteId);
        if (! $site) {
            return;
        }

        if ($site->provisioningState() === 'ready') {
            return;
        }

        try {
            $siteProvisioner->appendLog($site, 'info', 'queued', 'Provisioning job picked up by worker.', [
                'probe_attempt' => $this->probeAttempt,
            ]);

            if ($this->probeAttempt === 0) {
                $siteProvisioner->begin($site);
                $site->refresh();
            }

            $result = $siteProvisioner->checkReadiness($site);
            if ($result['ok']) {
                return;
            }

            if ($this->probeAttempt >= 9) {
                $siteProvisioner->markTimedOut($site, 'The site configuration was written, but no testing or primary domain responded before the retry limit was reached.');

                return;
            }

            $siteProvisioner->appendLog($site, 'info', 'waiting_for_http', 'Scheduling another reachability check.', [
                'next_probe_attempt' => $this->probeAttempt + 1,
                'delay_seconds' => 15,
            ]);

            static::dispatch($site->id, $this->probeAttempt + 1)
                ->delay(now()->addSeconds(15));
        } catch (\Throwable $e) {
            $siteProvisioner->markFailed($site, $e);
            Log::warning('ProvisionSiteJob failed', [
                'site_id' => $site->id,
                'probe_attempt' => $this->probeAttempt,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
