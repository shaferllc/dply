<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ops triage: list every ImportServerMigration with status + age + step
 * counts. Filters by org or status. Read-only — no side effects.
 */
class ListImportMigrationsCommand extends Command
{
    protected $signature = 'dply:imports:list
                            {--org= : Filter to migrations within this organization ulid}
                            {--source= : Filter by source (ploi|forge)}
                            {--status= : Filter by status (e.g. staging, cutover_failed)}
                            {--active : Show only non-terminal migrations}';

    protected $description = 'List import migrations for ops triage.';

    public function handle(): int
    {
        $query = ImportServerMigration::query()
            ->with(['organization', 'targetServer', 'steps'])
            ->orderByDesc('created_at');

        if ($org = $this->option('org')) {
            $query->where('organization_id', $org);
        }
        if ($source = $this->option('source')) {
            $query->where('source', $source);
        }
        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }
        if ($this->option('active')) {
            $query->whereNotIn('status', [
                ImportServerMigration::STATUS_COMPLETED,
                ImportServerMigration::STATUS_PARTIAL,
                ImportServerMigration::STATUS_ABORTED,
                ImportServerMigration::STATUS_EXPIRED,
            ]);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('No matching migrations.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Source', 'Status', 'Org', 'Target server', 'Steps (ok/fail/pending)', 'Age', 'Last activity'],
            $rows->map(fn (ImportServerMigration $m): array => $this->formatRow($m))->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function formatRow(ImportServerMigration $migration): array
    {
        $stepCounts = $migration->steps->countBy('status');
        $ok = (int) ($stepCounts->get(ImportMigrationStep::STATUS_SUCCEEDED, 0));
        $failed = (int) ($stepCounts->get(ImportMigrationStep::STATUS_FAILED, 0));
        $pending = (int) ($stepCounts->get(ImportMigrationStep::STATUS_PENDING, 0));

        $lastFinished = $migration->steps
            ->whereNotNull('finished_at')
            ->sortByDesc('finished_at')
            ->first()?->finished_at;

        return [
            mb_substr($migration->id, -8),
            $migration->source,
            $migration->status,
            $migration->organization?->name ?? mb_substr((string) $migration->organization_id, -6),
            $migration->targetServer?->name ?? '-',
            sprintf('%d/%d/%d', $ok, $failed, $pending),
            $migration->created_at?->diffForHumans() ?? '-',
            $lastFinished instanceof Carbon ? $lastFinished->diffForHumans() : '-',
        ];
    }
}
