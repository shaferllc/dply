<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SchedulerWrapperScript;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestScript;
use App\Services\Servers\ServerProvisionCommandBuilder;
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
 * {@see ServerProvisionCommandBuilder::metricsAgent()}
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

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'install-metrics-agent:'.$this->serverId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $remote,
        ServerMetricsGuestScript $script,
        SchedulerWrapperScript $wrapper,
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
            $wrapperFragment = $wrapper->installBashFragment($deployUser);
        } catch (Throwable $e) {
            Log::warning('server_metrics.install_agent.script_unavailable', [
                'server_id' => $this->serverId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // Install python3-minimal as root, drop the snapshot script as the
        // deploy user (into $HOME/.dply/bin), then install the scheduler
        // wrapper system-wide as root (writes to /usr/local/bin via sudo
        // inside the deploy-user heredoc). Both deploy steps are
        // idempotent — re-running the install job after a wrapper upgrade
        // just verifies the SHA + replaces the binary atomically.
        $bash = <<<BASH
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

if ! command -v python3 >/dev/null 2>&1; then
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y python3-minimal
fi

sudo -u {$deployUser} -H bash -s <<'DPLY_METRICS_DEPLOY'
{$deployFragment}
{$wrapperFragment}
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

            // Stamp the bundled SHA into meta so the Monitor workspace's
            // "Agent version unknown" pill flips to "Agent up to date"
            // immediately — without it, the verifier has nothing to
            // compare bundledSha256() against until someone clicks
            // Repair monitor now and we SSH the box to read the SHA.
            // We just deployed the bundled script over SSH ourselves;
            // the file on disk is by-definition the bundled version,
            // so we can write the SHA without another round-trip.
            try {
                $bundledSha = $script->bundledSha256();
            } catch (Throwable) {
                $bundledSha = null;
            }

            $meta = $server->fresh()?->meta ?? [];
            $meta['metrics_agent_installed_at'] = now()->toIso8601String();
            $meta['monitoring_python_installed'] = true;
            if ($bundledSha !== null) {
                $meta['monitoring_guest_script_sha'] = $bundledSha;
                $meta['monitoring_guest_verify_checked_at'] = now()->toIso8601String();
            }
            try {
                $meta['scheduler_wrapper_sha'] = $wrapper->bundledSha256();
                $meta['scheduler_wrapper_installed_at'] = now()->toIso8601String();
            } catch (Throwable) {
                // Failure to hash here means the wrapper file is missing
                // locally — already would have failed in the try{} above.
            }
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
