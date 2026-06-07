<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SchedulerTickOutput;
use App\Models\Server;
use App\Models\ServerSchedulerHeartbeat;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fires a single `schedule:run` (or equivalent) tick on demand from the
 * Schedule page's Run-now button.
 *
 * Coordinates with the wrapper's advisory file lock (Q15 (b1)) by invoking
 * the same `/usr/local/bin/dply-scheduler-tick` binary — the wrapper takes
 * the lock; if a real cron tick is in flight the wrapper exits 0 silently
 * and the Run-now becomes a no-op. That's the right semantic: the operator
 * was asking "is the scheduler firing", and the answer is "yes, right now."
 *
 * The job is scoped to the contract:
 *  - 5-minute hard timeout (Q15 (d))
 *  - Output streams into the existing `ConsoleAction` infrastructure
 *    eventually (full streaming integration lands in a follow-up; for now
 *    we capture the output and log it).
 *  - Audit log is written by the caller, not here — the caller intends to
 *    run it; failure during execution is logged separately.
 */
class RunSchedulerNowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Q15 (d): 5-minute hard timeout. */
    public int $timeout = 300;

    /** Don't auto-retry — Run-now is operator-initiated, retries should be too. */
    public int $tries = 1;

    public function __construct(
        public string $serverId,
        public string $heartbeatId,
        public ?string $userId = null,
        public ?string $runId = null,
    ) {}

    public static function cacheKey(string $runId): string
    {
        return 'scheduler_run:'.$runId;
    }

    public function handle(ExecuteRemoteTaskOnServer $remote): void
    {
        $this->store('running', '');

        $server = Server::query()->find($this->serverId);
        $heartbeat = ServerSchedulerHeartbeat::query()
            ->where('server_id', $this->serverId)
            ->whereKey($this->heartbeatId)
            ->first();

        if ($server === null || $heartbeat === null || $heartbeat->site_id === null) {
            $this->store('failed', 'Scheduler or server not found.');

            return;
        }
        if (! $server->isReady() || blank($server->ip_address)) {
            $this->store('failed', 'Server is not ready for SSH.');

            return;
        }
        $organization = $server->organization;
        if ($organization !== null && ! $organization->canSchedulerRun()) {
            Log::info('scheduler.run_now.paused_by_trial', [
                'server_id' => $this->serverId,
                'organization_id' => $organization->id,
                'trial_state' => $organization->trialState()->value,
            ]);
            $this->store('failed', 'Scheduler run is paused for this organization (trial state).');

            return;
        }

        // Resolve the site's repository path so the wrapper can `cd` into it.
        // The site relationship gives us the path; missing site = nothing to do.
        $site = $heartbeat->site;
        if ($site === null) {
            $this->store('failed', 'Site not found for this scheduler.');

            return;
        }

        $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';
        $command = $this->commandFor($heartbeat->scheduler_kind, $directory);
        if ($command === null) {
            $this->store('failed', 'No canonical run command for this scheduler kind. Use a long-running worker instead.');

            return;
        }

        // Invoke through the wrapper so the existing flock + heartbeat write
        // path is shared with real ticks. Caller's audit_log already recorded
        // intent — this job logs only execution outcome (success / timeout /
        // wrapper missing).
        $wrapperCmd = sprintf(
            '/usr/local/bin/dply-scheduler-tick %s %s -- %s',
            escapeshellarg($heartbeat->site_id),
            escapeshellarg($heartbeat->scheduler_kind),
            $command,
        );

        try {
            $out = $remote->runInlineBash($server, 'scheduler-run-now', $wrapperCmd, $this->timeout, false);
            $exit = $out->getExitCode();
            $body = trim((string) $out->getBuffer());
            Log::info('scheduler.run_now.completed', [
                'server_id' => $this->serverId,
                'heartbeat_id' => $this->heartbeatId,
                'user_id' => $this->userId,
                'exit_code' => $exit,
            ]);
            $this->store(
                $exit === 0 ? 'done' : 'failed',
                $body !== '' ? $body : ($exit === 0 ? '(no output)' : 'Exited with code '.$exit),
            );

            // Record a manual run in the tick-output history (Q7: trigger=manual).
            // runInlineBash combines stdout+stderr into one buffer; store under
            // whichever stream matches the outcome so the Logs UI tints it right.
            SchedulerTickOutput::record(
                heartbeatId: $heartbeat->id,
                trigger: SchedulerTickOutput::TRIGGER_MANUAL,
                exitCode: $exit,
                stdout: $exit === 0 ? ($body !== '' ? $body : '(no output)') : null,
                stderr: $exit !== 0 ? ($body !== '' ? $body : 'Exited with code '.$exit) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('scheduler.run_now.failed', [
                'server_id' => $this->serverId,
                'heartbeat_id' => $this->heartbeatId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
            $this->store('failed', $e->getMessage());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->store('failed', $exception?->getMessage() ?? 'The scheduler run job failed.');
    }

    private function store(string $status, string $output): void
    {
        if ($this->runId === null) {
            return;
        }
        Cache::put(self::cacheKey($this->runId), compact('status', 'output'), now()->addMinutes(10));
    }

    private function commandFor(string $kind, string $directory): ?string
    {
        return match ($kind) {
            ServerSchedulerHeartbeat::KIND_LARAVEL => 'cd '.escapeshellarg($directory).' && php artisan schedule:run',
            ServerSchedulerHeartbeat::KIND_RAILS => 'cd '.escapeshellarg($directory).' && bundle exec whenever --update-crontab',
            // Generic schedulers don't have a single canonical command;
            // operators wanting Run-now on generic schedulers should add a
            // command via a follow-up affordance.
            default => null,
        };
    }
}
