<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerSystemdInventoryRecorder;
use App\Services\Servers\ServerSystemdServicesCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncServerSystemdServicesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout;

    public function __construct(
        public string $serverId,
    ) {
        $this->timeout = max(120, (int) config('server_services.systemd_inventory_timeout', 300) + 90);
        $queue = config('server_services.sync_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return 'server-systemd-inventory:'.$this->serverId;
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
            return;
        }

        $started = microtime(true);
        $catalog = app(ServerSystemdServicesCatalog::class);
        $script = $catalog->buildInventoryScript($server);
        $invTimeout = (int) config('server_services.systemd_inventory_timeout', 300);

        try {
            $out = $executor->runInlineBash(
                $server,
                'services-systemd-inventory-job',
                $script,
                $invTimeout,
                static function (): void {},
            );
        } catch (\Throwable $e) {
            Log::warning('systemd inventory ssh failed', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);
            $this->recordInventoryMeta($server->fresh(), 'failed', $e->getMessage(), $this->elapsedMsSince($started));

            return;
        }

        $raw = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
        if (! $out->isSuccessful()) {
            Log::warning('systemd inventory non-zero exit', [
                'server_id' => $this->serverId,
                'exit' => $out->getExitCode(),
            ]);
            $this->recordInventoryMeta(
                $server->fresh(),
                'failed',
                __('Remote command exited with code :code.', ['code' => (string) $out->getExitCode()]),
                $this->elapsedMsSince($started),
            );

            return;
        }

        try {
            $recorder->persistInventoryFromRawOutput($server->fresh(), $raw);
            $this->recordInventoryMeta($server->fresh(), 'success', null, $this->elapsedMsSince($started));
        } catch (\Throwable $e) {
            Log::error('systemd inventory persist failed', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);
            $this->recordInventoryMeta($server->fresh(), 'failed', $e->getMessage(), $this->elapsedMsSince($started));

            throw $e;
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
