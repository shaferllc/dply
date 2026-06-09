<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerPoolMember;
use Illuminate\Console\Command;

/**
 * Operator visibility into the warm pool: per-configured-bucket member counts
 * by status vs the bucket's min/max, plus any orphan members not in config.
 */
class WarmPoolStatusCommand extends Command
{
    protected $signature = 'dply:warm-pool:status';

    protected $description = 'Show warm server pool bucket counts and health.';

    public function handle(): int
    {
        $this->line('Warm pool: '.(config('warm_pool.enabled') ? '<info>enabled</info>' : '<comment>disabled</comment>'));

        $buckets = (array) config('warm_pool.buckets', []);
        if ($buckets === []) {
            $this->info('No buckets configured.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            if (! is_array($bucket)) {
                continue;
            }
            $provider = (string) ($bucket['provider'] ?? '');
            $region = (string) ($bucket['region'] ?? '');
            $size = (string) ($bucket['size'] ?? '');
            $tier = (string) ($bucket['tier'] ?? ServerPoolMember::TIER_BASELINE);

            $base = ServerPoolMember::query()->forBucket($provider, $region, $size, $tier);
            $count = fn (string $s): int => (clone $base)->where('status', $s)->count();

            $rows[] = [
                "{$provider}/{$region}/{$size}/{$tier}",
                (int) ($bucket['min'] ?? 0).'/'.(int) ($bucket['max'] ?? 0),
                $count(ServerPoolMember::STATUS_WARMING),
                $count(ServerPoolMember::STATUS_READY),
                $count(ServerPoolMember::STATUS_CLAIMING),
                $count(ServerPoolMember::STATUS_CLAIMED),
                $count(ServerPoolMember::STATUS_FAILED),
            ];
        }

        $this->table(
            ['bucket', 'min/max', 'warming', 'ready', 'claiming', 'claimed', 'failed'],
            $rows,
        );

        $orphans = ServerPoolMember::query()->where('status', ServerPoolMember::STATUS_FAILED)->count();
        if ($orphans > 0) {
            $this->warn("{$orphans} failed member(s) — the autoscaler should be replacing them; investigate if persistent.");
        }

        return self::SUCCESS;
    }
}
