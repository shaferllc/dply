<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncLogAggregatorPolicyJob;
use App\Models\ServerLogAggregator;
use App\Services\Logs\ServerLogAggregatorPolicyMap;
use Illuminate\Console\Command;

/**
 * Refresh the per-org policy table (retention_days + hard-cap allow) on every
 * running dply Logs aggregator. Dispatches one queued sync job per aggregator;
 * the job no-ops on the box when the policy is unchanged. Scheduled hourly,
 * after the meter run so caps reflect the latest usage.
 *
 *   php artisan dply:logs:sync-policy
 *   php artisan dply:logs:sync-policy --print   # show the CSV, dispatch nothing
 */
class SyncLogAggregatorPolicyCommand extends Command
{
    protected $signature = 'dply:logs:sync-policy
                            {--print : Print the rendered policy CSV without dispatching}';

    protected $description = 'Ship the per-org retention/quota policy to running dply Logs aggregators.';

    public function handle(ServerLogAggregatorPolicyMap $policy): int
    {
        if ($this->option('print')) {
            $this->line($policy->toCsv());

            return self::SUCCESS;
        }

        $aggregators = ServerLogAggregator::query()
            ->where('status', ServerLogAggregator::STATUS_RUNNING)
            ->get();

        if ($aggregators->isEmpty()) {
            $this->info('No running aggregators — nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($aggregators as $aggregator) {
            SyncLogAggregatorPolicyJob::dispatch($aggregator->id);
        }

        $this->info(sprintf('Dispatched policy sync to %d aggregator(s).', $aggregators->count()));

        return self::SUCCESS;
    }
}
