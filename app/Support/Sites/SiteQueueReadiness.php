<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Models\WorkerPool;
use Illuminate\Support\Collection;

/**
 * Whether this site can actually process a queued job, and if not, which link
 * in the chain is broken.
 *
 * Four things must all be true, and each fails silently on its own:
 *
 *   1. the app writes jobs to a store (not `sync`, which runs them inline)
 *   2. something is draining that store (an on-box worker or a pool)
 *   3. dply can read the depth (else the page is blind, not the queue)
 *   4. deploys restart the workers (else they run yesterday's code forever)
 *
 * Pure: models in, verdicts out, no IO beyond the queries it is handed. The
 * checks are worth testing exhaustively because "queues look fine but nothing
 * runs" is the failure they exist to prevent.
 */
final class SiteQueueReadiness
{
    public const OK = 'ok';

    public const WARN = 'warn';

    /**
     * @param  Collection<int, SupervisorProgram>  $workers
     * @param  Collection<int, WorkerPool>  $pools
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public static function checks(Site $site, Collection $workers, Collection $pools, ?SiteQueueSnapshot $latest): array
    {
        $config = SiteQueueConfiguration::for($site);
        $activeWorkers = $workers->where('is_active', true)->count();
        $machines = (int) $pools->sum('desired_count');

        return [
            [
                'key' => 'driver',
                'label' => __('App writes to a queue'),
                'status' => $config->isConfigured ? self::OK : self::WARN,
                'detail' => $config->isConfigured
                    ? __('QUEUE_CONNECTION is :c.', ['c' => (string) $config->connection])
                    : ($config->warning() ?? __('No queue driver configured.')),
            ],
            [
                'key' => 'consumer',
                'label' => __('Something drains it'),
                'status' => ($activeWorkers + $machines) > 0 ? self::OK : self::WARN,
                'detail' => ($activeWorkers + $machines) > 0
                    ? __(':w worker(s) on this server, :m machine(s) on pools.', ['w' => $activeWorkers, 'm' => $machines])
                    : __('No active worker and no attached pool — queued jobs will sit.'),
            ],
            [
                'key' => 'visibility',
                'label' => __('dply can read the depth'),
                // A stale reading means the SWEEP is broken, not the queue. Saying
                // so stops an operator debugging an application that is fine.
                'status' => $latest !== null && $latest->captured_at->gt(now()->subMinutes(15)) ? self::OK : self::WARN,
                'detail' => $latest === null
                    ? __('No sample yet — the first sweep runs within five minutes.')
                    : __('Last sample :when.', ['when' => $latest->captured_at->diffForHumans()]),
            ],
            [
                'key' => 'deploy_restart',
                'label' => __('Deploys restart the workers'),
                'status' => ($site->restart_supervisor_programs_after_deploy ?? false) ? self::OK : self::WARN,
                'detail' => ($site->restart_supervisor_programs_after_deploy ?? false)
                    ? __('Workers restart after each deploy.')
                    // The quiet one: a long-lived worker holds the code it booted
                    // with, so without this a deploy ships to the web app and the
                    // queue keeps running the previous release.
                    : __('Off — workers keep running the code they booted with, so deploys never reach them.'),
            ],
        ];
    }

    /**
     * @param  list<array{status: string}>  $checks
     */
    public static function isReady(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? null) !== self::OK) {
                return false;
            }
        }

        return true;
    }
}
