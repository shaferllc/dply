<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerManageSshExecutor;
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
 * Deferred metrics-agent install. Equivalent to
 * {@see \App\Services\Servers\ServerProvisionCommandBuilder::metricsAgent()}
 * but runs over SSH AFTER the bash provision journey is reported "ready",
 * saving 30–60s of journey wall-clock at the cost of monitoring being
 * unavailable for ~1 minute after success.
 *
 * Pairs with {@see DeployGuestMetricsCallbackEnvJob}, which writes the
 * env file + crontab block once the agent files exist on the host. The
 * RunSetupScriptJob success path dispatches both: this one first, the
 * env/cron deploy second.
 */
class InstallMetricsAgentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

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
        return 'install-metrics-agent:'.$this->serverId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $remote,
        ServerMetricsGuestScript $script,
    ): void {
        if (! (bool) config('server_provision.install_metrics_agent', true)) {
            return;
        }

        $server = Server::query()->find($this->serverId);
        if ($server === null || ! $server->isVmHost()) {
            return;
        }

        if (! empty($server->meta['metrics_agent_installed_at'] ?? null)) {
            return;
        }

        $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($deployUser === '' || $deployUser === 'root' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $deployUser)) {
            return;
        }

        try {
            $deployFragment = $script->guestScriptDeployOnlyScript();
        } catch (Throwable $e) {
            Log::warning('server_metrics.install_agent.script_unavailable', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // Install python3-minimal as root, then drop the snapshot script
        // into the deploy user's home — mirrors the inline metricsAgent()
        // bash from ServerProvisionCommandBuilder line-for-line.
        $bash = <<<BASH
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

if ! command -v python3 >/dev/null 2>&1; then
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y python3-minimal
fi

sudo -u {$deployUser} -H bash -s <<'DPLY_METRICS_DEPLOY'
{$deployFragment}
DPLY_METRICS_DEPLOY
BASH;

        try {
            $out = $remote->runInlineBash(
                $server,
                'install-metrics-agent',
                $bash,
                120,
                false,
            );

            if (! $out->isSuccessful()) {
                Log::warning('server_metrics.install_agent.failed', [
                    'server_id' => $this->serverId,
                    'exit' => $out->getExitCode(),
                    'output' => mb_substr(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()), 0, 1500),
                ]);

                return;
            }

            $meta = $server->fresh()?->meta ?? [];
            $meta['metrics_agent_installed_at'] = now()->toIso8601String();
            $meta['monitoring_python_installed'] = true;
            $server->forceFill(['meta' => $meta])->saveQuietly();

            // Once the agent script + python are in place, wire the env
            // file + crontab block so it actually runs on a schedule.
            DeployGuestMetricsCallbackEnvJob::dispatch($this->serverId);
        } catch (Throwable $e) {
            Log::warning('server_metrics.install_agent.exception', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
