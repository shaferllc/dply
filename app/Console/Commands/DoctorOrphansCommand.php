<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDomain;
use App\Models\SiteProcess;
use Illuminate\Console\Command;

/**
 * Find orphaned rows referencing missing parents.
 *
 *   dply:doctor:orphans
 *   dply:doctor:orphans --json
 *   dply:doctor:orphans --prune --force        # actually delete them
 *
 * Checks each child table's parent FK and reports rows whose parent
 * is gone. Read-only by default; --prune deletes them but requires
 * --force as a safety belt because deletion is destructive.
 *
 * Useful after manual DB cleanup, after a botched failed migration,
 * or when investigating "why is this row sticking around?". Not run
 * in normal operation — orphans are usually rare.
 *
 * Exit code mirrors presence of orphans: 0 when clean, 1 when any
 * orphans found (so CI can fail fast).
 */
class DoctorOrphansCommand extends Command
{
    protected $signature = 'dply:doctor:orphans
        {--prune : Delete the orphaned rows (requires --force)}
        {--force : Required to actually prune}
        {--json : Output as JSON}';

    protected $description = 'Find rows referencing missing parents (orphans).';

    public function handle(): int
    {
        $report = [
            'site_deployments' => $this->orphanIdsByForeign(SiteDeployment::class, 'site_id', Site::class),
            'site_domains' => $this->orphanIdsByForeign(SiteDomain::class, 'site_id', Site::class),
            'site_processes' => $this->orphanIdsByForeign(SiteProcess::class, 'site_id', Site::class),
            'server_database_engines' => $this->orphanIdsByForeign(ServerDatabaseEngine::class, 'server_id', Server::class),
            'sites_without_server' => $this->orphanIdsByForeign(Site::class, 'server_id', Server::class),
        ];

        $totalOrphans = array_sum(array_map('count', $report));
        $prune = (bool) $this->option('prune');
        $force = (bool) $this->option('force');
        $deleted = 0;

        if ($prune) {
            if (! $force) {
                $this->error('--prune requires --force.');

                return self::FAILURE;
            }
            $deleted = $this->prune($report);
        }

        $payload = [
            'pruned' => $prune,
            'deleted' => $deleted,
            'total_orphans' => $totalOrphans,
            'orphans' => $report,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return $totalOrphans === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($payload);

        return $totalOrphans === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  class-string  $childClass
     * @param  class-string  $parentClass
     * @return array<int, mixed>
     */
    private function orphanIdsByForeign(string $childClass, string $foreignKey, string $parentClass): array
    {
        $existingParentIds = $parentClass::query()->pluck('id')->all();

        return $childClass::query()
            ->whereNotNull($foreignKey)
            ->whereNotIn($foreignKey, $existingParentIds)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<string, array<int, mixed>>  $report
     */
    private function prune(array $report): int
    {
        $count = 0;
        $count += $this->deleteByIds(SiteDeployment::class, $report['site_deployments']);
        $count += $this->deleteByIds(SiteDomain::class, $report['site_domains']);
        $count += $this->deleteByIds(SiteProcess::class, $report['site_processes']);
        $count += $this->deleteByIds(ServerDatabaseEngine::class, $report['server_database_engines']);
        $count += $this->deleteByIds(Site::class, $report['sites_without_server']);

        return $count;
    }

    /**
     * @param  class-string  $class
     * @param  array<int, mixed>  $ids
     */
    private function deleteByIds(string $class, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $class::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function renderHuman(array $p): void
    {
        $total = (int) $p['total_orphans'];
        if ($total === 0) {
            $this->info('No orphans detected.');

            return;
        }

        $this->warn(sprintf('%d orphan(s) detected:', $total));
        foreach ($p['orphans'] as $table => $ids) {
            if (! is_array($ids) || $ids === []) {
                continue;
            }
            $this->line(sprintf('  %-30s %d', $table, count($ids)));
        }

        if ($p['pruned']) {
            $this->newLine();
            $this->info(sprintf('Pruned %d row(s).', $p['deleted']));
        } else {
            $this->newLine();
            $this->line('<fg=gray>Run with --prune --force to delete.</>');
        }
    }
}
