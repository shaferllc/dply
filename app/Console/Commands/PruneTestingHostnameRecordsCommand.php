<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sites\TestingHostnameRecordPruner;
use Illuminate\Console\Command;

class PruneTestingHostnameRecordsCommand extends Command
{
    protected $signature = 'dply:prune-testing-hostname-records {--dry-run : Report stale records without deleting them}';

    protected $description = 'Compare configured testing hostname A records against attached site hostnames and remove stale records.';

    public function handle(TestingHostnameRecordPruner $pruner): int
    {
        $records = $pruner->staleRecords();
        $dryRun = (bool) $this->option('dry-run');

        if ($records === []) {
            $this->info('No stale testing hostname A records found.');

            return self::SUCCESS;
        }

        foreach ($records as $record) {
            if ($dryRun) {
                $this->line(sprintf(
                    'Dry run: stale testing hostname record %s in %s (record #%d → %s).',
                    $record['hostname'],
                    $record['zone'],
                    $record['record_id'],
                    $record['record_data']
                ));

                continue;
            }

            $pruner->deleteRecord($record);

            $this->info(sprintf(
                'Deleted stale testing hostname record %s in %s (record #%d).',
                $record['hostname'],
                $record['zone'],
                $record['record_id']
            ));
        }

        return self::SUCCESS;
    }
}
