<?php

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Models\User;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Notifications\ServerPatchNotificationDispatcher;
use App\Services\Servers\ServerAptLockBash;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServerManageRemoteSshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(
        public string $serverId,
        public string $cacheKey,
        public string $taskName,
        public string $inlineBash,
        public int $timeoutSeconds,
        public ?string $flashSuccessMessage = null,
        public ?string $logId = null,
        /**
         * Fully-qualified class name of an event to broadcast on completion (success or failure).
         * Constructor signature must accept (serverId, taskId, taskName, success, error,
         * flashSuccess, finalOutput) to match {@see broadcastCompletion}. Cache write happens
         * before the broadcast so listeners can re-read the authoritative cache state.
         */
        public ?string $broadcastEventClass = null,
        /**
         * Optional ConsoleAction row ID. When set, the job mirrors progress into
         * the row via {@see ConsoleEmitter} so the page-top banner partial can
         * render it the same way it renders the webserver-switch job. The
         * cache-poll layer continues to work in parallel — callers that haven't
         * migrated to the banner keep their existing UI.
         */
        public ?string $consoleActionId = null,
    ) {
        $this->timeout = max(60, $timeoutSeconds + 60);
        $queue = config('server_manage.remote_task_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /**
     * Publish a patches notification when this manage-action is an OS patch task
     * (apt upgrade/dist-upgrade, reboot, unattended-upgrades toggle). No-op for the
     * many other manage-action task names that flow through this shared job. The
     * actor is read off the ServerManageAction row seeded at dispatch time.
     */
    private function maybeNotifyPatchAction(Server $server, bool $success, ?string $error): void
    {
        if (! preg_match('/^manage-action:(.+)$/', $this->taskName, $m)) {
            return;
        }

        $kind = match (true) {
            $m[1] === 'apt_upgrade' => $success ? 'updates_applied' : 'apply_failed',
            $m[1] === 'apt_dist_upgrade' => $success ? 'dist_upgrade_applied' : 'apply_failed',
            $m[1] === 'reboot' && $success => 'reboot_completed',
            $m[1] === 'unattended_upgrades_enable' && $success => 'auto_updates_enabled',
            $m[1] === 'unattended_upgrades_disable' && $success => 'auto_updates_disabled',
            default => null,
        };
        if ($kind === null) {
            return;
        }

        $actor = null;
        if ($this->logId !== null) {
            $userId = ServerManageAction::query()->whereKey($this->logId)->value('user_id');
            if ($userId) {
                $actor = User::find($userId);
            }
        }

        $details = (! $success && $error) ? [__('Error: :error', ['error' => $error])] : [];

        app(ServerPatchNotificationDispatcher::class)->notify(
            $server,
            $kind,
            $details,
            $actor,
            ['task' => $m[1], 'success' => $success],
        );
    }

    /**
     * Fire the optional completion broadcast configured at construction time. No-op when no
     * event class was provided — keeps SSH-keys / Firewall callers on the cache-poll path.
     */
    private function broadcastCompletion(bool $success, ?string $error, string $output): void
    {
        if ($this->broadcastEventClass === null || ! class_exists($this->broadcastEventClass)) {
            return;
        }

        try {
            broadcast(new $this->broadcastEventClass(
                $this->serverId,
                $this->cacheKey,
                $this->taskName,
                $success,
                $error,
                $this->flashSuccessMessage,
                $output,
            ));
        } catch (Throwable) {
            // Swallow broadcast failures: the cache write already happened, so the wire:poll
            // fallback will resolve the banner. We never want a Reverb hiccup to fail the job.
        }
    }

    public static function cacheKey(string $id): string
    {
        return 'server_manage_remote:'.$id;
    }

    /**
     * Latest request id for this server + task; only that job may complete successfully.
     */
    public static function activeRequestCacheKey(string $serverId, string $taskName): string
    {
        return 'server_manage_remote_active:'.$serverId.':'.sha1($taskName);
    }

    public function handle(ServerManageSshExecutor $executor): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            $this->failCache(__('Server not found.'));

            return;
        }

        if ($this->isSuperseded()) {
            $this->mergePayload([
                'status' => 'failed',
                'output' => '',
                'error' => __('This request was replaced by a newer one.'),
                'flash_success' => null,
            ]);
            $this->updateLog(ServerManageAction::STATUS_FAILED, error: __('Replaced by a newer request.'));
            $this->broadcastCompletion(false, __('This request was replaced by a newer one.'), '');

            return;
        }

        $this->mergePayload([
            'status' => 'running',
            'output' => '',
            'error' => null,
        ]);
        $this->updateLog(ServerManageAction::STATUS_RUNNING, started: true);
        $this->markConsoleRunning();

        $fullOutput = '';
        $lastFlush = microtime(true);
        $lastSupersedeCheck = microtime(true);
        $flushInterval = (float) config('server_manage.remote_task_cache_flush_seconds', 0.5);
        $supersedeCheckInterval = max(0.15, $flushInterval);

        // The ConsoleEmitter writes line-shaped entries into the row's output
        // column. We split the cache-flush buffer on \n so each terminal line
        // becomes its own banner row, instead of one giant blob.
        $emitter = new ConsoleEmitter($this->consoleActionId);
        $lastEmittedLen = 0;

        $onOutput = function (string $type, string $buffer) use (&$fullOutput, &$lastFlush, &$lastSupersedeCheck, &$lastEmittedLen, $flushInterval, $supersedeCheckInterval, $emitter): void {
            $fullOutput .= $buffer;
            $now = microtime(true);
            if ($now - $lastSupersedeCheck >= $supersedeCheckInterval) {
                $lastSupersedeCheck = $now;
                if ($this->isSuperseded()) {
                    throw ManageRemoteTaskSupersededException::make();
                }
            }
            if ($now - $lastFlush >= $flushInterval || strlen($buffer) > 8192) {
                $lastFlush = $now;
                $cleaned = ServerManageSshExecutor::stripSshClientNoise($fullOutput);
                $this->mergePayload(['output' => $cleaned]);
                // Emit only the newly-completed lines (between the last flush
                // and the last newline in $cleaned) so the banner builds up
                // incrementally without re-emitting earlier rows.
                $lastNewline = strrpos($cleaned, "\n");
                if ($lastNewline !== false && $lastNewline + 1 > $lastEmittedLen) {
                    $slice = substr($cleaned, $lastEmittedLen, $lastNewline + 1 - $lastEmittedLen);
                    foreach (preg_split('/\R/', rtrim($slice, "\n")) ?: [] as $line) {
                        if ($line !== '') {
                            $emitter($line);
                        }
                    }
                    $lastEmittedLen = $lastNewline + 1;
                }
            }
        };

        try {
            $out = $executor->runInlineBash(
                $server,
                $this->taskName,
                $this->inlineBash,
                $this->timeoutSeconds,
                $onOutput,
            );
            $fullOutput = $out->getBuffer();
            $trimmed = trim(ServerManageSshExecutor::stripSshClientNoise($fullOutput));

            if ($this->isSuperseded()) {
                $this->mergePayload([
                    'status' => 'failed',
                    'output' => $trimmed,
                    'error' => __('This request was replaced by a newer one.'),
                    'flash_success' => null,
                ]);
                $this->updateLog(ServerManageAction::STATUS_FAILED, error: __('Replaced by a newer request.'), output: $trimmed);
                $this->emitConsoleTail($emitter, $trimmed, $lastEmittedLen);
                $this->markConsoleFailed(__('This request was replaced by a newer one.'));
                $this->broadcastCompletion(false, __('This request was replaced by a newer one.'), $trimmed);

                return;
            }

            if (! $out->isSuccessful()) {
                $maxAttempts = (int) config('server_manage.apt_auto_retry_max_attempts', 3);
                $delaySeconds = (int) config('server_manage.apt_auto_retry_delay_seconds', 15);
                if ($maxAttempts > 1
                    && $this->attempts() < $maxAttempts
                    && ServerAptLockBash::outputLooksLikeAptLockFailure($trimmed, $out->getExitCode())) {
                    $nextAttempt = $this->attempts() + 1;
                    $this->mergePayload([
                        'status' => 'queued',
                        'output' => $trimmed,
                        'error' => null,
                        'flash_success' => null,
                        'retry_attempt' => $nextAttempt,
                    ]);
                    $this->updateLog(ServerManageAction::STATUS_QUEUED, output: $trimmed);
                    $this->release($delaySeconds * $this->attempts());

                    return;
                }

                $error = __('Remote command exited with code :code.', ['code' => (string) ($out->getExitCode() ?? 'unknown')]);
                $this->mergePayload([
                    'status' => 'failed',
                    'output' => $trimmed,
                    'error' => $error,
                    'flash_success' => null,
                ]);
                $this->updateLog(ServerManageAction::STATUS_FAILED, error: $error, output: $trimmed);
                $this->emitConsoleTail($emitter, $trimmed, $lastEmittedLen);
                $this->markConsoleFailed($error);
                $this->broadcastCompletion(false, $error, $trimmed);
                $this->maybeNotifyPatchAction($server, false, $error);

                return;
            }

            $this->mergePayload([
                'status' => 'finished',
                'output' => $trimmed,
                'error' => null,
                'flash_success' => $this->flashSuccessMessage,
            ]);
            $this->updateLog(ServerManageAction::STATUS_FINISHED, output: $trimmed);
            $this->emitConsoleTail($emitter, $trimmed, $lastEmittedLen);
            $this->emitConsolePlaceholderIfEmpty($emitter, $trimmed);
            $this->markConsoleCompleted();
            $this->broadcastCompletion(true, null, $trimmed);
            $this->maybeNotifyPatchAction($server, true, null);

            if ($this->taskName === 'services-install:install_monitoring_prerequisites') {
                $server = Server::find($this->serverId);
                if ($server !== null) {
                    app(ServerMetricsGuestPushService::class)->syncPushArtifactsAfterInstall($server);
                }
            }

            if ($this->shouldRerunProbeAfterFinish()) {
                RefreshServerInventoryJob::dispatch($this->serverId);
            }
        } catch (ManageRemoteTaskSupersededException) {
            $trimmed = trim(ServerManageSshExecutor::stripSshClientNoise($fullOutput));
            $this->mergePayload([
                'status' => 'failed',
                'output' => $trimmed,
                'error' => __('This request was replaced by a newer one.'),
                'flash_success' => null,
            ]);
            $this->updateLog(ServerManageAction::STATUS_FAILED, error: __('Replaced by a newer request.'), output: $trimmed);
            $this->emitConsoleTail($emitter, $trimmed, $lastEmittedLen);
            $this->markConsoleFailed(__('This request was replaced by a newer one.'));
            $this->broadcastCompletion(false, __('This request was replaced by a newer one.'), $trimmed);
        } catch (Throwable $e) {
            $trimmed = trim(ServerManageSshExecutor::stripSshClientNoise($fullOutput));
            $this->mergePayload([
                'status' => 'failed',
                'output' => $trimmed,
                'error' => $e->getMessage(),
                'flash_success' => null,
            ]);
            $this->updateLog(ServerManageAction::STATUS_FAILED, error: $e->getMessage(), output: $trimmed);
            $this->emitConsoleTail($emitter, $trimmed, $lastEmittedLen);
            $this->markConsoleFailed($e->getMessage());
            $this->broadcastCompletion(false, $e->getMessage(), $trimmed);
        }
    }

    /**
     * Emit any tail content that arrived between the last flush and the run's
     * terminal state. Without this, output that didn't end with a newline (a
     * common shape for short utility scripts) would be lost from the banner.
     */
    private function emitConsoleTail(ConsoleEmitter $emitter, string $finalOutput, int $alreadyEmittedLen): void
    {
        if ($this->consoleActionId === null) {
            return;
        }
        if (strlen($finalOutput) <= $alreadyEmittedLen) {
            return;
        }
        $tail = substr($finalOutput, $alreadyEmittedLen);
        foreach (preg_split('/\R/', rtrim($tail, "\n")) ?: [] as $line) {
            if ($line !== '') {
                $emitter($line);
            }
        }
    }

    /**
     * Drop a friendly placeholder when a successful run produced no terminal
     * output at all — some daemons (e.g. `lshttpd -t`) write their diagnostics
     * into the server's own log file instead of stdout/stderr, so the SSH
     * stream stays empty even though the run finished cleanly. Without this
     * the banner shows the unhelpful "No output recorded." copy.
     */
    private function emitConsolePlaceholderIfEmpty(ConsoleEmitter $emitter, string $finalOutput): void
    {
        if ($this->consoleActionId === null) {
            return;
        }
        if (trim($finalOutput) !== '') {
            return;
        }
        $emitter->success(__('Command finished with no terminal output.'), 'dply');
    }

    private function markConsoleRunning(): void
    {
        if ($this->consoleActionId === null) {
            return;
        }
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => DB::raw('coalesce(started_at, now())'),
            'updated_at' => now(),
        ]);
    }

    private function markConsoleCompleted(): void
    {
        if ($this->consoleActionId === null) {
            return;
        }
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => null,
            'updated_at' => now(),
        ]);
    }

    private function markConsoleFailed(string $error): void
    {
        if ($this->consoleActionId === null) {
            return;
        }
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'error' => mb_substr($error, 0, 2000),
            'updated_at' => now(),
        ]);
    }

    protected function shouldRerunProbeAfterFinish(): bool
    {
        if (str_starts_with($this->taskName, 'mise-runtime:')) {
            return true;
        }

        if (! preg_match('/^manage-action:(.+)$/', $this->taskName, $m)) {
            return false;
        }
        $key = $m[1];
        $defs = config('server_manage.service_actions', []);
        $danger = config('server_manage.dangerous_actions', []);
        $entry = $defs[$key] ?? $danger[$key] ?? null;

        return is_array($entry) && (bool) ($entry['rerun_probe_after_finish'] ?? false);
    }

    protected function updateLog(string $status, bool $started = false, ?string $error = null, ?string $output = null): void
    {
        if ($this->logId === null || $this->logId === '') {
            return;
        }

        $row = ServerManageAction::query()->find($this->logId);
        if ($row === null) {
            return;
        }

        $update = ['status' => $status];
        if ($started) {
            $update['started_at'] = now();
        }
        if (in_array($status, [ServerManageAction::STATUS_FINISHED, ServerManageAction::STATUS_FAILED], true)) {
            $update['finished_at'] = now();
        }
        if ($error !== null && $error !== '') {
            $update['error_message'] = $error;
        }
        if ($output !== null) {
            // Cap output so a runaway script can't bloat the row. The
            // last 64KB is what an operator usually wants — final apt
            // resolver output, error trace, etc. Earlier chunks live
            // in the in-memory cache during the run.
            $maxOutputBytes = 65_536;
            if (strlen($output) > $maxOutputBytes) {
                $output = "[…output truncated…]\n".substr($output, -$maxOutputBytes);
            }
            $update['output'] = $output;
        }
        $row->update($update);
    }

    protected function isSuperseded(): bool
    {
        if (! (bool) config('server_manage.supersede_duplicate_remote_tasks', true)) {
            return false;
        }

        $active = Cache::get(self::activeRequestCacheKey($this->serverId, $this->taskName));

        return is_string($active) && $active !== '' && $active !== $this->cacheKey;
    }

    public function failed(?Throwable $exception): void
    {
        $this->failCache($exception?->getMessage() ?? __('The remote task failed.'));
    }

    protected function failCache(string $message): void
    {
        $existing = Cache::get(self::cacheKey($this->cacheKey), []);
        $this->mergePayload([
            'status' => 'failed',
            'output' => is_array($existing) ? ($existing['output'] ?? '') : '',
            'error' => $message,
            'flash_success' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mergePayload(array $data): void
    {
        $key = self::cacheKey($this->cacheKey);
        $ttlSeconds = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);
        $existing = Cache::get($key, []);
        if (! is_array($existing)) {
            $existing = [];
        }
        Cache::put($key, array_merge($existing, $data), now()->addSeconds(max(60, $ttlSeconds)));
    }
}
