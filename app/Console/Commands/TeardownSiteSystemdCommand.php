<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\SiteSystemdProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Wipe all systemd units for a site without deleting the site itself.
 *
 *   dply:site:teardown-systemd <site> [--json]
 *
 * Mirror of {@see RedeploySiteSystemdCommand}. Two scenarios:
 *
 *   1. Recovery — drop and re-converge: pair this with `dply:site:
 *      redeploy-systemd <site>` to rewrite all unit files clean.
 *      Useful when the previous unit state is suspect.
 *
 *   2. Migration — about to flip a site from one runtime to another
 *      (e.g. node → static), and want the old units gone before the
 *      new deploy converges. The CleanupRemoteSiteArtifactsJob handles
 *      this on actual delete; this command is the same flow without
 *      the row delete.
 *
 * Like the redeploy variant: synchronous (SSH errors land in the
 * operator's terminal), skips PHP/static with a friendly message
 * rather than treating it as an error.
 */
class TeardownSiteSystemdCommand extends Command
{
    protected $signature = 'dply:site:teardown-systemd
        {site : Site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'Wipe a site\'s systemd units without deleting the site (recovery / migration prep).';

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
                    'Skipped — %s sites have no systemd units to tear down.',
                    $runtime ?? 'unknown',
                ));
            }

            return self::SUCCESS;
        }

        try {
            $removed = $provisioner->teardown($site);
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
                'units' => $removed,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($removed === []) {
            $this->info('Nothing to remove — no candidate units for this site.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Tore down %d %s on %s:',
            count($removed),
            count($removed) === 1 ? 'unit' : 'units',
            $site->name,
        ));
        foreach ($removed as $unit) {
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
