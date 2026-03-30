<?php

namespace App\Jobs;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\Events\TaskStarted;
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTaskModel;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs stack provisioning from servers.meta (wizard choices), then optional setup_scripts recipes.
 *
 * Uses TaskRunner ({@see TaskDispatcher::runWithModel}) so execution goes through the same SSH
 * upload/run path as other remote tasks, persists a {@see TaskRunnerTaskModel} row, and emits
 * TaskRunner events ({@see TaskStarted},
 * {@see TaskCompleted},
 * {@see TaskFailed}) for listeners and callbacks.
 */
class RunSetupScriptJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(
        public Server $server
    ) {}

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
            $output = $dispatcher->runWithModel($task, $taskModel);

            $server->refresh();
            $success = $output !== null && $output->isSuccessful();
            $updates = [
                'setup_status' => $success ? Server::SETUP_STATUS_DONE : Server::SETUP_STATUS_FAILED,
            ];
            if ($success && $server->openSshPublicKeyFromPrivate() !== null) {
                $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');
                if ($deployUser !== '' && $deployUser !== 'root'
                    && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $deployUser)) {
                    $updates['ssh_user'] = $deployUser;
                }
            }
            $server->update($updates);
        } catch (TaskExecutionException $e) {
            Log::warning('Server provision / setup script failed (TaskRunner).', [
                'server_id' => $server->id,
                'task_runner_task_id' => $taskModel->id,
                'setup_script_key' => $server->setup_script_key,
                'error' => $e->getMessage(),
                'caused_by' => $e->getPrevious()?->getMessage(),
            ]);
            $server->update(['setup_status' => Server::SETUP_STATUS_FAILED]);
        } catch (\Throwable $e) {
            Log::warning('Server provision / setup script failed.', [
                'server_id' => $server->id,
                'task_runner_task_id' => $taskModel->id,
                'setup_script_key' => $server->setup_script_key,
                'error' => $e->getMessage(),
            ]);
            $server->update(['setup_status' => Server::SETUP_STATUS_FAILED]);
        }
    }
}
