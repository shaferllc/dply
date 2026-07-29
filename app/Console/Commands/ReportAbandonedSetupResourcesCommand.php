<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteBinding;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Surfaces REAL resources (databases, buckets) that were provisioned while a
 * site was in its first-deploy setup, where that setup was then abandoned — the
 * site still exists but never reached a deploy. Those resources are orphans
 * with no running app behind them.
 *
 * Deliberately READ-ONLY. Tearing the resource down is a separate, explicit,
 * destructive action: managed databases hold real data and are UNLINKED, never
 * auto-dropped (see {@see \App\Support\Sites\SiteRelationPurger}). This command
 * only reports candidates for an operator to review.
 *
 * Provenance is stamped by {@see \App\Modules\Deploy\Services\SiteBindingManager::stampSetupProvenance}
 * (config.provisioned_during_setup + _at).
 */
class ReportAbandonedSetupResourcesCommand extends Command
{
    protected $signature = 'dply:report-abandoned-setup-resources {--days=2 : Only report resources provisioned at least this many days ago}';

    protected $description = 'Report resources provisioned during a site setup that was then abandoned (site never deployed). Read-only.';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(0, (int) $this->option('days')));

        $rows = [];

        SiteBinding::query()
            ->with('site')
            ->whereIn('type', ['database', 'storage'])
            ->orderBy('id')
            ->chunkById(200, function ($bindings) use (&$rows, $cutoff): void {
                foreach ($bindings as $binding) {
                    $cfg = $binding->config;
                    if (($cfg['provisioned_during_setup'] ?? false) !== true) {
                        continue;
                    }

                    $site = $binding->site;
                    // Deleted site → the orphaned-data purger handles those rows.
                    // Setup finished or a deploy ever ran → not abandoned, keep.
                    if ($site === null || ! $site->isInFirstDeploySetup() || filled($site->last_deploy_at)) {
                        continue;
                    }

                    $at = $cfg['provisioned_during_setup_at'] ?? null;
                    $provisionedAt = $at !== null ? Carbon::parse((string) $at) : $binding->created_at;
                    if ($provisionedAt->greaterThan($cutoff)) {
                        continue; // still within the grace window
                    }

                    $rows[] = [
                        $site->name ?: (string) $site->id,
                        $binding->type,
                        $binding->name ?: '—',
                        $site->firstDeploySetupState() ?: '—',
                        $provisionedAt->diffForHumans(),
                    ];
                }
            });

        if ($rows === []) {
            $this->info('No abandoned setup resources found.');

            return self::SUCCESS;
        }

        $this->warn(count($rows).' resource(s) provisioned during an abandoned setup:');
        $this->table(['Site', 'Type', 'Name', 'Setup state', 'Provisioned'], $rows);
        $this->line('Review and tear down manually — this command never drops infrastructure.');

        return self::SUCCESS;
    }
}
