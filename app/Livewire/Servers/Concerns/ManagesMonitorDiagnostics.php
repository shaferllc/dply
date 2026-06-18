<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\UpgradeGuestMetricsScriptJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Insights\Services\InsightCorrelationService;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Services\Servers\ServerMetricsGuestPushVerifier;
use App\Services\Servers\ServerMetricsGuestScript;
use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesMonitorDiagnostics
{


    /**
     * Banner status derived from the queued cache payload (queued/running/finished/failed)
     * or the inline-path success/error state. Empty string means "no banner".
     */
    public function getDiagnosticsBannerStatusProperty(): string
    {
        if ($this->servicesRemoteTaskId !== null && $this->servicesRemoteTaskId !== '') {
            $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->servicesRemoteTaskId));
            if (is_array($payload)) {
                return match ((string) ($payload['status'] ?? '')) {
                    'queued' => 'queued',
                    'running' => 'running',
                    'finished' => 'completed',
                    'failed' => 'failed',
                    default => 'running',
                };
            }

            return 'running';
        }
        if (is_string($this->remote_error) && $this->remote_error !== '') {
            return 'failed';
        }
        if (is_string($this->remote_output) && $this->remote_output !== '') {
            return 'completed';
        }

        return '';
    }

    /**
     * Splits the shared $remote_output string into the banner's expected list<string> shape.
     * Empty array when no transcript is available yet.
     *
     * @return list<string>
     */
    public function getDiagnosticsBannerOutputLinesProperty(): array
    {
        if (! is_string($this->remote_output) || $this->remote_output === '') {
            return [];
        }

        return explode("\n", $this->remote_output);
    }

    /**
     * Clears the diagnostics banner and any queued cache entry so it can dismiss cleanly.
     * Safe to call when no banner is showing — no-op.
     */
    public function dismissDiagnosticsBanner(): void
    {
        $this->authorize('view', $this->server);

        if ($this->servicesRemoteTaskId !== null && $this->servicesRemoteTaskId !== '') {
            Cache::forget(ServerManageRemoteSshJob::cacheKey($this->servicesRemoteTaskId));
            $this->servicesRemoteTaskId = null;
        }
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = null;
    }

    public function verifyGuestPush(): void
    {
        $this->authorize('view', $this->server);
        $this->metrics_error = null;

        if (! $this->serverOpsReady()) {
            $this->metrics_error = __('Provisioning and SSH must be ready before verifying guest push.');

            return;
        }

        $meta = $this->server->meta ?? [];
        if (! ($meta['monitoring_ssh_reachable'] ?? false)) {
            $this->metrics_error = __('Dply could not reach this server over SSH the last time we checked. Recheck status first, then verify guest push.');

            return;
        }

        try {
            $server = $this->server->fresh();
            $summary = app(ServerMetricsGuestPushVerifier::class)->refreshRemoteState($server);
            $queuedRepairs = [];

            if (
                ! $summary['configured']
                || ! $summary['cron_current']
                || ! $summary['callback_env_deployed']
                || ! $summary['cron_installed']
            ) {
                app(ServerMetricsGuestPushService::class)->ensureConfigured($server->fresh());
                $queuedRepairs[] = __('callback env and cron sync');
            }

            if (! $summary['script_current']) {
                UpgradeGuestMetricsScriptJob::dispatch(
                    $server->id,
                    app(ServerMetricsGuestScript::class)->bundledSha256()
                );
                $queuedRepairs[] = __('monitor script repair');
            }

            $this->toastSuccess($queuedRepairs === []
                ? __('Guest monitoring wiring verified.')
                : __('Monitor rechecked. Queued: :items.', ['items' => implode(', ', $queuedRepairs)]));

            $this->server->refresh();
        } catch (\Throwable $e) {
            $this->metrics_error = $e->getMessage();
        }
    }

    public function inspectMetricsCallbackEnv(): void
    {
        $this->authorize('view', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = 'inspect';

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before inspecting the callback env.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $server = $this->server->fresh();
        $sshUser = trim((string) $server->ssh_user) ?: 'root';
        $script = str_replace('__SSH_USER__', addslashes($sshUser), <<<'BASH'
TARGET_USER="__SSH_USER__"
CURRENT_ENV="$HOME/.dply/metrics-callback.env"
TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6 2>/dev/null || true)"
if [ -z "$TARGET_HOME" ] && [ "$TARGET_USER" = "root" ]; then
  TARGET_HOME="/root"
fi
TARGET_ENV=""
if [ -n "$TARGET_HOME" ]; then
  TARGET_ENV="$TARGET_HOME/.dply/metrics-callback.env"
fi

ENV_FILE=""
if [ -f "$CURRENT_ENV" ]; then
  ENV_FILE="$CURRENT_ENV"
elif [ -n "$TARGET_ENV" ] && [ -f "$TARGET_ENV" ]; then
  ENV_FILE="$TARGET_ENV"
fi

if [ -z "$ENV_FILE" ]; then
  if [ -n "$TARGET_ENV" ]; then
    echo "metrics-callback.env is missing at $CURRENT_ENV and $TARGET_ENV"
  else
    echo "metrics-callback.env is missing at $CURRENT_ENV"
  fi
  exit 0
fi

echo "Inspecting: $ENV_FILE"
awk '
  BEGIN { FS="="; OFS="=" }
  /^DPLY_METRICS_CALLBACK_TOKEN=/ {
    token = substr($0, length("DPLY_METRICS_CALLBACK_TOKEN=") + 1)
    if (length(token) > 12) {
      print "DPLY_METRICS_CALLBACK_TOKEN", substr(token, 1, 6) "..." substr(token, length(token) - 3)
    } else {
      print "DPLY_METRICS_CALLBACK_TOKEN", "***"
    }
    next
  }
  { print $0 }
' "$ENV_FILE"
BASH);

        try {
            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedServicesScript(
                    $server,
                    'metrics-callback-env:inspect',
                    $script,
                    60,
                    __('Callback env inspection finished.'),
                    __('TaskRunner (SSH)').' — '.__('Inspect callback env'),
                );

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'metrics-callback-env:inspect',
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                60,
            );

            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->remote_error = null;
            $this->toastSuccess(__('Callback env inspection finished.'));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    public function runMonitorCallbackDiagnostics(): void
    {
        $this->authorize('view', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = 'diagnostics';

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before running callback diagnostics.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $server = $this->server->fresh();
        $sshUser = trim((string) $server->ssh_user) ?: 'root';
        $script = str_replace('__SSH_USER__', addslashes($sshUser), <<<'BASH'
TARGET_USER="__SSH_USER__"
TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6 2>/dev/null || true)"
if [ -z "$TARGET_HOME" ] && [ "$TARGET_USER" = "root" ]; then
  TARGET_HOME="/root"
fi
if [ -z "$TARGET_HOME" ]; then
  TARGET_HOME="$HOME"
fi

SCRIPT_PATH="$TARGET_HOME/.dply/bin/server-metrics-snapshot.py"
ENV_FILE="$TARGET_HOME/.dply/metrics-callback.env"

echo "== monitor paths =="
echo "target_user=$TARGET_USER"
echo "target_home=$TARGET_HOME"
echo "script_path=$SCRIPT_PATH"
echo "env_file=$ENV_FILE"
echo

echo "== files =="
if [ -f "$SCRIPT_PATH" ]; then
  ls -l "$SCRIPT_PATH"
else
  echo "missing: $SCRIPT_PATH"
fi

if [ -f "$ENV_FILE" ]; then
  echo "present: $ENV_FILE"
  awk '
    BEGIN { FS="="; OFS="=" }
    /^DPLY_METRICS_CALLBACK_TOKEN=/ {
      token = substr($0, length("DPLY_METRICS_CALLBACK_TOKEN=") + 1)
      if (length(token) > 12) {
        print "DPLY_METRICS_CALLBACK_TOKEN", substr(token, 1, 6) "..." substr(token, length(token) - 3)
      } else {
        print "DPLY_METRICS_CALLBACK_TOKEN", "***"
      }
      next
    }
    { print $0 }
  ' "$ENV_FILE"
else
  echo "missing: $ENV_FILE"
fi
echo

echo "== cron =="
if command -v sudo >/dev/null 2>&1; then
  sudo -n -u "$TARGET_USER" crontab -l 2>/dev/null | sed -n '/# BEGIN DPLY METRICS GUEST/,/# END DPLY METRICS GUEST/p'
else
  (crontab -l 2>/dev/null || true) | sed -n '/# BEGIN DPLY METRICS GUEST/,/# END DPLY METRICS GUEST/p'
fi
echo

echo "== local script run =="
if [ -x "$SCRIPT_PATH" ]; then
  timeout 20 env -i HOME="$TARGET_HOME" PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" python3 "$SCRIPT_PATH" 2>&1 | sed -n '1,20p'
else
  echo "script not executable or missing"
fi
echo

echo "== callback probe =="
if [ -f "$ENV_FILE" ]; then
  set -a
  . "$ENV_FILE"
  set +a
  if command -v curl >/dev/null 2>&1; then
    curl -I -sS --max-time 15 "$DPLY_METRICS_CALLBACK_URL" 2>&1 | sed -n '1,20p'
  else
    echo "curl not installed"
  fi
else
  echo "callback env missing"
fi
BASH);

        try {
            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedServicesScript(
                    $server,
                    'monitor-callback:diagnostics',
                    $script,
                    120,
                    __('Monitor callback diagnostics finished.'),
                    __('TaskRunner (SSH)').' — '.__('Run callback diagnostics'),
                );

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'monitor-callback:diagnostics',
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                120,
            );

            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->remote_error = null;
            $this->toastSuccess(__('Monitor callback diagnostics finished.'));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    public function repairMonitorNow(): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;
        $this->remote_output_kind = 'repair';

        if ($this->currentUserIsDeployer()) {
            $msg = __('Deployers cannot repair monitor installs on servers.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        if (! $this->serverOpsReady()) {
            $msg = __('Provisioning and SSH must be ready before repairing the monitor.');
            $this->remote_error = $msg;
            $this->toastError($msg);

            return;
        }

        $server = $this->server->fresh();
        $guestScript = app(ServerMetricsGuestScript::class);
        $guestPush = app(ServerMetricsGuestPushService::class);

        if ($guestPush->plainTokenForDeploy($server) === null) {
            $guestPush->generateAndStoreToken($server);
            $server = $server->fresh();
        }

        $script = trim($guestScript->guestScriptDeployOnlyScript())
            ."\n\n".trim($guestPush->writeCallbackEnvFileBash($server))
            ."\n\n".trim($guestPush->installGuestMetricsCronBash());

        try {
            $out = $this->runManageInlineBash(
                $server,
                'monitor-repair-now',
                $script,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                120,
            );

            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->remote_error = null;

            $meta = $server->fresh()->meta ?? [];
            $meta['monitoring_callback_env_deployed'] = true;
            $meta['monitoring_callback_env_deployed_at'] = now()->toIso8601String();
            $meta['monitoring_guest_push_callback_url'] = $guestPush->guestPushUrl();
            $meta['monitoring_guest_cron_installed_at'] = now()->toIso8601String();
            $meta['monitoring_guest_push_cron_expression'] = $guestPush->normalizedGuestPushCronExpression();
            $server->forceFill(['meta' => $meta])->saveQuietly();

            app(ServerMetricsGuestPushVerifier::class)->refreshRemoteState($server->fresh());
            $this->server->refresh();
            $this->toastSuccess(__('Monitor repaired directly over SSH.'));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->remote_error = $msg;
            $this->toastError($msg);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function summarizeLatestPayload(array $payload): array
    {
        $swapTotalKb = isset($payload['swap_total_kb']) ? (int) $payload['swap_total_kb'] : null;
        $swapUsedKb = isset($payload['swap_used_kb']) ? (int) $payload['swap_used_kb'] : null;

        return [
            'memory_available_bytes' => isset($payload['mem_available_kb']) ? (int) $payload['mem_available_kb'] * 1024 : null,
            'memory_total_bytes' => isset($payload['mem_total_kb']) ? (int) $payload['mem_total_kb'] * 1024 : null,
            'swap_total_bytes' => $swapTotalKb !== null ? $swapTotalKb * 1024 : null,
            'swap_used_bytes' => $swapUsedKb !== null ? $swapUsedKb * 1024 : null,
            'swap_pct' => $swapTotalKb && $swapUsedKb !== null
                ? round(($swapUsedKb / $swapTotalKb) * 100, 1)
                : null,
            'disk_free_bytes' => isset($payload['disk_free_bytes']) ? (int) $payload['disk_free_bytes'] : null,
            'inode_pct_root' => isset($payload['inode_pct_root']) ? (float) $payload['inode_pct_root'] : null,
            'cpu_count' => isset($payload['cpu_count']) ? (int) $payload['cpu_count'] : null,
            'load_per_cpu_1m' => isset($payload['load_per_cpu_1m']) ? (float) $payload['load_per_cpu_1m'] : null,
            'uptime_seconds' => isset($payload['uptime_seconds']) ? (int) $payload['uptime_seconds'] : null,
            'rx_bytes_per_sec' => isset($payload['rx_bytes_per_sec']) ? (float) $payload['rx_bytes_per_sec'] : null,
            'tx_bytes_per_sec' => isset($payload['tx_bytes_per_sec']) ? (float) $payload['tx_bytes_per_sec'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deploymentContext(Server $server): array
    {
        /** @var EloquentCollection<int, Site> $sites */
        $sites = $server->sites()
            ->with(['deployments' => fn ($query) => $query->limit(1)])
            ->orderBy('name')
            ->get();

        $latestDeployment = SiteDeployment::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->first();

        $latestFailedDeployment = SiteDeployment::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->where('status', SiteDeployment::STATUS_FAILED)
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->first();

        $activeSiteCount = $sites->filter(fn ($site) => $site->status !== Site::STATUS_ERROR)->count();
        $siteSummaries = $sites->take(3)->map(function ($site): array {
            $deployment = $site->deployments->first();

            return [
                'name' => $site->name,
                'status' => $site->status,
                'last_deploy_at' => $deployment?->finished_at?->toIso8601String(),
                'last_deploy_status' => $deployment?->status,
            ];
        })->values();

        return [
            'site_count' => $sites->count(),
            'active_site_count' => $activeSiteCount,
            'latest_deployment' => $latestDeployment,
            'latest_failed_deployment' => $latestFailedDeployment,
            'latest_correlation' => app(InsightCorrelationService::class)->correlateForNewFinding($server),
            'site_summaries' => $siteSummaries,
        ];
    }
}
