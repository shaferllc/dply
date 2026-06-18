<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Console\Command;

/**
 * Keeps the `function_invocations` table bounded.
 *
 * Organic web traffic is unbounded, so each site's `web` rows are capped
 * tighter and expire faster than its low-volume `tick` / `test` rows.
 * Scheduled daily — a brief overshoot between runs is harmless, which is
 * why ingest stays a single INSERT rather than trimming on every write.
 */
class PruneFunctionInvocationsCommand extends Command
{
    protected $signature = 'serverless:prune-invocations';

    protected $description = 'Prune old function_invocations rows so the table stays bounded per site.';

    /** Organic web rows: kept per site / max age in days. */
    private const WEB_KEEP = 500;

    private const WEB_DAYS = 7;

    /** dply-initiated rows (tick / test): kept per site / max age in days. */
    private const OPERATIONAL_KEEP = 200;

    private const OPERATIONAL_DAYS = 30;

    public function handle(): int
    {
        $deleted = $this->prune([FunctionInvocation::SOURCE_WEB], self::WEB_KEEP, self::WEB_DAYS)
            + $this->prune(
                [FunctionInvocation::SOURCE_TICK, FunctionInvocation::SOURCE_TEST],
                self::OPERATIONAL_KEEP,
                self::OPERATIONAL_DAYS,
            );

        $this->info('Pruned '.$deleted.' function invocation(s).');

        return self::SUCCESS;
    }

    /**
     * Drop rows of the given sources past the age cutoff, then per site trim
     * anything beyond the most recent $keep.
     *
     * @param  list<string>  $sources
     */
    private function prune(array $sources, int $keep, int $days): int
    {
        $deleted = FunctionInvocation::query()
            ->whereIn('source', $sources)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $siteIds = FunctionInvocation::query()
            ->whereIn('source', $sources)
            ->distinct()
            ->pluck('site_id');

        foreach ($siteIds as $siteId) {
            $keepIds = FunctionInvocation::query()
                ->where('site_id', $siteId)
                ->whereIn('source', $sources)
                ->orderByDesc('created_at')
                ->limit($keep)
                ->pluck('id');

            $deleted += FunctionInvocation::query()
                ->where('site_id', $siteId)
                ->whereIn('source', $sources)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }

        return $deleted;
    }
}
