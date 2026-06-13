<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Jobs\WaitForServerSshReadyJob;
use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Services\Servers\ServerAptLockBash;
use Illuminate\Support\Facades\Log;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait SchedulesProvisionRetry
{


    /**
     * If the most recent run failed for a transient reason (apt fetch timeout, network blip,
     * connection reset) and we're under the attempt cap, queue a delayed retry rather than
     * leaving the user staring at a failure card.
     *
     * Disabled by default — set DPLY_AUTO_RETRY_ENABLED=true to opt in. Operators iterating
     * on the bash script generally want a failed run to stay visible so they can inspect
     * the output and choose when to re-run, instead of dply silently kicking off attempt
     * #2 after 30s and overwriting the in-flight diagnostic state.
     */
    protected static function tryScheduleAutoRetry(Server $server): bool
    {
        if (! (bool) config('dply.auto_retry_enabled', false)) {
            return false;
        }

        $latestRun = ServerProvisionRun::query()
            ->where('server_id', $server->getKey())
            ->latest('created_at')
            ->first();

        if ($latestRun === null) {
            return false;
        }

        $attempt = max(1, (int) $latestRun->attempt);
        if ($attempt >= self::MAX_AUTO_RETRY_ATTEMPTS) {
            return false;
        }

        $task = $latestRun->task;
        $output = is_object($task) && isset($task->output) ? (string) $task->output : '';
        if (! self::failureLooksTransient($output)) {
            return false;
        }

        // Backoff: 30s after first failure, 90s after second.
        $delaySeconds = $attempt === 1 ? 30 : 90;
        $retryAt = now()->addSeconds($delaySeconds);

        $meta = $server->meta ?? [];
        $meta['auto_retry_at'] = $retryAt->toIso8601String();
        $meta['auto_retry_attempt'] = $attempt + 1;
        $meta['auto_retry_max'] = self::MAX_AUTO_RETRY_ATTEMPTS;
        // Clear stale provision_task_id so the journey UI doesn't keep polling the dead task.
        unset($meta['provision_task_id']);
        // Clear step snapshots from the previous failed run. Without
        // this, the journey page treats every prior-run script_* key
        // as "completed" via stepHasPersistedSnapshot during the new
        // run's cloud phase — so the moment cloud hits 100%, setup
        // shows 100% too, before the new setup script has even
        // dispatched. Manual rerun already clears these (see
        // ProvisionJourney::resumeInstall).
        unset($meta['provision_step_snapshots']);

        $server->update([
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => $meta,
        ]);

        Log::info('server.provision.auto_retry_scheduled', [
            'server_id' => $server->id,
            'attempt' => $attempt + 1,
            'max_attempts' => self::MAX_AUTO_RETRY_ATTEMPTS,
            'delay_seconds' => $delaySeconds,
            'retry_at' => $retryAt->toIso8601String(),
        ]);

        WaitForServerSshReadyJob::dispatch($server)->delay($retryAt);

        return true;
    }

    /**
     * Heuristic: does the failure output look like something a retry could resolve?
     * Network timeouts, apt fetch errors, connection resets — yes. Hard config / auth
     * errors — no, retrying just wastes time and risks more partial state.
     */
    protected static function failureLooksTransient(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        $transientPatterns = [
            '/Timeout was reached/i',
            '/Connection (?:timed out|refused|reset)/i',
            '/Temporary failure resolving/i',
            '/Could not resolve host/i',
            '/Failed to fetch /i',
            '/E: Unable to fetch /i',
            '/Sub-process .* returned an error code/i',
            '/Hash Sum mismatch/i',
            '/network is unreachable/i',
            '/No route to host/i',
            '/SSL_connect: Connection reset/i',
            '/curl: \(\d+\) (?:Could not|Operation timed out|Failed to|Recv failure)/i',
            '/Could not get lock/i',
            '/Unable to acquire the dpkg frontend lock/i',
            '/is held by process/i',
            '/\/var\/lib\/dpkg\/lock-frontend/i',
        ];

        $hardErrorPatterns = [
            '/Permission denied/i',
            '/command not found/i',
            '/syntax error/i',
            '/dpkg: error processing package/i',
            '/conflicting requested operation/i',
        ];

        foreach ($hardErrorPatterns as $pattern) {
            if (preg_match($pattern, $output) === 1) {
                return false;
            }
        }

        // "Unable to locate package X" means the package name isn't in any
        // configured repo — a hard error that retrying won't fix. The same
        // message also appears when the apt index download failed (a transient
        // network blip), so only treat it as hard when there's NO fetch failure.
        if (preg_match('/E: Unable to locate package/i', $output) === 1
            && preg_match('/(Failed to fetch|Could not connect|Temporary failure resolving|Could not resolve host|InRelease)/i', $output) !== 1) {
            return false;
        }

        foreach ($transientPatterns as $pattern) {
            if (preg_match($pattern, $output) === 1) {
                return true;
            }
        }

        if (ServerAptLockBash::outputLooksLikeAptLockFailure($output)) {
            return true;
        }

        return false;
    }

    public static function shouldDispatch(Server $server): bool
    {
        if ($server->status !== Server::STATUS_READY) {
            return false;
        }

        if (! $server->isVmHost()) {
            return false;
        }

        if (empty($server->ip_address) || ! filled($server->ssh_private_key)) {
            return false;
        }

        $meta = $server->meta ?? [];
        $hasStack = is_array($meta) && filled($meta['server_role'] ?? null);
        $hasOptionalScript = filled($server->setup_script_key) && $server->setup_script_key !== 'none';

        return $hasStack || $hasOptionalScript;
    }
}
