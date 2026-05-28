<?php

declare(strict_types=1);

namespace App\Services\Sites\Promote;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;

/**
 * Copies site-scoped server cron rows to the destination server during promote.
 */
final class SitePromoteCronReplicator
{
    public static function replicate(Site $source, Site $destination, Server $destServer): int
    {
        $crons = ServerCronJob::query()
            ->where('site_id', $source->id)
            ->orderBy('cron_expression')
            ->get();

        $count = 0;
        foreach ($crons as $cron) {
            $row = $cron->replicate();
            $row->server_id = $destServer->id;
            $row->site_id = $destination->id;
            $row->is_synced = false;
            $row->last_sync_error = null;
            $row->last_run_at = null;
            $row->last_run_output = null;
            $row->save();
            $count++;
        }

        return $count;
    }
}
