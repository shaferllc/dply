<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Services\Servers\ServerMetricsGuestScript;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deploys the latest resources/server-scripts/server-metrics-snapshot.py when it differs from the guest copy.
 */
class UpgradeGuestMetricsScriptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public string $serverId,
        public string $expectedBundledSha256,
    ) {
        $queue = config('server_metrics.guest_script.upgrade_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return 'upgrade-guest-metrics:'.$this->serverId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $remote,
        ServerMetricsGuestScript $guest,
        ServerMetricsGuestPushService $guestPush,
    ): void {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        if ($guest->bundledSha256() !== $this->expectedBundledSha256) {
            return;
        }

        $script = $guest->guestScriptDeployOnlyScript();

        try {
            $out = $remote->runInlineBash(
                $server,
                'guest-metrics-script-upgrade',
                $script,
                120,
                false,
            );
            if (! $out->isSuccessful()) {
                Log::warning('server_metrics.guest_script_upgrade_failed', [
                    'server_id' => $this->serverId,
                    'exit' => $out->getExitCode(),
                    'output' => mb_substr(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()), 0, 2000),
                ]);

                return;
            }

            $meta = $server->meta ?? [];
            $meta['monitoring_guest_script_sha256'] = $this->expectedBundledSha256;
            $meta['monitoring_guest_script_upgraded_at'] = now()->toIso8601String();
            $server->forceFill(['meta' => $meta])->saveQuietly();

            $guestPush->ensureConfigured($server->fresh());
        } catch (Throwable $e) {
            Log::warning('server_metrics.guest_script_upgrade_exception', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
