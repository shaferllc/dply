<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Services;

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ConditionalStreamingService
{
    /**
     * The streaming logger instance.
     */
    protected StreamingLoggerInterface $streamingLogger;

    /**
     * Task priority levels.
     */
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    /**
     * Task categories.
     */
    public const CATEGORY_BACKUP = 'backup';

    public const CATEGORY_DEPLOYMENT = 'deployment';

    public const CATEGORY_MAINTENANCE = 'maintenance';

    public const CATEGORY_MONITORING = 'monitoring';

    public const CATEGORY_SYNC = 'sync';

    /**
     * Create a new ConditionalStreamingService instance.
     */
    public function __construct(StreamingLoggerInterface $streamingLogger)
    {
        $this->streamingLogger = $streamingLogger;
        $this->registerConditionalHandlers();
    }

    /**
     * Register conditional streaming handlers.
     */
    protected function registerConditionalHandlers(): void
    {
        // Critical task handler - immediate notifications
        $this->streamingLogger->addStreamHandler(function ($logData) {
            $this->handleCriticalTask($logData);
        }, 'critical-tasks');

        // High priority task handler - detailed logging
        $this->streamingLogger->addStreamHandler(function ($logData) {
            $this->handleHighPriorityTask($logData);
        }, 'high-priority-tasks');

        // Backup task handler - progress tracking
        $this->streamingLogger->addStreamHandler(function ($logData) {
            $this->handleBackupTask($logData);
        }, 'backup-tasks');

        // Deployment task handler - status updates
        $this->streamingLogger->addStreamHandler(function ($logData) {
            $this->handleDeploymentTask($logData);
        }, 'deployment-tasks');

        // Error handler - alert notifications
        $this->streamingLogger->addStreamHandler(function ($logData) {
            $this->handleError($logData);
        }, 'error-events');
    }

    /**
     * Handle critical task streaming.
     */
    protected function handleCriticalTask(array $logData): void
    {
        $context = $logData['context'] ?? [];
        $taskPriority = $context['task_priority'] ?? 'normal';

        if ($taskPriority === self::PRIORITY_CRITICAL) {
            // Send immediate SMS/email alerts
            $this->sendCriticalAlert($logData);

            // Log to critical task log
            Log::channel('critical')->info('Critical task event', $logData);

            // Broadcast to admin dashboard
            $this->broadcastToAdmins($logData);
        }
    }

    /**
     * Handle high priority task streaming.
     */
    protected function handleHighPriorityTask(array $logData): void
    {
        $context = $logData['context'] ?? [];
        $taskPriority = $context['task_priority'] ?? 'normal';

        if ($taskPriority === self::PRIORITY_HIGH) {
            // Detailed logging for high priority tasks
            Log::channel('high-priority')->info('High priority task event', $logData);

            // Send Slack notification
            $this->sendSlackNotification($logData);

            // Update high priority task dashboard
            $this->updateHighPriorityDashboard($logData);
        }
    }

    /**
     * Handle backup task streaming.
     */
    protected function handleBackupTask(array $logData): void
    {
        $context = $logData['context'] ?? [];
        $taskCategory = $context['task_category'] ?? '';

        if ($taskCategory === self::CATEGORY_BACKUP) {
            // Track backup progress
            $this->trackBackupProgress($logData);

            // Update backup status dashboard
            $this->updateBackupDashboard($logData);

            // Send backup completion notification
            if ($logData['context']['event'] ?? '' === 'completed') {
                $this->sendBackupCompletionNotification($logData);
            }
        }
    }

    /**
     * Handle deployment task streaming.
     */
    protected function handleDeploymentTask(array $logData): void
    {
        $context = $logData['context'] ?? [];
        $taskCategory = $context['task_category'] ?? '';

        if ($taskCategory === self::CATEGORY_DEPLOYMENT) {
            // Track deployment stages
            $this->trackDeploymentStage($logData);

            // Update deployment status
            $this->updateDeploymentStatus($logData);

            // Send deployment notifications
            $this->sendDeploymentNotification($logData);
        }
    }

    /**
     * Handle error events.
     */
    protected function handleError(array $logData): void
    {
        if ($logData['level'] === 'error') {
            // Send error alert
            $this->sendErrorAlert($logData);

            // Log to error tracking service
            $this->logToErrorTracking($logData);

            // Update error dashboard
            $this->updateErrorDashboard($logData);
        }
    }

    /**
     * Configure streaming for a specific task.
     */
    public function configureTaskStreaming(Task $task, array $options = []): void
    {
        $taskId = $task->id ?? uniqid();
        $priority = $options['priority'] ?? self::PRIORITY_NORMAL;
        $category = $options['category'] ?? '';
        $notifyOnCompletion = $options['notify_on_completion'] ?? false;
        $notifyOnError = $options['notify_on_error'] ?? true;

        // Add task-specific context to all streaming events
        $this->streamingLogger->addStreamHandler(function ($logData) use ($taskId, $priority, $category, $notifyOnCompletion, $notifyOnError) {
            $this->handleTaskSpecificStreaming($logData, $taskId, $priority, $category, $notifyOnCompletion, $notifyOnError);
        }, $taskId);
    }

    /**
     * Handle task-specific streaming.
     */
    protected function handleTaskSpecificStreaming(array $logData, string $taskId, string $priority, string $category, bool $notifyOnCompletion, bool $notifyOnError): void
    {
        $context = $logData['context'] ?? [];
        $context['task_id'] = $taskId;
        $context['task_priority'] = $priority;
        $context['task_category'] = $category;

        // Route to appropriate handlers based on priority and category
        if ($priority === self::PRIORITY_CRITICAL) {
            $this->handleCriticalTask($logData);
        } elseif ($priority === self::PRIORITY_HIGH) {
            $this->handleHighPriorityTask($logData);
        }

        if ($category === self::CATEGORY_BACKUP) {
            $this->handleBackupTask($logData);
        } elseif ($category === self::CATEGORY_DEPLOYMENT) {
            $this->handleDeploymentTask($logData);
        }

        // Handle completion notifications
        if ($notifyOnCompletion && ($context['event'] ?? '') === 'completed') {
            $this->sendCompletionNotification($logData);
        }

        // Handle error notifications
        if ($notifyOnError && $logData['level'] === 'error') {
            $this->handleError($logData);
        }
    }

    /**
     * Send critical alert.
     */
    protected function sendCriticalAlert(array $logData): void
    {
        // In a real implementation, you'd send SMS/email
        Log::critical('Critical task alert', $logData);
    }

    /**
     * Send Slack notification.
     */
    protected function sendSlackNotification(array $logData): void
    {
        // In a real implementation, you'd send to Slack
        Log::info('Slack notification sent', $logData);
    }

    /**
     * Broadcast to admins.
     */
    protected function broadcastToAdmins(array $logData): void
    {
        // In a real implementation, you'd broadcast to admin channels
        Log::info('Broadcast to admins', $logData);
    }

    /**
     * Track backup progress.
     */
    protected function trackBackupProgress(array $logData): void
    {
        // In a real implementation, you'd update backup tracking
        Log::info('Backup progress tracked', $logData);
    }

    /**
     * Update backup dashboard.
     */
    protected function updateBackupDashboard(array $logData): void
    {
        // In a real implementation, you'd update dashboard
        Log::info('Backup dashboard updated', $logData);
    }

    /**
     * Send backup completion notification.
     */
    protected function sendBackupCompletionNotification(array $logData): void
    {
        // In a real implementation, you'd send notification
        Log::info('Backup completion notification sent', $logData);
    }

    /**
     * Track deployment stage.
     */
    protected function trackDeploymentStage(array $logData): void
    {
        // In a real implementation, you'd track deployment stages
        Log::info('Deployment stage tracked', $logData);
    }

    /**
     * Update deployment status.
     */
    protected function updateDeploymentStatus(array $logData): void
    {
        // In a real implementation, you'd update deployment status
        Log::info('Deployment status updated', $logData);
    }

    /**
     * Send deployment notification.
     */
    protected function sendDeploymentNotification(array $logData): void
    {
        // In a real implementation, you'd send deployment notification
        Log::info('Deployment notification sent', $logData);
    }

    /**
     * Send error alert.
     */
    protected function sendErrorAlert(array $logData): void
    {
        // In a real implementation, you'd send error alert
        Log::error('Error alert sent', $logData);
    }

    /**
     * Log to error tracking service.
     */
    protected function logToErrorTracking(array $logData): void
    {
        // In a real implementation, you'd log to error tracking service
        Log::error('Logged to error tracking service', $logData);
    }

    /**
     * Update error dashboard.
     */
    protected function updateErrorDashboard(array $logData): void
    {
        // In a real implementation, you'd update error dashboard
        Log::info('Error dashboard updated', $logData);
    }

    /**
     * Send completion notification.
     */
    protected function sendCompletionNotification(array $logData): void
    {
        // In a real implementation, you'd send completion notification
        Log::info('Completion notification sent', $logData);
    }

    /**
     * Update high priority dashboard.
     */
    protected function updateHighPriorityDashboard(array $logData): void
    {
        // In a real implementation, you'd update high priority dashboard
        Log::info('High priority dashboard updated', $logData);
    }

    /**
     * Get task priority from task instance.
     */
    public function getTaskPriority(Task $task): string
    {
        // In a real implementation, you'd determine priority from task properties
        return $task->priority ?? self::PRIORITY_NORMAL;
    }

    /**
     * Get task category from task instance.
     */
    public function getTaskCategory(Task $task): string
    {
        // In a real implementation, you'd determine category from task properties
        return $task->category ?? '';
    }

    /**
     * Check if task should be streamed based on conditions.
     */
    public function shouldStreamTask(Task $task, array $conditions = []): bool
    {
        $priority = $this->getTaskPriority($task);
        $category = $this->getTaskCategory($task);

        // Check priority conditions
        if (isset($conditions['min_priority'])) {
            $priorityLevels = [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_CRITICAL];
            $taskPriorityIndex = array_search($priority, $priorityLevels);
            $minPriorityIndex = array_search($conditions['min_priority'], $priorityLevels);

            if ($taskPriorityIndex < $minPriorityIndex) {
                return false;
            }
        }

        // Check category conditions
        if (isset($conditions['categories']) && ! in_array($category, $conditions['categories'])) {
            return false;
        }

        // Check time conditions
        if (isset($conditions['business_hours_only'])) {
            $hour = now()->hour;
            if ($hour < 9 || $hour > 17) {
                return false;
            }
        }

        return true;
    }
}
