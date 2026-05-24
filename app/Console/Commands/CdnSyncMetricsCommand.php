<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncSiteCdnMetricsJob;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CdnSyncMetricsCommand extends Command
{
    protected $signature = 'dply:site:cdn-sync-metrics
        {site? : Site ID, slug, or name (omit with --all-enabled to fan out)}
        {--all-enabled : Queue a job for every site with edge enabled}
        {--since=1440 : Trailing window in minutes (default 24h)}
        {--sync : Run inline instead of queuing}';

    protected $description = 'Refresh the trailing-24h edge metrics snapshot for a site or every enabled site.';

    public function handle(): int
    {
        $since = max(60, (int) $this->option('since'));

        if ($this->option('all-enabled')) {
            $count = 0;
            Site::query()
                ->where(function ($q): void {
                    // Postgres JSON path lookup: cdn.enabled = true.
                    $q->whereRaw("(meta->'cdn'->>'enabled')::boolean = true");
                })
                ->whereNotNull(DB::raw("meta->'cdn'->>'zone_id'"))
                ->orderBy('id')
                ->each(function (Site $site) use (&$count, $since): void {
                    if ($this->option('sync')) {
                        (new SyncSiteCdnMetricsJob($site->id, $since))->handle();
                    } else {
                        SyncSiteCdnMetricsJob::dispatch($site->id, $since);
                    }
                    $count++;
                });
            $this->info("Dispatched {$count} metric sync job(s).");

            return self::SUCCESS;
        }

        $needle = (string) $this->argument('site');
        if ($needle === '') {
            $this->error('Provide a site argument or --all-enabled.');

            return self::FAILURE;
        }

        $site = Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            (new SyncSiteCdnMetricsJob($site->id, $since))->handle();
        } else {
            SyncSiteCdnMetricsJob::dispatch($site->id, $since);
        }

        $this->info('Metrics sync dispatched.');

        return self::SUCCESS;
    }
}
