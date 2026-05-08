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
 * Install (or refresh) the systemd units for a site.
 *
 * Mirror of the teardown half (CleanupRemoteSiteArtifactsJob's
 * systemd_unit_names branch). Dispatched once a site has been marked
 * active by SiteProvisioner — at that point NGINX is configured to
 * proxy to the site's internal_port, so the long-running runtime
 * service has to be up for traffic to reach anything.
 *
 * Skips when:
 *   - the site can't be found (deleted between dispatch and run);
 *   - the runtime is PHP or static (FPM is implicit, static is
 *     served directly by NGINX);
 *   - the site has no start_command (URL-first detection didn't
 *     produce one and the user didn't fill it in by hand).
 *
 * Failures are logged and surfaced via the site-apply banner; NGINX
 * will return 502 while the unit is absent until the next save / deploy.
 */
class ProvisionSiteSystemdUnitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesSiteApplyState;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $siteId) {}

    protected function applyKind(): string
    {
        return 'systemd';
    }

    public function handle(SiteSystemdProvisioner $provisioner): void
    {
        $site = Site::query()->with('server', 'processes')->find($this->siteId);
        if ($site === null) {
            return;
        }

        $runtime = $site->runtimeKey();
        if ($runtime === 'php' || $runtime === 'static' || $runtime === null) {
            return;
        }

        if (trim((string) $site->start_command) === '') {
            return;
        }

        $runId = $this->beginApplyRun($site);

        try {
            $written = $provisioner->provision($site);
            $this->cacheApplyOutput($runId, 'Provisioned units: '.implode(', ', (array) $written));
            $this->completeApplyRun($site);
            Log::info('Provisioned site systemd units', [
                'site_id' => $site->id,
                'units' => $written,
            ]);
        } catch (\Throwable $e) {
            $this->cacheApplyOutput($runId, $e->getMessage());
            $this->failApplyRun($site, $e->getMessage());

            Log::warning('ProvisionSiteSystemdUnitsJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
