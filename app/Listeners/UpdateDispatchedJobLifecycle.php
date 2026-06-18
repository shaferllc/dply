<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

/**
 * Closes the lifecycle for rows created by {@see RecordLivewireDispatchedJob}.
 * The worker fires JobProcessing → JobProcessed/JobFailed; we look the row
 * up via its `instance` column (Laravel's job UUID) and flip Pending →
 * Running → Finished/Failed.
 *
 * Worker-only listener — dispatcher-side recording is handled separately
 * so the user sees the row appear immediately on click, even before a
 * worker has dequeued the job.
 */
class UpdateDispatchedJobLifecycle
{
    public function handleProcessing(JobProcessing $event): void
    {
        $uuid = $this->uuid($event->job);
        if ($uuid === null) {
            return;
        }

        $this->safeUpdate($uuid, [
            'status' => TaskStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $uuid = $this->uuid($event->job);
        if ($uuid === null) {
            return;
        }

        $this->safeUpdate($uuid, [
            'status' => TaskStatus::Finished,
            'completed_at' => now(),
            'exit_code' => 0,
        ]);
    }

    public function handleFailed(JobFailed $event): void
    {
        $uuid = $this->uuid($event->job);
        if ($uuid === null) {
            return;
        }

        $message = (string) $event->exception->getMessage();

        $this->safeUpdate($uuid, [
            'status' => TaskStatus::Failed,
            'completed_at' => now(),
            'exit_code' => 1,
            // Cap to keep the panel detail viewer responsive — the full
            // exception trace is in the framework's `failed_jobs` table.
            'output' => mb_substr($message, 0, 4000),
        ]);
    }

    private function uuid(mixed $job): ?string
    {
        if (! is_object($job) || ! method_exists($job, 'uuid')) {
            return null;
        }

        $uuid = $job->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function safeUpdate(string $uuid, array $attributes): void
    {
        try {
            TaskRunnerTask::query()
                ->where('instance', $uuid)
                ->where('action', 'dispatched_job') // keep this listener's writes scoped to OUR rows
                ->limit(1)
                ->update($attributes);
        } catch (\Throwable $e) {
            Log::warning('debug.taskrunner.dispatched_job_update_failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
