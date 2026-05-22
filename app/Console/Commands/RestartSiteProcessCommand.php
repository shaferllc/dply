<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restart a single SiteProcess via systemctl.
 *
 *   dply:site:restart-process <site> <process> [--json]
 *
 * Useful when an operator changes worker config or env and wants the
 * existing systemd unit to pick it up without rolling the rest of
 * the site (which a full deploy would). The unit file content
 * doesn't change — just `systemctl restart <unit>`.
 *
 * Refuses to touch web (PHP-FPM is graceful-reload, not restart, and
 * it's owned by the deploy pipeline). Refuses for PHP/static sites
 * that have no systemd units.
 */
class RestartSiteProcessCommand extends Command
{
    protected $signature = 'dply:site:restart-process
        {site : Site ID, slug, or name}
        {process : SiteProcess name (e.g. sidekiq, celery, worker)}
        {--json : Output the result as JSON}';

    protected $description = 'Restart a single SiteProcess via systemctl.';

    public function handle(SiteSystemdProvisioner $provisioner, SiteSystemdUnitBuilder $builder): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $runtime = $site->runtimeKey();
        if ($runtime === 'php' || $runtime === 'static' || $runtime === null) {
            $this->error('Cannot restart a process on a '.($runtime ?? 'unknown').' site — no systemd units to act on.');

            return self::FAILURE;
        }

        $processName = (string) $this->argument('process');
        if ($processName === 'web') {
            $this->error('The web process is reload-only on PHP and managed by the deploy pipeline on other runtimes; use dply:site:run-phase <site> restart instead.');

            return self::FAILURE;
        }

        $process = $site->processes()->where('name', $processName)->first();
        if ($process === null) {
            $this->error("Process '{$processName}' not found on site '{$site->name}'.");

            return self::FAILURE;
        }

        $unitName = $builder->processUnitName($site, $process);

        try {
            $output = $provisioner->restartUnit($site, $unitName);
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'site_id' => $site->id,
                    'process' => $processName,
                    'unit' => $unitName,
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
                'process' => $processName,
                'unit' => $unitName,
                'output' => $output,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Restarted {$unitName} on {$site->name}.");
        if (trim($output) !== '') {
            $this->newLine();
            $this->line($output);
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
