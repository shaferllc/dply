<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Console;

use App\Jobs\ProvisionSiteSystemdUnitsJob;

use App\Models\Site;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-provision the systemd units for a site.
 *
 *   dply:site:redeploy-systemd <site> [--json]
 *
 * Ops escape hatch when a site's units have drifted from what the
 * dply data model says they should be — e.g. a manual edit of
 * /etc/systemd/system, a server reboot that lost some state, a unit
 * that failed to install during ProvisionSiteSystemdUnitsJob and the
 * job already retired.
 *
 * Runs synchronously (not via the queue) so the operator sees the
 * SSH error inline if anything's wrong. The site keeps running on
 * NGINX in the meantime — re-converging units doesn't touch the
 * web upstream config.
 *
 * Skips PHP and static sites (FPM is implicit, static has no units)
 * with a friendly message rather than treating it as an error.
 */
class RedeploySiteSystemdCommand extends Command
{
    protected $signature = 'dply:site:redeploy-systemd
        {site : Site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'Re-provision the systemd units for a site (recovery / forced re-converge).';

    public function handle(SiteSystemdProvisioner $provisioner): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $runtime = $site->runtimeKey();
        if ($runtime === 'php' || $runtime === 'static' || $runtime === null) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'runtime-has-no-systemd-units',
                    'runtime' => $runtime,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->info(sprintf(
                    'Skipped — %s sites have no systemd units to converge.',
                    $runtime ?? 'unknown',
                ));
            }

            return self::SUCCESS;
        }

        try {
            $written = $provisioner->provision($site);
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'site_id' => $site->id,
                'units' => $written,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($written === []) {
            $this->warn('No units provisioned — site may have no start_command set yet.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Re-provisioned %d %s on %s:',
            count($written),
            count($written) === 1 ? 'unit' : 'units',
            $site->name,
        ));
        foreach ($written as $unit) {
            $this->line('  • '.$unit);
        }

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
