<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\DeployIntelligence\Scanner;
use Illuminate\Console\Command;

/**
 * Runs the deploy-intelligence scanner against every organization
 * (or one specifically). Opens new alerts, refreshes still-observed
 * ones, and resolves alerts whose conditions have cleared. Newly
 * opened alerts fan out to org notification channels.
 *
 *   dply:deploy-intelligence:scan [--org=<id>]
 */
class DeployIntelligenceScanCommand extends Command
{
    protected $signature = 'dply:deploy-intelligence:scan
        {--org= : Scan only a single organization ID}';

    protected $description = 'Scan every org for deploy intelligence findings (slow builds, expiring TLS, env drift).';

    public function handle(Scanner $scanner): int
    {
        $query = Organization::query();
        if ($orgId = $this->option('org')) {
            $query->whereKey($orgId);
        }
        $orgs = $query->get();

        if ($orgs->isEmpty()) {
            $this->components->warn('No organizations matched.');

            return self::SUCCESS;
        }

        $totals = ['opened' => 0, 'refreshed' => 0, 'resolved' => 0];
        foreach ($orgs as $org) {
            $result = $scanner->scan($org);
            $totals['opened'] += $result['opened'];
            $totals['refreshed'] += $result['refreshed'];
            $totals['resolved'] += $result['resolved'];

            $this->components->info(sprintf(
                '%s · %d opened, %d refreshed, %d resolved',
                $org->name,
                $result['opened'],
                $result['refreshed'],
                $result['resolved'],
            ));
        }

        $this->components->info(sprintf(
            'Done. %d opened, %d refreshed, %d resolved across %d organizations.',
            $totals['opened'],
            $totals['refreshed'],
            $totals['resolved'],
            $orgs->count(),
        ));

        return self::SUCCESS;
    }
}
