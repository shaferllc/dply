<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesSiteApplyState;
use App\Models\Site;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tear down a single named systemd unit for a site.
 *
 * Used when a SiteProcess row is removed from the dashboard — rather
 * than wait for the next deploy to re-converge state, we immediately
 * disable + delete the matching unit file. The site keeps running;
 * only the just-removed worker / scheduler / custom process goes away.
 *
 * Site-level teardown (when a Site is deleted) goes through the
 * existing CleanupRemoteSiteArtifactsJob's `systemd_unit_names` path
 * — that handles the bulk case. This job is the per-unit equivalent.
 */
class TearDownSiteSystemdUnitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesSiteApplyState;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $siteId,
        public string $unitName,
    ) {}

    protected function applyKind(): string
    {
        return 'systemd';
    }

    public function handle(SiteSystemdProvisioner $provisioner): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null) {
            return;
        }

        $runId = $this->beginApplyRun($site);

        try {
            $provisioner->teardownUnit($site, $this->unitName);
            $this->cacheApplyOutput($runId, 'Tore down unit: '.$this->unitName);
            $this->completeApplyRun($site);
            Log::info('Tore down site systemd unit', [
                'site_id' => $site->id,
                'unit' => $this->unitName,
            ]);
        } catch (\Throwable $e) {
            // Best-effort cleanup. Banner shows failed; the next deploy or
            // site-delete cleanup will re-attempt the same unit.
            $this->cacheApplyOutput($runId, $e->getMessage());
            $this->failApplyRun($site, $e->getMessage());

            Log::warning('TearDownSiteSystemdUnitJob failed', [
                'site_id' => $site->id,
                'unit' => $this->unitName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
