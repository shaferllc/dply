<?php

namespace App\Jobs;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTaskModel;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\TrackTaskInBackground;
use App\Observers\TaskRunnerTaskObserver;
use App\Services\Servers\ServerProvisionCommandBuilder;
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
            if ($server->openSshPublicKeyFromPrivate() !== null) {
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
        if ($server->status !== Server::STATUS_READY || empty($server->ip_address)) {
            return false;
        }

        if (! filled($server->ssh_private_key)) {
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
        ServerProvisionCommandBuilder $builder,
        TaskDispatcher $dispatcher,
    ): void {
        $server = $this->server->fresh();
        if (! $server || ! static::shouldDispatch($server)) {
            return;
        }

        $commands = $builder->build($server);

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
            return;
        }

        $timeout = (int) config('server_provision.remote_script_timeout_seconds', 3600);

        $body = "#!/bin/bash\nset -euo pipefail\nexport DEBIAN_FRONTEND=noninteractive\n";
        foreach ($commands as $line) {
            $body .= rtrim($line)."\n";
        }

        $task = AnonymousTask::script('Server stack provision', $body, ['timeout' => $timeout]);
        $task->setUser('root');

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

        $meta = $server->meta ?? [];
        $meta['provision_task_id'] = (string) $taskModel->id;
        $server->update([
            'setup_status' => Server::SETUP_STATUS_RUNNING,
            'meta' => $meta,
        ]);

        try {
            $task->setTaskModel($taskModel);

            $tracked = $dispatcher->wrapWithTrackTaskInBackground($task, $taskModel);
            $tracked->setTaskModel($taskModel);

            $taskModel->update([
                'instance' => serialize($tracked),
            ]);

            $output = $dispatcher->runInBackgroundWithModel($tracked, $taskModel);

            if ($output === null || ! $output->isSuccessful()) {
                $taskModel->update([
                    'status' => TaskStatus::Failed,
                    'completed_at' => now(),
                    'output' => $output !== null ? $output->getBuffer() : 'Background start returned no output.',
                ]);
                static::applyProvisionOutcomeToServer($server, false);
            }
        } catch (TaskExecutionException $e) {
            Log::warning('Server provision / setup script failed (TaskRunner).', [
                'server_id' => $server->id,
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
            Log::warning('Server provision / setup script failed.', [
                'server_id' => $server->id,
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
}
