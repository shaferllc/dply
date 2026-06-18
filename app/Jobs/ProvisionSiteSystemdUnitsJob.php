<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
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
 * Failures surface in the site's console-actions banner.
 */
class ProvisionSiteSystemdUnitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
    ) {}

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'systemd';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteSystemdProvisioner $provisioner): void
    {
        $site = Site::query()->with('server', 'processes')->find($this->siteId);
        if ($site === null) {
            return;
        }

        // A php/static site's WEB tier needs no systemd unit (FPM / static is
        // served by the webserver). BUT its WORKER processes (Horizon,
        // queue:work, scheduler) still need units — previously we returned here
        // and they never got provisioned, so Horizon never started on deploy.
        // Provision whenever the site has an active non-web worker process, OR a
        // non-php/static runtime with a web start command.
        $site->loadMissing('processes');
        $hasWorkerProcess = $site->processes->contains(
            fn (SiteProcess $p): bool => $p->type !== SiteProcess::TYPE_WEB
                && (bool) $p->is_active
                && trim((string) $p->command) !== ''
        );
        $runtime = $site->runtimeKey();
        $webNeedsUnit = ! in_array($runtime, ['php', 'static', null], true)
            && trim((string) $site->start_command) !== '';

        if (! $hasWorkerProcess && ! $webNeedsUnit) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('systemd', 'writing units');
            $written = $provisioner->provision($site);
            $emit->success('provisioned: '.implode(', ', (array) $written), 'systemd');
            $this->completeConsoleAction();
            Log::info('Provisioned site systemd units', [
                'site_id' => $site->id,
                'units' => $written,
            ]);
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'systemd');
            $this->failConsoleAction($e->getMessage());

            Log::warning('ProvisionSiteSystemdUnitsJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
