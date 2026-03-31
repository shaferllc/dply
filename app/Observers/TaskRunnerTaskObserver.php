<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Servers\UpsertServerProvisionArtifact;
use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\ProvisionLogSections;
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

        $run = $this->provisionRunForTask($task, $server);

        if ($run && ($task->wasChanged('output') || $task->wasChanged('status'))) {
            $this->persistStructuredArtifacts($run, $task);
        }

        if (! $task->wasChanged('status')) {
            return;
        }

        if ($task->status === TaskStatus::Finished) {
            if ($run) {
                $run->update([
                    'status' => 'succeeded',
                    'rollback_status' => 'not_needed',
                    'summary' => 'Provisioning finished successfully.',
                    'completed_at' => now(),
                ]);
            }
            RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

            return;
        }

        if (in_array($task->status, [TaskStatus::Failed, TaskStatus::Timeout, TaskStatus::Cancelled], true)) {
            if ($run) {
                $rollbackEvents = ProvisionLogSections::parseTaggedLines((string) $task->output, '[dply-rollback] ');
                $run->update([
                    'status' => $task->status === TaskStatus::Cancelled ? 'cancelled' : 'failed',
                    'rollback_status' => $rollbackEvents === [] ? 'repair_required' : 'attempted',
                    'summary' => $rollbackEvents === [] ? 'Provisioning failed and needs guided repair.' : 'Provisioning failed after attempting safe rollback.',
                    'completed_at' => now(),
                ]);
            }
            RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);
        }
    }

    private function provisionRunForTask(Task $task, Server $server): ?ServerProvisionRun
    {
        $runId = (string) ($server->meta['provision_run_id'] ?? '');

        return ServerProvisionRun::query()
            ->when($runId !== '', fn ($query) => $query->whereKey($runId))
            ->where('task_id', $task->id)
            ->latest('created_at')
            ->first();
    }

    private function persistStructuredArtifacts(ServerProvisionRun $run, Task $task): void
    {
        /** @var UpsertServerProvisionArtifact $upsert */
        $upsert = app(UpsertServerProvisionArtifact::class);

        $verification = ProvisionLogSections::parseTaggedLines((string) $task->output, '[dply-verify] ');
        if ($verification !== []) {
            $upsert->handle(
                $run,
                'verification_report',
                'Verification report',
                json_encode($verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
                ['checks' => $verification],
                'verification-report',
            );
        }

        $rollback = ProvisionLogSections::parseTaggedLines((string) $task->output, '[dply-rollback] ');
        if ($rollback !== []) {
            $upsert->handle(
                $run,
                'rollback_report',
                'Rollback report',
                json_encode($rollback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
                ['events' => $rollback],
                'rollback-report',
            );
        }

        if ($task->output !== null && trim($task->output) !== '') {
            $upsert->handle(
                $run,
                'task_output_tail',
                'Recent provision output',
                $task->tailOutput(20),
                [],
                'task-output-tail',
            );
        }
    }
}
