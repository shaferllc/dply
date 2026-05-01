<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Servers\CreateServerProvisionRun;
use App\Actions\Servers\UpsertServerProvisionArtifact;
use App\Enums\ServerProvider;
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

    /**
     * Apply provision outcome to the server (setup_status, optional deploy ssh_user).
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
            $server->update($updates);

            return;
        }

        $server->update(['setup_status' => Server::SETUP_STATUS_FAILED]);
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

trap 'status=\$?; echo "[dply-rollback] automatic :: started :: Provision failed, attempting safe rollback"; dply_restore_backups || true; exit \$status' ERR

BASH;
    }
}
