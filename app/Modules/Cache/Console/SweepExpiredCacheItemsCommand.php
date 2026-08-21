<?php

declare(strict_types=1);

namespace App\Modules\Cache\Console;

use App\Modules\Cache\Services\PostgresCacheStore;
use Illuminate\Console\Command;

/**
 * Reclaim expired cache items.
 *
 * This frees SPACE; it does not enforce correctness. Expired rows are filtered
 * on read by {@see PostgresCacheStore}, so however far behind this falls, a
 * stale value can never be served. That separation is deliberate: a sweeper
 * that correctness depends on is a sweeper whose every failure is a bug.
 *
 * Batched so one pass cannot hold a long transaction against the store, and so
 * the statement-level usage triggers aggregate over a batch rather than the
 * whole table.
 */
class SweepExpiredCacheItemsCommand extends Command
{
    protected $signature = 'dply:cache:sweep {--json : Output JSON}';

    protected $description = 'Delete expired dply Cache items and reclaim their quota.';

    public function handle(PostgresCacheStore $store): int
    {
        $deleted = $store->sweep(
            (int) config('cache_service.sweep.batch_size', 5_000),
            (int) config('cache_service.sweep.max_batches', 20),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode(['deleted' => $deleted]));

            return self::SUCCESS;
        }

        $this->info($deleted === 0 ? 'Nothing to sweep.' : 'Swept '.$deleted.' expired items.');

        return self::SUCCESS;
    }
}
