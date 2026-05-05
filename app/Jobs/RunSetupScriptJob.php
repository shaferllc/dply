<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Servers\CreateServerProvisionRun;
use App\Actions\Servers\UpsertServerProvisionArtifact;
use App\Enums\ServerProvider;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\InstallMetricsAgentJob;
use App\Jobs\RunServerInsightsJob;
use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTaskModel;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\TrackTaskInBackground;
use App\Observers\TaskRunnerTaskObserver;
use App\Services\Servers\Bootstrap\ServerBootstrapStrategyResolver;
use App\Services\Servers\FirewallRuleTemplateApplicator;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Support\Servers\ProvisionPipelineLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs stack provisioning from servers.meta (wizard choices), then optional setup_scripts recipes.
 *
 * Uses TaskRunner {@see TaskDispatcher::runInBackgroundWithModel} with {@see TrackTaskInBackground}
 * so the remote wrapper can POST signed webhooks (update-output, mark-as-finished, …). Completion
 * is applied to {@see Server} when the task row moves to a terminal status
 * ({@see TaskRunnerTaskObserver}).
 */
class RunSetupScriptJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(
        public Server $server
    ) {}

    /** Cap on automatic retries for transient provisioning failures (network/apt timeouts, etc.). */
    public const MAX_AUTO_RETRY_ATTEMPTS = 3;

    /**
     * Apply provision outcome to the server (setup_status, optional deploy ssh_user).
     * On transient failures, schedules an automatic retry with backoff up to MAX_AUTO_RETRY_ATTEMPTS.
     */
    public static function applyProvisionOutcomeToServer(Server $server, bool $success): void
    {
        $server->refresh();

        if ($success) {
            $updates = [
                'setup_status' => Server::SETUP_STATUS_DONE,
            ];
            if ($server->hasDedicatedOperationalSshPrivateKey()) {
                $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');
                if ($deployUser !== '' && $deployUser !== 'root'
                    && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $deployUser)) {
                    $updates['ssh_user'] = $deployUser;
                }
            }
            // Clear any prior auto-retry markers on success.
            $meta = $server->meta ?? [];
            unset($meta['auto_retry_at'], $meta['auto_retry_attempt'], $meta['auto_retry_max']);
            $updates['meta'] = $meta;
            $server->update($updates);

            // Email server credentials to the creator IF the org has the
            // toggle on. Opt-in only: most operators don't want
            // connection blocks landing in mailboxes by default. The
            // email itself only carries host/port/user — the SSH key
            // download stays gated behind an authenticated dashboard
            // session (see ServerProvisionedCredentialsNotification).
            $organization = $server->organization;
            $creator = $server->user;
            if ($organization
                && $organization->email_server_credentials_enabled
                && $creator
                && filled($creator->email)
            ) {
                $creator->notify(new \App\Notifications\ServerProvisionedCredentialsNotification($server->fresh() ?? $server));
            }

            // Kick off insights immediately so the workspace lands with a
            // populated heartbeat / metrics-missing baseline instead of an
            // empty state that requires the operator to hit "Refresh"
            // before anything appears. Job no-ops if the server isn't
            // ready yet, so the dispatch is safe even on edge timing.
            if (config('insights.queue_after_install', true) && $server->isVmHost()) {
                RunServerInsightsJob::dispatch($server->id);
            }

            // Fire an immediate health check so the workspace Overview's
            // Health tile flips from "Not checked yet" to a real result
            // within seconds of provisioning finishing, instead of
            // waiting up to 5 minutes for the recurring scheduler at
            // bootstrap/app.php to catch up. The job is idempotent —
            // worst case the scheduler re-checks 5 minutes later
            // anyway and overwrites with fresh data.
            if (! empty($server->ip_address) && $server->isVmHost()) {
                CheckServerHealthJob::dispatch($server);
            }

            // Wire up the metrics push pipeline. Two paths converge here
            // depending on whether the inline metrics step ran during
            // the bash provision:
            //   - inline=true  → bash already installed Python + the
            //     snapshot script. syncPushArtifactsAfterInstall just
            //     writes the env file + crontab block.
            //   - inline=false (default) → bash skipped the agent.
            //     Dispatch InstallMetricsAgentJob to SSH the install
            //     bash on a separate connection AFTER the journey reads
            //     "ready". That job dispatches the env/cron deploy on
            //     success, so the post-install state ends up identical
            //     either way; the user just gets ~30–60s back on the
            //     wall-clock.
            if ((bool) config('server_provision.install_metrics_agent', true)
                && ! empty($server->ip_address)
                && $server->isVmHost()
            ) {
                if ((bool) config('server_provision.install_metrics_agent_inline', false)) {
                    app(ServerMetricsGuestPushService::class)->syncPushArtifactsAfterInstall($server);
                } else {
                    InstallMetricsAgentJob::dispatch((string) $server->id)
                        ->delay(now()->addSeconds(15));
                }
            }

            // Mirror the bash provision script's UFW defaults (SSH on
            // the server's ssh_port + HTTP/HTTPS for VM hosts) into
            // server_firewall_rules so the dashboard reflects what's
            // actually on the host. Idempotent — the applicator dedupes
            // by (port, protocol, source, action) so reruns are no-ops.
            try {
                app(FirewallRuleTemplateApplicator::class)
                    ->seedDefaultsForServer($server->fresh() ?? $server, $server->user);
            } catch (\Throwable $e) {
                // Seeding is best-effort: a failure here shouldn't fail
                // the whole provision job. Log and move on; the
                // workspace's "Apply" button can recreate rules later.
                ProvisionPipelineLog::warning('server.provision.firewall_seed_failed', $server, [
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        if (static::tryScheduleAutoRetry($server)) {
            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['auto_retry_at'], $meta['auto_retry_attempt'], $meta['auto_retry_max']);
        $server->update([
            'setup_status' => Server::SETUP_STATUS_FAILED,
            'meta' => $meta,
        ]);
    }

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
            '/E: Unable to (?:fetch|locate) /i',
            '/Sub-process .* returned an error code/i',
            '/Hash Sum mismatch/i',
            '/network is unreachable/i',
            '/No route to host/i',
            '/SSL_connect: Connection reset/i',
            '/curl: \(\d+\) (?:Could not|Operation timed out|Failed to|Recv failure)/i',
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

        foreach ($transientPatterns as $pattern) {
            if (preg_match($pattern, $output) === 1) {
                return true;
            }
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

        if ($server->provider === ServerProvider::FlyIo) {
            return false;
        }

        $meta = $server->meta ?? [];
        $hasStack = is_array($meta) && filled($meta['server_role'] ?? null);
        $hasOptionalScript = filled($server->setup_script_key) && $server->setup_script_key !== 'none';

        return $hasStack || $hasOptionalScript;
    }

    public function handle(
        ServerBootstrapStrategyResolver $bootstrapStrategies,
        CreateServerProvisionRun $createProvisionRun,
        UpsertServerProvisionArtifact $upsertProvisionArtifact,
        TaskDispatcher $dispatcher,
    ): void {
        $server = $this->server->fresh();
        if (! $server) {
            Log::debug('server.provision.run_setup.skip_missing_server', [
                'server_id' => $this->server->id,
            ]);

            return;
        }

        if (! static::shouldDispatch($server)) {
            ProvisionPipelineLog::debug('server.provision.run_setup.skip_should_dispatch', $server, [
                'phase' => 'gate',
                'setup_script_key' => $server->setup_script_key,
            ]);

            return;
        }

        $strategy = $bootstrapStrategies->for($server);
        $commands = $strategy->build($server);

        $scripts = config('setup_scripts.scripts', []);
        if (filled($server->setup_script_key) && $server->setup_script_key !== 'none') {
            $recipe = $scripts[$server->setup_script_key] ?? null;
            $extra = is_array($recipe) ? ($recipe['commands'] ?? []) : [];
            foreach ($extra as $command) {
                if (is_string($command) && trim($command) !== '') {
                    $commands[] = trim($command);
                }
            }
        }

        if ($commands === []) {
            ProvisionPipelineLog::debug('server.provision.run_setup.skip_no_commands', $server, [
                'phase' => 'build',
                'setup_script_key' => $server->setup_script_key,
            ]);

            return;
        }

        $timeout = (int) config('server_provision.remote_script_timeout_seconds', 3600);

        ProvisionPipelineLog::info('server.provision.run_setup.script_built', $server, [
            'phase' => 'build',
            'strategy' => $strategy::class,
            'command_count' => count($commands),
            'remote_timeout_seconds' => $timeout,
            'setup_script_key' => $server->setup_script_key,
        ]);

        $body = '';
        foreach ($commands as $line) {
            $body .= rtrim($line)."\n";
        }

        $taskModel = new TaskRunnerTaskModel([
            'name' => 'Server stack provision',
            'action' => 'provision_stack',
            'script' => 'dply-provision-stack.sh',
            'script_content' => $body,
            'timeout' => $timeout,
            'user' => 'root',
            'server_id' => $server->id,
            'created_by' => $server->user_id,
            'status' => TaskStatus::Pending,
        ]);
        $taskModel->save();

        ProvisionPipelineLog::info('server.provision.run_setup.task_row_created', $server, [
            'phase' => 'persist_task',
            'task_runner_task_id' => $taskModel->id,
        ]);

        $run = $createProvisionRun->handle($server, $taskModel);
        foreach ($strategy->buildArtifacts($server) as $artifact) {
            $upsertProvisionArtifact->handle(
                $run,
                $artifact['type'],
                $artifact['label'],
                $artifact['content'],
                $artifact['metadata'],
                $artifact['key'],
            );
        }

        $body = $this->provisionScriptPreamble($taskModel->id, $run).$body;
        $taskModel->update(['script_content' => $body]);

        $task = AnonymousTask::script('Server stack provision', $body, ['timeout' => $timeout]);
        $task->setUser('root');

        $meta = $server->meta ?? [];
        $meta['provision_task_id'] = (string) $taskModel->id;
        $meta['provision_run_id'] = (string) $run->id;
        $server->update([
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'meta' => $meta,
        ]);

        ProvisionPipelineLog::info('server.provision.run_setup.dispatching_remote', $server, [
            'phase' => 'task_runner',
            'task_runner_task_id' => $taskModel->id,
            'provision_run_id' => $run->id,
            'setup_status' => Server::SETUP_STATUS_RUNNING,
        ]);

        try {
            $task->setTaskModel($taskModel);

            $tracked = $dispatcher->wrapWithTrackTaskInBackground($task, $taskModel);
            $tracked->setTaskModel($taskModel);

            $taskModel->update([
                'instance' => TaskRunnerTaskModel::storeInstance($tracked),
            ]);

            $output = $dispatcher->runInBackgroundWithModel($tracked, $taskModel);

            if ($output === null || ! $output->isSuccessful()) {
                ProvisionPipelineLog::warning('server.provision.run_setup.background_start_failed', $server, [
                    'phase' => 'task_runner',
                    'task_runner_task_id' => $taskModel->id,
                    'provision_run_id' => $run->id,
                    'output_null' => $output === null,
                    'successful' => $output?->isSuccessful(),
                ]);
                $taskModel->update([
                    'status' => TaskStatus::Failed,
                    'completed_at' => now(),
                    'output' => $output !== null ? $output->getBuffer() : 'Background start returned no output.',
                ]);
                static::applyProvisionOutcomeToServer($server, false);
            } else {
                ProvisionPipelineLog::info('server.provision.run_setup.background_started', $server, [
                    'phase' => 'task_runner',
                    'task_runner_task_id' => $taskModel->id,
                    'provision_run_id' => $run->id,
                ]);
            }
        } catch (TaskExecutionException $e) {
            ProvisionPipelineLog::warning('server.provision.run_setup.task_runner_exception', $server, [
                'phase' => 'task_runner',
                'task_runner_task_id' => $taskModel->id,
                'setup_script_key' => $server->setup_script_key,
                'error' => $e->getMessage(),
                'caused_by' => $e->getPrevious()?->getMessage(),
            ]);
            $taskModel->update([
                'status' => TaskStatus::Failed,
                'exit_code' => null,
                'output' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            static::applyProvisionOutcomeToServer($server, false);
        } catch (\Throwable $e) {
            ProvisionPipelineLog::warning('server.provision.run_setup.failed', $server, [
                'phase' => 'task_runner',
                'task_runner_task_id' => $taskModel->id,
                'setup_script_key' => $server->setup_script_key,
                'error' => $e->getMessage(),
            ]);
            $taskModel->update([
                'status' => TaskStatus::Failed,
                'exit_code' => null,
                'output' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            static::applyProvisionOutcomeToServer($server, false);
        }
    }

    private function provisionScriptPreamble(string $taskId, ServerProvisionRun $run): string
    {
        $runId = (string) $run->id;

        return <<<BASH
#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
DPLY_PROVISION_ROOT=/var/lib/dply/provision/{$runId}
DPLY_PROVISION_BACKUPS="\${DPLY_PROVISION_ROOT}/backups"
mkdir -p "\${DPLY_PROVISION_BACKUPS}"
echo "[dply] provision run {$runId} task {$taskId}"

dply_restore_backups() {
  if [ ! -d "\${DPLY_PROVISION_BACKUPS}" ]; then
    return 0
  fi

  while IFS= read -r statefile; do
    rel="\${statefile#\${DPLY_PROVISION_BACKUPS}/}"
    rel="\${rel%.state}"
    target="/\${rel}"
    state=\$(cat "\${statefile}")
    if [ "\${state}" = "exists" ] && [ -f "\${DPLY_PROVISION_BACKUPS}/\${rel}.bak" ]; then
      mkdir -p "\$(dirname "\${target}")"
      cp -a "\${DPLY_PROVISION_BACKUPS}/\${rel}.bak" "\${target}"
      echo "[dply-rollback] \${rel} :: restored :: Previous config restored"
    elif [ "\${state}" = "missing" ]; then
      rm -f "\${target}"
      echo "[dply-rollback] \${rel} :: removed :: New config removed"
    fi
  done < <(find "\${DPLY_PROVISION_BACKUPS}" -name '*.state' -type f 2>/dev/null)
}

dply_write_file() {
  target=\$(printf '%s' "\$1" | base64 -d)
  payload=\$(printf '%s' "\$2" | base64 -d)
  rel="\${target#/}"
  statefile="\${DPLY_PROVISION_BACKUPS}/\${rel}.state"
  backupfile="\${DPLY_PROVISION_BACKUPS}/\${rel}.bak"
  mkdir -p "\$(dirname "\${statefile}")" "\$(dirname "\${target}")"
  if [ -f "\${target}" ]; then
    cp -a "\${target}" "\${backupfile}"
    printf 'exists' > "\${statefile}"
  else
    printf 'missing' > "\${statefile}"
  fi
  printf '%s' "\${payload}" > "\${target}"
  echo "[dply-rollback] \${rel} :: checkpoint :: Backup recorded"
}

trap 'status=\$?; echo "[dply-rollback] automatic :: started :: Provision failed, attempting safe rollback"; dply_dump_dpkg_diagnostics 2>&1 || true; dply_restore_backups || true; exit \$status' ERR

# Two-phase apt-lock waiter. Cloud-init's first-boot
# unattended-upgrades commonly holds the apt lock for 5-10+ minutes
# on fresh DigitalOcean droplets, which is unacceptable wait latency
# during interactive provisioning. Strategy:
#
#   Phase 1 (0-90s): passive wait. Politely block on cloud-init and
#                    apt locks. Most well-behaved droplets clear in
#                    this window.
#   Phase 2 (>90s):  active eviction. Stop the unattended-upgrades
#                    service/timer, kill any apt-get / unattended-upgr
#                    processes still running, run dpkg --configure -a
#                    to recover any half-installed packages, then
#                    proceed. Faster than waiting 5-10 minutes for
#                    the OS to finish a background task we never
#                    asked for.
#
# Hard fail at 180s — at that point something is genuinely wedged and
# silent waiting just hides the problem.
dply_apt_locks_held() {
  fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
    || fuser /var/lib/dpkg/lock >/dev/null 2>&1 \
    || fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
    || pgrep -x apt-get >/dev/null 2>&1 \
    || pgrep -x unattended-upgr >/dev/null 2>&1
}

dply_wait_for_apt_locks() {
  # Fast-path: if our pre-empt block at script start already disabled
  # cloud-init + the auto-upgrade timers AND the locks aren't held by
  # anything else, we're good — skip the cloud-init status --wait
  # (which can sit there for 60s on droplets that booted recently).
  if ! dply_apt_locks_held; then
    return 0
  fi

  # cloud-init may still be honouring an in-flight `apt-get` from before
  # our pre-empt killed it. Try a short status wait so we don't race
  # cloud-init's last-gasp cleanup, but cap it tight (5s, not 60s).
  if command -v cloud-init >/dev/null 2>&1; then
    timeout 5 cloud-init status --wait >/dev/null 2>&1 || true
  fi

  local waited=0
  # Polite wait window before forcible eviction. Tighter than the old
  # 90s because the pre-empt block at script start should have already
  # cleared everything — if locks are still held this far in, the
  # blocking process is stuck rather than legitimately upgrading.
  local polite=15

  while dply_apt_locks_held; do
    if [ "\${waited}" -lt "\${polite}" ]; then
      echo "[dply] apt is busy (waited \${waited}s — likely cloud-init unattended-upgrades); polite wait, retry in 5s..."
      sleep 5
      waited=\$((waited + 5))
      continue
    fi

    if [ "\${waited}" -eq "\${polite}" ]; then
      echo "[dply] apt still busy after \${polite}s — evicting unattended-upgrades to unblock provisioning."
      # Cloud-init too — its modules can re-spawn apt children that
      # the pre-empt block at script start may have missed.
      systemctl stop cloud-init.target cloud-config.service cloud-final.service cloud-init.service cloud-init-local.service >/dev/null 2>&1 || true
      systemctl stop unattended-upgrades.service >/dev/null 2>&1 || true
      systemctl disable unattended-upgrades.service >/dev/null 2>&1 || true
      systemctl stop apt-daily.timer apt-daily.service >/dev/null 2>&1 || true
      systemctl stop apt-daily-upgrade.timer apt-daily-upgrade.service >/dev/null 2>&1 || true
      pkill -TERM -x unattended-upgr >/dev/null 2>&1 || true
      pkill -TERM -x apt-get >/dev/null 2>&1 || true
      pkill -TERM -x apt >/dev/null 2>&1 || true
      sleep 2
      pkill -KILL -x unattended-upgr >/dev/null 2>&1 || true
      pkill -KILL -x apt-get >/dev/null 2>&1 || true
      pkill -KILL -x apt >/dev/null 2>&1 || true
      # Drop dpkg lock files left behind by SIGKILL.
      rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock >/dev/null 2>&1 || true
      # Half-installed package recovery after a kill -9.
      dpkg --configure -a >/dev/null 2>&1 || true
      waited=\$((waited + 5))
      sleep 2
      continue
    fi

    if [ "\${waited}" -ge 180 ]; then
      echo "[dply] ERROR: apt lock still held 90s after eviction — something is wedged." >&2
      echo "[dply] Diagnose on the host:" >&2
      echo "[dply]   ps auxf | grep -E 'apt|unattended|dpkg'" >&2
      echo "[dply]   lsof /var/lib/dpkg/lock-frontend" >&2
      return 1
    fi

    echo "[dply] post-eviction wait (\${waited}s); retry in 5s..."
    sleep 5
    waited=\$((waited + 5))
  done
}

# Heal any half-configured dpkg state left behind by a prior failed
# install, an OOM kill, an apt eviction, or cloud-init's unattended-
# upgrades being interrupted mid-flight. Symptom that drives this:
#
#   E: Sub-process /usr/bin/dpkg returned an error code (1)
#   N not fully installed or removed
#
# Three-level recovery, escalating only when the gentler step fails:
#   1. dpkg --configure -a
#      Re-runs postinst for every half-configured package. Fixes the
#      common case where postinst was killed (OOM, our eviction).
#   2. apt-get install -f
#      Repairs unmet dependencies that were left behind. Catches the
#      case where the package is fine but a missing dep blocks
#      reconfiguration.
#   3. dpkg --purge --force-all <broken pkg>
#      The escape hatch. If postinst itself is the bug (mysql-server
#      8.4 + missing locale + missing /var/run/mysqld), levels 1-2
#      will keep failing forever in a loop. Purge the broken package
#      so the normal install flow downstream gets a clean slate.
#      The package's own install step will reinstall it from a known-
#      working state.
dply_dump_dpkg_diagnostics() {
  echo "[dply-diag] ===== dpkg failure diagnostics =====" >&2
  echo "[dply-diag] non-ok packages (status != ii/rc/un):" >&2
  local broken_list
  broken_list=\$(dpkg -l 2>/dev/null \\
    | awk '/^[a-zA-Z]{2}[ \\t]/ && \$1 !~ /^(ii|rc|un)\$/ { print "  "\$1, \$2, \$3 }')
  if [ -n "\${broken_list}" ]; then
    echo "\${broken_list}" >&2
  else
    echo "  (none flagged — failure may be a postinst that exited 1 without leaving status state)" >&2
  fi

  echo "[dply-diag] last 50 lines of /var/log/apt/term.log:" >&2
  tail -n 50 /var/log/apt/term.log 2>/dev/null | sed 's/^/  /' >&2 \\
    || echo "  (term.log unavailable)" >&2

  echo "[dply-diag] last 30 lines of /var/log/dpkg.log:" >&2
  tail -n 30 /var/log/dpkg.log 2>/dev/null | sed 's/^/  /' >&2 \\
    || echo "  (dpkg.log unavailable)" >&2

  # MySQL-specific deep diagnostics. The postinst calls
  # systemctl start mysql, and the daemon's actual startup error
  # only lands in the systemd journal — neither apt's term.log
  # nor dpkg.log capture mysqld's stderr. Without these blocks
  # we keep seeing "configure → half-configured in <1s" without
  # any root-cause signal. Only emit when mysql-server is in the
  # broken list (or installed at all) so non-mysql failures aren't
  # noisy.
  if echo "\${broken_list}" | grep -qE 'mysql-server|mariadb-server' \\
     || dpkg -l mysql-server-* mariadb-server-* 2>/dev/null | grep -qE '^[a-zA-Z]{2}[ \\t]'; then
    echo "[dply-diag] mysql/mariadb appears in package state — pulling daemon diagnostics:" >&2

    echo "[dply-diag]   journalctl -u mysql (last 80 lines):" >&2
    journalctl -u mysql --no-pager -n 80 2>/dev/null | sed 's/^/    /' >&2 \\
      || echo "    (journalctl -u mysql unavailable)" >&2

    echo "[dply-diag]   journalctl -u mariadb (last 40 lines):" >&2
    journalctl -u mariadb --no-pager -n 40 2>/dev/null | sed 's/^/    /' >&2 \\
      || echo "    (no mariadb journal)" >&2

    echo "[dply-diag]   /var/log/mysql/error.log tail (last 50 lines):" >&2
    tail -n 50 /var/log/mysql/error.log 2>/dev/null | sed 's/^/    /' >&2 \\
      || echo "    (no /var/log/mysql/error.log yet)" >&2

    echo "[dply-diag]   filesystem state:" >&2
    ls -lad /var/lib/mysql /var/log/mysql /var/run/mysqld /etc/mysql 2>&1 | sed 's/^/    /' >&2

    echo "[dply-diag]   mysqld --validate-config:" >&2
    if command -v mysqld >/dev/null 2>&1; then
      sudo -u mysql mysqld --validate-config 2>&1 | head -n 30 | sed 's/^/    /' >&2 \\
        || mysqld --validate-config 2>&1 | head -n 30 | sed 's/^/    /' >&2 \\
        || true
    else
      echo "    (mysqld binary not present — package failed before unpack completed)" >&2
    fi

    echo "[dply-diag]   AppArmor status for mysqld:" >&2
    aa-status 2>/dev/null | grep -i mysql | sed 's/^/    /' >&2 \\
      || echo "    (apparmor not active or no mysql profile)" >&2

    echo "[dply-diag]   memory snapshot (mysql 8.0 needs ~512MB to initialise):" >&2
    free -h 2>/dev/null | sed 's/^/    /' >&2

    echo "[dply-diag]   processes still holding mysql files:" >&2
    fuser -v /var/lib/mysql 2>&1 | sed 's/^/    /' >&2 || true
  fi

  echo "[dply-diag] ===== end diagnostics =====" >&2
}

dply_repair_dpkg_state() {
  dply_wait_for_apt_locks || return 1

  # /^[a-zA-Z]{2}[ \\t]/  — exactly two status chars + whitespace.
  # Without the {2} bound, the dpkg -l header line "Desired=Unknown..."
  # also matched and fed garbage into the broken-list. Tightening to
  # exactly two characters skips the header cleanly.
  local broken
  broken=\$(dpkg -l 2>/dev/null | awk '/^[a-zA-Z]{2}[ \\t]/ && \$1 !~ /^(ii|rc|un)\$/ { print \$2 }')

  if [ -z "\${broken}" ]; then
    return 0
  fi

  echo "[dply] detected half-configured packages, running dpkg --configure -a to heal:"
  echo "\${broken}" | sed 's/^/[dply]   /'

  if dpkg --configure -a; then
    DEBIAN_FRONTEND=noninteractive apt-get install -f -y \\
      || echo "[dply] WARNING: apt-get install -f could not auto-fix dependencies."
  else
    echo "[dply] dpkg --configure -a failed; trying apt-get install -f..."
    DEBIAN_FRONTEND=noninteractive apt-get install -f -y || true
  fi

  # Re-check; if any package is STILL half-configured after both
  # repair attempts, the postinst itself is broken. Purge with
  # --force-all so the normal install flow reinstalls it cleanly.
  local still_broken
  still_broken=\$(dpkg -l 2>/dev/null | awk '/^[a-zA-Z]{2}[ \\t]/ && \$1 !~ /^(ii|rc|un)\$/ { print \$2 }')

  if [ -n "\${still_broken}" ]; then
    echo "[dply] gentle repair failed; purging stuck packages (will be reinstalled by their own install step):"
    echo "\${still_broken}" | sed 's/^/[dply]   /'

    # mysql-server's postinst calls mysql_install_db, which refuses to
    # initialise into a non-empty /var/lib/mysql. A previous failed
    # install left files there, so even after dpkg --purge clears the
    # package the data directory survives — and the very next reinstall
    # bombs identically: "data directory not empty". Symptom in
    # /var/log/dpkg.log: configure → half-configured in <1 second,
    # repeating every retry. Same logic applies to mariadb. Stop the
    # service and nuke the data + log directories so the next install
    # gets a clean slate.
    if echo "\${still_broken}" | grep -qE '^(mysql-server|mariadb-server)'; then
      echo "[dply] mysql/mariadb among broken packages — wiping stale data dirs so reinstall can initialise cleanly."
      systemctl stop mysql mariadb >/dev/null 2>&1 || true
      rm -rf /var/lib/mysql /var/log/mysql /etc/mysql
    fi

    # shellcheck disable=SC2086
    DEBIAN_FRONTEND=noninteractive dpkg --purge --force-all \${still_broken} \\
      || { echo "[dply] ERROR: even --force-all purge failed; manual intervention required." >&2; return 1; }
    echo "[dply] purge complete; downstream install steps will reinstall."
  fi
}

BASH;
    }
}
