<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $siteId,
        public string $unitName,
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
        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            $emit->step('systemd', 'tearing down unit '.$this->unitName);
            $provisioner->teardownUnit($site, $this->unitName);
            $emit->success('tore down '.$this->unitName, 'systemd');
            $this->completeConsoleAction();
            Log::info('Tore down site systemd unit', [
                'site_id' => $site->id,
                'unit' => $this->unitName,
            ]);
        } catch (\Throwable $e) {
            // Best-effort cleanup. Banner shows failed; the next deploy or
            // site-delete cleanup will re-attempt the same unit.
            $emit->error($e->getMessage(), 'systemd');
            $this->failConsoleAction($e->getMessage());

            Log::warning('TearDownSiteSystemdUnitJob failed', [
                'site_id' => $site->id,
                'unit' => $this->unitName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
