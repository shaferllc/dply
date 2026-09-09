<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\EnsuresDefaultUptimeMonitors;
use Illuminate\Console\Command;

/**
 * Retargets the legacy single "Homepage check" to HTTPS, adds the HTTP
 * sibling, and stores the host-nearest probe region on existing sites.
 */
class BackfillDefaultUptimeMonitorsCommand extends Command
{
    protected $signature = 'dply:uptime:backfill-defaults';

    protected $description = 'Backfill the default HTTP + HTTPS homepage uptime monitors and host-nearest probe region.';

    public function handle(EnsuresDefaultUptimeMonitors $seeder): int
    {
        $created = 0;
        $sites = 0;

        Site::query()
            ->with(['server', 'uptimeMonitors'])
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($seeder, &$created, &$sites): void {
                foreach ($chunk as $site) {
                    $fresh = $seeder->ensure($site);
                    $created += count($fresh);
                    $sites++;
                }
            });

        $this->components->info("Backfilled default uptime monitors for {$sites} site(s); created {$created} monitor(s).");

        return self::SUCCESS;
    }
}
