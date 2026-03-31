<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes ~/.dply/metrics-callback.env and installs a marked user crontab block so the guest script can push on a schedule.
 */
class DeployGuestMetricsCallbackEnvJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(
        public string $serverId,
    ) {
        $queue = config('server_metrics.guest_push.deploy_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return 'deploy-guest-metrics-callback-env:'.$this->serverId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $remote,
        ServerMetricsGuestPushService $push,
    ): void {
        if (! $push->isEnabled()) {
            return;
        }

        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        if ($push->plainTokenForDeploy($server) === null) {
            $push->generateAndStoreToken($server);
            $server = $server->fresh();
        }

        $envBash = $push->writeCallbackEnvFileBash($server);
        if (str_starts_with(trim($envBash), '# skip')) {
            return;
        }

        $bash = trim($envBash)."\n\n".$push->installGuestMetricsCronBash();

        try {
            $out = $remote->runInlineBash(
                $server,
                'guest-metrics-push-sync',
                $bash,
                90,
                false,
            );
            if (! $out->isSuccessful()) {
                Log::warning('server_metrics.callback_env_deploy_failed', [
                    'server_id' => $this->serverId,
                    'exit' => $out->getExitCode(),
                    'output' => mb_substr(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()), 0, 1500),
                ]);

                return;
            }

            $meta = $server->meta ?? [];
            $meta['monitoring_callback_env_deployed'] = true;
            $meta['monitoring_callback_env_deployed_at'] = now()->toIso8601String();
            $meta['monitoring_guest_cron_installed_at'] = now()->toIso8601String();
            $meta['monitoring_guest_push_cron_expression'] = $push->normalizedGuestPushCronExpression();
            $server->forceFill(['meta' => $meta])->saveQuietly();
        } catch (Throwable $e) {
            Log::warning('server_metrics.callback_env_deploy_exception', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
