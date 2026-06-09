<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerCacheServiceReplication;
use App\Support\Servers\CacheServiceReplication;
use Illuminate\Console\Command;

/**
 * Refresh `last_link_status` / `last_observed_offset` / `last_polled_at` on every
 * active {@see ServerCacheServiceReplication} row. The Replication card and the
 * Overview Role tile both read these fields, so a stale link status appears in
 * the UI within a poll cycle.
 *
 * Register from the kernel: `$schedule->command('dply:poll-cache-replication')->everyMinute()`.
 */
class PollCacheServiceReplicationCommand extends Command
{
    protected $signature = 'dply:poll-cache-replication';

    protected $description = 'Poll INFO replication on every active replica row and update cached link status / offset.';

    public function handle(CacheServiceReplication $replication): int
    {
        $rows = ServerCacheServiceReplication::query()
            ->whereIn('status', [
                ServerCacheServiceReplication::STATUS_CONFIGURING,
                ServerCacheServiceReplication::STATUS_ACTIVE,
                ServerCacheServiceReplication::STATUS_ERROR,
            ])
            ->with(['replicaCacheService.server'])
            ->get();

        foreach ($rows as $row) {
            $replica = $row->replicaCacheService;
            $server = $replica?->server;
            if ($replica === null || $server === null) {
                continue;
            }

            $snapshot = $replication->snapshot($server, $replica);
            $linkStatus = $snapshot['master_link_status'] ?? null;

            $row->update([
                'last_link_status' => $linkStatus,
                'last_observed_offset' => $snapshot['replication_offset'] ?? null,
                'last_polled_at' => now(),
                'status' => match (true) {
                    $linkStatus === 'up' => ServerCacheServiceReplication::STATUS_ACTIVE,
                    $linkStatus === null => $row->status,
                    default => ServerCacheServiceReplication::STATUS_ERROR,
                },
            ]);
        }

        $this->info('Polled '.$rows->count().' replication row(s).');

        return self::SUCCESS;
    }
}
