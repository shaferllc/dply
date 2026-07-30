<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Restart this box's Horizon AFTER the deploy queue has drained — the safe way
 * for dply's control-plane to bounce its OWN Horizon during a self-deploy.
 *
 * The problem: deploys run as {@see App\Modules\Deploy\Jobs\RunSiteDeploymentJob} on the `dply`
 * queue, processed by the control-plane Horizon. A self-deploy's restart step
 * runs `horizon:terminate` on that same box; the master exits and systemd
 * (KillMode=mixed) reaps the cgroup, SIGKILLing every IN-FLIGHT deploy worker —
 * the self-deploy itself AND any concurrent customer deploy.
 *
 * The fix: {@see App\Services\Sites\SiteDeployPipelineRunner} launches this
 * command DETACHED (setsid) instead of terminating inline. Detached from both
 * the SSH session and the Horizon cgroup, it survives the restart it triggers.
 * It waits until no deploy job is reserved (in-flight) on the queue — including
 * the launching deploy, which finishes moments later — then terminates Horizon.
 * Jobs still PENDING in Redis are untouched by a restart, so they simply run
 * once Horizon (Restart=always) comes back on the new code.
 */
class SelfHorizonRestartCommand extends Command
{
    protected $signature = 'dply:self-horizon-restart
        {--queue=dply : Deploy queue whose reserved (in-flight) jobs to drain before restarting}
        {--max-wait=1200 : Hard cap in seconds before restarting regardless (avoid starving on a busy queue)}
        {--poll=5 : Seconds between drain checks}';

    protected $description = 'Drain in-flight deploys, then bounce this box\'s Horizon (control-plane self-deploy safety).';

    public function handle(): int
    {
        $queue = (string) $this->option('queue');
        $maxWait = max(0, (int) $this->option('max-wait'));
        $poll = max(1, (int) $this->option('poll'));
        $reservedKey = 'queues:'.$queue.':reserved';

        $waited = 0;
        while (true) {
            $inFlight = $this->inFlightDeployCount($reservedKey);
            if ($inFlight <= 0) {
                $this->info("[dply] deploy queue '{$queue}' drained — restarting Horizon.");
                break;
            }

            if ($waited >= $maxWait) {
                $this->warn("[dply] still {$inFlight} deploy(s) in-flight after {$maxWait}s — restarting Horizon anyway.");
                break;
            }

            $this->line("[dply] {$inFlight} deploy(s) still in-flight on '{$queue}' — waiting {$poll}s (waited {$waited}s).");
            sleep($poll);
            $waited += $poll;
        }

        // Fail-soft: template merge + DPLY_ROOT patch must not block Horizon restart.
        try {
            $exit = Artisan::call('dply:self:sync-supervisor');
            $output = trim(Artisan::output());
            if ($output !== '') {
                $this->line($output);
            }
            if ($exit !== self::SUCCESS) {
                $this->warn('[dply] supervisor sync reported failure — continuing with horizon:terminate.');
            }
        } catch (Throwable $e) {
            $this->warn('[dply] supervisor sync skipped: '.$e->getMessage());
        }

        // Fail-soft: if this process can elevate (root / sudo -n), keep Docker
        // ready for Edge builds (Horizon user = www-data on control-plane workers).
        $this->maybeEnsureEdgeBuildDocker();

        try {
            Artisan::call('horizon:terminate');
            $this->info('[dply] horizon:terminate signalled; supervisor (Restart=always) relaunches it on the new release.');
        } catch (Throwable $e) {
            $this->error('[dply] horizon:terminate failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Count RunSiteDeploymentJob entries currently RESERVED (popped by a worker,
     * not yet acked) on the queue. These are the jobs a Horizon restart would
     * kill; pending jobs survive a restart so we don't count them. Fail-open:
     * any Redis hiccup returns 0 so we don't block the restart indefinitely.
     */
    private function inFlightDeployCount(string $reservedKey): int
    {
        try {
            $payloads = Redis::connection('queue')->zrange($reservedKey, 0, -1);
        } catch (Throwable $e) {
            return 0;
        }

        if (! is_array($payloads)) {
            return 0;
        }

        $count = 0;
        foreach ($payloads as $payload) {
            if (str_contains((string) $payload, 'RunSiteDeploymentJob')) {
                $count++;
            }
        }

        return $count;
    }

    private function maybeEnsureEdgeBuildDocker(): void
    {
        try {
            $check = Artisan::call('dply:edge:ensure-build-docker', ['--check' => true]);
            if ($check === self::SUCCESS) {
                return;
            }

            $this->warn('[dply] Docker not ready for Edge builds — attempting ensure (needs root or passwordless sudo)…');
            $exit = Artisan::call('dply:edge:ensure-build-docker');
            $output = trim(Artisan::output());
            if ($output !== '') {
                $this->line($output);
            }
            if ($exit !== self::SUCCESS) {
                $this->warn('[dply] Edge Docker ensure failed — run once as root on this worker: sudo php artisan dply:edge:ensure-build-docker');
            }
        } catch (Throwable $e) {
            $this->warn('[dply] Edge Docker ensure skipped: '.$e->getMessage());
        }
    }
}
