<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerSystemdInventoryRecorder;
use App\Services\Servers\ServerSystemdServicesCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncServerSystemdServicesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout;

    public function __construct(
        public string $serverId,
        /**
         * Optional UUID matching {@see ServerManageRemoteSshJob::cacheKey} so the workspace's
         * action banner can render queued/running/finished/failed status + streaming SSH output
         * without a parallel state machine. Caller pre-writes the `queued` payload before
         * dispatching us; we transition to running, stream output, then write the terminal state.
         */
        public ?string $cacheKey = null,
        /**
         * Optional broadcast event class to dispatch after the terminal cache write so Echo
         * subscribers can update immediately (avoiding the wire:poll latency).
         */
        public ?string $broadcastEventClass = null,
        /**
         * Optional ShouldBeUnique key. When null (the default), each dispatch gets a per-call
         * unique key, effectively disabling dedup — which is what we want for post-action
         * dispatches that must always run (so the inventory's `pending_action` flag clears).
         * Auto-load callers explicitly set 'auto:<serverId>' to dedupe rapid page reloads.
         */
        public ?string $dedupeKey = null,
    ) {
        $this->timeout = max(120, (int) config('server_services.systemd_inventory_timeout', 300) + 90);
        $queue = config('server_services.sync_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }

        if ($this->dedupeKey === null || $this->dedupeKey === '') {
            $this->dedupeKey = $this->cacheKey !== null && $this->cacheKey !== ''
                ? 'server-systemd-inventory:run:'.$this->cacheKey
                : 'server-systemd-inventory:once:'.bin2hex(random_bytes(8));
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->dedupeKey;
    }

    public int $uniqueFor = 120;

    public function handle(
        ServerManageSshExecutor $executor,
        ServerSystemdInventoryRecorder $recorder,
    ): void {
        if (! (bool) config('server_services.systemd_inventory_job_enabled', true)) {
            return;
        }

        $server = Server::query()->find($this->serverId);
        if ($server === null || ! $server->isReady() || $server->ssh_private_key === null || $server->ssh_private_key === '') {
            $this->writeBannerCache('failed', '', __('Server is not ready for inventory sync.'));
            $this->fireBroadcast(false, __('Server is not ready for inventory sync.'), '');

            return;
        }

        $started = microtime(true);
        $catalog = app(ServerSystemdServicesCatalog::class);
        $script = $catalog->buildInventoryScript($server);
        $invTimeout = (int) config('server_services.systemd_inventory_timeout', 300);

        $this->writeBannerCache('running', '', null);

        $fullOutput = '';
        $lastFlush = microtime(true);
        $flushInterval = (float) config('server_manage.remote_task_cache_flush_seconds', 0.5);
        $onOutput = function (string $type, string $buffer) use (&$fullOutput, &$lastFlush, $flushInterval): void {
            $fullOutput .= $buffer;
            $now = microtime(true);
            if ($now - $lastFlush >= $flushInterval || strlen($buffer) > 8192) {
                $lastFlush = $now;
                $this->writeBannerCache('running', ServerManageSshExecutor::stripSshClientNoise($fullOutput), null);
            }
        };

        try {
            $out = $executor->runInlineBash(
                $server,
                'services-systemd-inventory-job',
                $script,
                $invTimeout,
                $onOutput,
            );
        } catch (\Throwable $e) {
            Log::warning('systemd inventory ssh failed', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);
            $trimmed = trim(ServerManageSshExecutor::stripSshClientNoise($fullOutput));
            $this->writeBannerCache('failed', $trimmed, $e->getMessage());
            $this->recordInventoryMeta($server->fresh(), 'failed', $e->getMessage(), $this->elapsedMsSince($started));
            $this->fireBroadcast(false, $e->getMessage(), $trimmed);

            return;
        }

        $raw = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
        if (! $out->isSuccessful()) {
            $error = __('Remote command exited with code :code.', ['code' => (string) $out->getExitCode()]);
            Log::warning('systemd inventory non-zero exit', [
                'server_id' => $this->serverId,
                'exit' => $out->getExitCode(),
            ]);
            $this->writeBannerCache('failed', $raw, $error);
            $this->recordInventoryMeta(
                $server->fresh(),
                'failed',
                $error,
                $this->elapsedMsSince($started),
            );
            $this->fireBroadcast(false, $error, $raw);

            return;
        }

        try {
            $recorder->persistInventoryFromRawOutput($server->fresh(), $raw);
            $this->recordInventoryMeta($server->fresh(), 'success', null, $this->elapsedMsSince($started));
            $this->writeBannerCache('finished', $raw, null);
            $this->fireBroadcast(true, null, $raw);
        } catch (\Throwable $e) {
            Log::error('systemd inventory persist failed', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);
            $this->recordInventoryMeta($server->fresh(), 'failed', $e->getMessage(), $this->elapsedMsSince($started));
            $this->writeBannerCache('failed', $raw, $e->getMessage());
            $this->fireBroadcast(false, $e->getMessage(), $raw);

            throw $e;
        }
    }

    /**
     * Write the cache payload in the same shape as {@see ServerManageRemoteSshJob} so the
     * workspace's existing syncSystemdRemoteTaskFromCache poll handler picks it up unchanged.
     * Optional cacheKey is used to no-op when the caller didn't request banner tracking.
     */
    protected function writeBannerCache(string $status, string $output, ?string $error): void
    {
        if ($this->cacheKey === null || $this->cacheKey === '') {
            return;
        }
        $ttl = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);
        $current = Cache::get(ServerManageRemoteSshJob::cacheKey($this->cacheKey));
        $payload = is_array($current) ? $current : [];
        $payload['status'] = $status;
        $payload['output'] = $output;
        $payload['error'] = $error;
        if ($status === 'finished') {
            $payload['flash_success'] = __('Inventory sync complete.');
        } elseif ($status === 'failed') {
            $payload['flash_success'] = null;
        }
        Cache::put(
            ServerManageRemoteSshJob::cacheKey($this->cacheKey),
            $payload,
            now()->addSeconds(max(120, $ttl)),
        );
    }

    protected function fireBroadcast(bool $success, ?string $error, string $output): void
    {
        if ($this->cacheKey === null || $this->cacheKey === '' || $this->broadcastEventClass === null) {
            return;
        }
        if (! class_exists($this->broadcastEventClass)) {
            return;
        }

        try {
            broadcast(new $this->broadcastEventClass(
                $this->serverId,
                $this->cacheKey,
                'services-systemd-inventory-job',
                $success,
                $error,
                $success ? __('Inventory sync complete.') : null,
                $output,
            ));
        } catch (\Throwable) {
            // Same belt-and-suspenders policy as ServerManageRemoteSshJob: the cache write is
            // authoritative; a Reverb hiccup must never fail the queue job.
        }
    }

    protected function elapsedMsSince(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    protected function recordInventoryMeta(Server $server, string $status, ?string $error, int $durationMs): void
    {
        $meta = $server->meta ?? [];
        $meta['systemd_inventory_last_at'] = now()->toIso8601String();
        $meta['systemd_inventory_last_status'] = $status;
        $meta['systemd_inventory_last_error'] = $error;
        $meta['systemd_inventory_last_duration_ms'] = $durationMs;
        $server->forceFill(['meta' => $meta])->saveQuietly();
    }
}
