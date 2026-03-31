<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\ProvisionStepSnapshots;

class TaskRunnerTaskObserver
{
    public function updated(Task $task): void
    {
        if ($task->action !== 'provision_stack') {
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

        if ($task->wasChanged('output') || $task->wasChanged('script_content') || $task->wasChanged('status')) {
            $snapshots = ProvisionStepSnapshots::merge(
                is_array($meta['provision_step_snapshots'] ?? null) ? $meta['provision_step_snapshots'] : [],
                is_string($task->script_content) ? $task->script_content : null,
                is_string($task->output) ? $task->output : null,
            );

            if (($meta['provision_step_snapshots'] ?? null) !== $snapshots) {
                $meta['provision_step_snapshots'] = $snapshots;
                $server->update(['meta' => $meta]);
            }
        }

        if (! $task->wasChanged('status')) {
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
