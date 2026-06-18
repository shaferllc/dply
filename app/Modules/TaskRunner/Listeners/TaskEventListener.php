<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Listeners;

use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\Events\TaskProgress;
use App\Modules\TaskRunner\Events\TaskStarted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class TaskEventListener
{
    /**
     * Handle task started events.
     */
    public function handleTaskStarted(TaskStarted $event): void
    {
        Log::info('Task started', [
            'task_name' => $event->getTaskName(),
            'task_class' => $event->getTaskClass(),
            'task_action' => $event->getTaskAction(),
            'task_id' => $event->getTaskId(),
            'started_at' => $event->startedAt,
            'is_background' => $event->isBackground(),
            'connection' => $event->getConnection(),
        ]);

        // Example: Send notification for important tasks
        if ($this->isImportantTask($event)) {
            $this->notifyTaskStarted($event);
        }

        // Example: Update dashboard metrics
        $this->updateTaskMetrics($event, 'started');
    }

    /**
     * Handle task completed events.
     */
    public function handleTaskCompleted(TaskCompleted $event): void
    {
        $metrics = $event->getPerformanceMetrics();

        Log::info('Task completed', [
            'task_name' => $event->getTaskName(),
            'task_class' => $event->getTaskClass(),
            'successful' => $event->wasSuccessful(),
            'exit_code' => $event->getExitCode(),
            'duration' => $metrics['duration_human'],
            'output_size' => $metrics['output_size'],
            'timed_out' => $event->timedOut(),
            'task_id' => $event->getTaskId(),
        ]);

        // Example: Send success notification
        if ($event->wasSuccessful() && $this->isImportantTask($event)) {
            $this->notifyTaskCompleted($event);
        }

        // Example: Update performance metrics
        $this->updatePerformanceMetrics($event);

        // Example: Clean up temporary files
        $this->cleanupTaskFiles($event);
    }

    /**
     * Handle task failed events.
     */
    public function handleTaskFailed(TaskFailed $event): void
    {
        $details = $event->getFailureDetails();

        Log::error('Task failed', [
            'task_name' => $event->getTaskName(),
            'task_class' => $event->getTaskClass(),
            'reason' => $event->getReason(),
            'exception_class' => $details['exception_class'],
            'exception_message' => $details['exception_message'],
            'exit_code' => $details['exit_code'],
            'timed_out' => $details['timed_out'],
            'duration' => $details['duration_human'],
            'task_id' => $event->getTaskId(),
        ]);

        // Example: Send failure notification
        $this->notifyTaskFailed($event);

        // Example: Create incident ticket
        if ($this->isCriticalTask($event)) {
            $this->createIncidentTicket($event);
        }

        // Example: Retry failed task
        if ($this->shouldRetryTask($event)) {
            $this->scheduleRetry($event);
        }
    }

    /**
     * Handle task progress events.
     */
    public function handleTaskProgress(TaskProgress $event): void
    {
        $details = $event->getProgressDetails();

        Log::debug('Task progress', [
            'task_name' => $event->getTaskName(),
            'task_class' => $event->getTaskClass(),
            'current_step' => $details['current_step'],
            'total_steps' => $details['total_steps'],
            'step_name' => $details['step_name'],
            'percentage' => $details['percentage_int'],
            'progress_bar' => $details['progress_bar'],
            'task_id' => $event->getTaskId(),
        ]);

        // Example: Update progress in database
        $this->updateTaskProgress($event);

        // Example: Send progress notification for long-running tasks
        if ($this->isLongRunningTask($event)) {
            $this->notifyTaskProgress($event);
        }

        // Example: Update real-time dashboard
        $this->updateProgressDashboard($event);
    }

    /**
     * Check if the task is important enough for notifications.
     */
    protected function isImportantTask(TaskStarted|TaskCompleted|TaskFailed $event): bool
    {
        $importantTasks = [
            'DatabaseBackupTask',
            'DeploymentTask',
            'SystemMaintenanceTask',
        ];

        return in_array($event->getTaskClass(), $importantTasks) ||
               str_contains($event->getTaskName(), 'backup') ||
               str_contains($event->getTaskName(), 'deploy') ||
               str_contains($event->getTaskName(), 'maintenance');
    }

    /**
     * Check if the task is critical.
     */
    protected function isCriticalTask(TaskFailed $event): bool
    {
        $criticalTasks = [
            'DatabaseBackupTask',
            'SystemMaintenanceTask',
        ];

        return in_array($event->getTaskClass(), $criticalTasks) ||
               $event->wasTimeout() ||
               $event->getExitCode() === 1;
    }

    /**
     * Check if the task should be retried.
     */
    protected function shouldRetryTask(TaskFailed $event): bool
    {
        // Don't retry if it was a timeout or critical error
        if ($event->wasTimeout() || $this->isCriticalTask($event)) {
            return false;
        }

        // Retry for temporary failures
        $retryableExitCodes = [2, 3, 4, 5, 6, 7, 8, 9];

        return in_array($event->getExitCode(), $retryableExitCodes);
    }

    /**
     * Check if the task is long-running.
     */
    protected function isLongRunningTask(TaskProgress $event): bool
    {
        return $event->getTotalSteps() > 5 ||
               str_contains($event->getTaskName(), 'backup') ||
               str_contains($event->getTaskName(), 'deploy');
    }

    /**
     * Send notification for task started.
     */
    protected function notifyTaskStarted(TaskStarted $event): void
    {
        // Example: Send Slack notification
        // Notification::route('slack', config('notifications.slack.webhook_url'))
        //     ->notify(new TaskStartedNotification($event));

        Log::info('Task started notification sent', [
            'task_name' => $event->getTaskName(),
            'task_id' => $event->getTaskId(),
        ]);
    }

    /**
     * Send notification for task completed.
     */
    protected function notifyTaskCompleted(TaskCompleted $event): void
    {
        // Example: Send email notification
        // Notification::route('mail', config('notifications.admin_email'))
        //     ->notify(new TaskCompletedNotification($event));

        Log::info('Task completed notification sent', [
            'task_name' => $event->getTaskName(),
            'task_id' => $event->getTaskId(),
            'duration' => $event->getDurationForHumans(),
        ]);
    }

    /**
     * Send notification for task failed.
     */
    protected function notifyTaskFailed(TaskFailed $event): void
    {
        // Example: Send urgent notification
        // Notification::route('mail', config('notifications.emergency_email'))
        //     ->notify(new TaskFailedNotification($event));

        Log::error('Task failed notification sent', [
            'task_name' => $event->getTaskName(),
            'task_id' => $event->getTaskId(),
            'reason' => $event->getReason(),
        ]);
    }

    /**
     * Send notification for task progress.
     */
    protected function notifyTaskProgress(TaskProgress $event): void
    {
        // Only notify on milestone progress (25%, 50%, 75%, 100%)
        $milestones = [25, 50, 75, 100];
        if (in_array($event->getPercentageInt(), $milestones)) {
            Log::info('Task progress notification sent', [
                'task_name' => $event->getTaskName(),
                'task_id' => $event->getTaskId(),
                'percentage' => $event->getPercentageInt(),
                'step_name' => $event->getStepName(),
            ]);
        }
    }

    /**
     * Update task metrics.
     */
    protected function updateTaskMetrics(TaskStarted $event, string $status): void
    {
        // Example: Update Redis metrics
        // Redis::incr("task_metrics:{$status}:{$event->getTaskClass()}");
        // Redis::incr("task_metrics:{$status}:total");

        Log::debug('Task metrics updated', [
            'status' => $status,
            'task_class' => $event->getTaskClass(),
            'task_id' => $event->getTaskId(),
        ]);
    }

    /**
     * Update performance metrics.
     */
    protected function updatePerformanceMetrics(TaskCompleted $event): void
    {
        $metrics = $event->getPerformanceMetrics();

        // Example: Store performance data
        // DB::table('task_performance')->insert([
        //     'task_class' => $event->getTaskClass(),
        //     'task_name' => $event->getTaskName(),
        //     'duration' => $metrics['duration'],
        //     'output_size' => $metrics['output_size'],
        //     'successful' => $event->wasSuccessful(),
        //     'created_at' => now(),
        // ]);

        Log::debug('Performance metrics updated', [
            'task_class' => $event->getTaskClass(),
            'duration' => $metrics['duration_human'],
            'successful' => $event->wasSuccessful(),
        ]);
    }

    /**
     * Clean up task files.
     */
    protected function cleanupTaskFiles(TaskCompleted $event): void
    {
        // Example: Clean up temporary files
        // $taskId = $event->getTaskId();
        // $tempDir = storage_path("app/tasks/{$taskId}");
        // if (File::exists($tempDir)) {
        //     File::deleteDirectory($tempDir);
        // }

        Log::debug('Task files cleaned up', [
            'task_id' => $event->getTaskId(),
        ]);
    }

    /**
     * Create incident ticket.
     */
    protected function createIncidentTicket(TaskFailed $event): void
    {
        // Example: Create incident in external system
        // $incident = [
        //     'title' => "Task Failed: {$event->getTaskName()}",
        //     'description' => $event->getReason(),
        //     'severity' => 'high',
        //     'task_id' => $event->getTaskId(),
        // ];
        // IncidentService::create($incident);

        Log::error('Incident ticket created', [
            'task_name' => $event->getTaskName(),
            'task_id' => $event->getTaskId(),
            'reason' => $event->getReason(),
        ]);
    }

    /**
     * Schedule task retry.
     */
    protected function scheduleRetry(TaskFailed $event): void
    {
        // Example: Schedule retry job
        // dispatch(new RetryTaskJob($event->task, $event->pendingTask))
        //     ->delay(now()->addMinutes(5));

        Log::info('Task retry scheduled', [
            'task_name' => $event->getTaskName(),
            'task_id' => $event->getTaskId(),
            'retry_delay' => '5 minutes',
        ]);
    }

    /**
     * Update task progress in database.
     */
    protected function updateTaskProgress(TaskProgress $event): void
    {
        // Example: Update progress in database
        // DB::table('task_progress')->updateOrInsert(
        //     ['task_id' => $event->getTaskId()],
        //     [
        //         'current_step' => $event->getCurrentStep(),
        //         'total_steps' => $event->getTotalSteps(),
        //         'percentage' => $event->getPercentage(),
        //         'step_name' => $event->getStepName(),
        //         'updated_at' => now(),
        //     ]
        // );

        Log::debug('Task progress updated in database', [
            'task_id' => $event->getTaskId(),
            'percentage' => $event->getPercentageInt(),
        ]);
    }

    /**
     * Update progress dashboard.
     */
    protected function updateProgressDashboard(TaskProgress $event): void
    {
        // Example: Broadcast to real-time dashboard
        // broadcast(new TaskProgressUpdated($event));

        Log::debug('Progress dashboard updated', [
            'task_id' => $event->getTaskId(),
            'percentage' => $event->getPercentageInt(),
        ]);
    }
}
