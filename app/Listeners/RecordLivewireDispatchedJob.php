<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Mirror jobs dispatched during a Livewire request into task_runner_tasks
 * so the bottom-of-screen TaskRunner panel can surface them as live work.
 *
 * Why Livewire-only? Cron, queue chaining, and scheduled work all dispatch
 * through the same queue API, but operators looking at "what's running for
 * me right now" only care about jobs they fired by clicking a button. The
 * `request()->is('livewire/update')` guard keeps the panel on-signal.
 *
 * Lifecycle is closed by {@see UpdateDispatchedJobLifecycle}, which writes
 * Running / Finished / Failed transitions when the worker picks the job up.
 * The job's UUID is stored in the Task's `instance` column so those events
 * can find the row again — task_runner_tasks already keeps `instance` for
 * exactly this kind of out-of-band correlation.
 */
class RecordLivewireDispatchedJob
{
    /**
     * Job classes we deliberately exclude — recording these would cause
     * recursion or noise (e.g. UpdateTaskOutput is the worker emitting
     * progress for an existing task; it's not user-facing).
     *
     * @var array<int, class-string>
     */
    private const SKIP_CLASSES = [
        UpdateTaskOutput::class,
    ];

    public function handle(JobQueued $event): void
    {
        if (! $this->isLivewireRequest()) {
            return;
        }

        $userId = Auth::id();
        if ($userId === null) {
            return;
        }

        $jobInstance = is_object($event->job) ? $event->job : null;
        $jobClass = is_object($jobInstance) ? $jobInstance::class : null;
        if ($jobClass === null || in_array($jobClass, self::SKIP_CLASSES, true)) {
            return;
        }

        $jobUuid = is_string($event->id) && $event->id !== '' ? $event->id : null;
        if ($jobUuid === null) {
            // Without a UUID we can't correlate JobProcessing/Processed
            // back to the row, so don't bother creating it.
            return;
        }

        $serverId = $this->extractServerId($jobInstance);

        try {
            TaskRunnerTask::query()->create([
                'name' => 'job:'.class_basename($jobClass),
                'action' => 'dispatched_job',
                'status' => TaskStatus::Pending,
                'instance' => $jobUuid,
                'server_id' => $serverId,
                'created_by' => $userId,
                'options' => [
                    'job_class' => $jobClass,
                    'connection' => $event->connectionName,
                    'queue' => $event->queue,
                ],
            ]);
        } catch (\Throwable $e) {
            // Recording is best-effort — never let a dispatch fail
            // because the audit row couldn't be written.
            Log::warning('debug.taskrunner.dispatched_job_record_failed', [
                'job_class' => $jobClass,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isLivewireRequest(): bool
    {
        // Livewire 3 routes all action requests through /livewire/update.
        // No global "is this a livewire dispatch span?" hook exists in v3,
        // so this URL probe is the simplest reliable signal.
        try {
            return request()->is('livewire/update') === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Best-effort: find a server_id-shaped value on the job so the Task
     * row joins to servers.organization_id (the existing feed filter).
     * Without it the row is still recorded but won't appear in
     * org-scoped panel queries — fine for jobs that aren't server-bound.
     */
    private function extractServerId(mixed $job): ?string
    {
        if (! is_object($job)) {
            return null;
        }

        foreach (['serverId', 'server_id'] as $name) {
            if (property_exists($job, $name)) {
                $value = $job->{$name};
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        if (property_exists($job, 'server')) {
            $value = $job->server;
            if (is_object($value) && property_exists($value, 'id')) {
                $id = $value->id;
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
