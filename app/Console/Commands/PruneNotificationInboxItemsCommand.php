<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationInboxItem;
use App\Support\NotificationTablesReady;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bound the per-user notification inbox. Hard-deletes inbox items that are READ
 * and NOT saved when they are either older than --days OR beyond the newest
 * --keep for that user. Unread and saved items are never pruned (saved is
 * sacred). The underlying NotificationEvent audit trail is untouched. Daily.
 */
class PruneNotificationInboxItemsCommand extends Command
{
    protected $signature = 'dply:notifications:prune
        {--days= : Delete read+unsaved items older than this many days (default config)}
        {--keep= : Keep at most this many newest read+unsaved items per user (default config)}';

    protected $description = 'Prune old / overflow read, unsaved notification inbox items.';

    public function handle(): int
    {
        if (! NotificationTablesReady::all()) {
            $this->warn('Notification tables not ready — skipping prune.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('notifications.retention_days', 30));
        $keep = (int) ($this->option('keep') ?? config('notifications.retention_keep_per_user', 500));
        $days = max(1, $days);
        $keep = max(1, $keep);

        // 1) Age-based: read + unsaved older than the window.
        $aged = NotificationInboxItem::query()
            ->whereNotNull('read_at')
            ->whereNull('saved_at')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        // 2) Count-cap: per user, beyond the newest $keep read+unsaved items.
        // Collect the ids to delete first (one pass over users with overflow),
        // then bulk-delete — avoids a correlated subquery per row.
        $overflowIds = [];

        $userIds = NotificationInboxItem::query()
            ->whereNotNull('read_at')
            ->whereNull('saved_at')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) > ?', [$keep])
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $ids = NotificationInboxItem::query()
                ->where('user_id', $userId)
                ->whereNotNull('read_at')
                ->whereNull('saved_at')
                ->orderByDesc('created_at')
                ->skip($keep)
                ->take(100000)
                ->pluck('id');

            foreach ($ids as $id) {
                $overflowIds[] = $id;
            }
        }

        $overflow = 0;
        foreach (array_chunk($overflowIds, 1000) as $chunk) {
            $overflow += DB::table('notification_inbox_items')->whereIn('id', $chunk)->delete();
        }

        $this->info("Pruned {$aged} aged + {$overflow} overflow notification inbox item(s).");

        return self::SUCCESS;
    }
}
