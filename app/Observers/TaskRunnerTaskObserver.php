<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;

class TaskRunnerTaskObserver
{
    public function updated(Task $task): void
    {
        if ($task->action !== 'provision_stack' || ! $task->wasChanged('status')) {
            return;
        }

        if (! $task->server_id) {
            return;
        }

        $server = Server::query()->find($task->server_id);
        if (! $server) {
            return;
        }

        $meta = $server->meta ?? [];
        if (($meta['provision_task_id'] ?? null) !== (string) $task->id) {
            return;
        }

        if ($task->status === TaskStatus::Finished) {
            RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

            return;
        }

        if (in_array($task->status, [TaskStatus::Failed, TaskStatus::Timeout], true)) {
            RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);
        }
    }
}
