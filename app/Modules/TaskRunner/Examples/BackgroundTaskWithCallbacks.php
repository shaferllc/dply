<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\BaseTask;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\BackgroundTaskTracker;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Support\Facades\Log;

/**
 * Example demonstrating background task tracking with comprehensive callback support.
 * This shows how to track long-running tasks in the background with real-time updates.
 */
class BackgroundTaskWithCallbacks
{
    /**
     * Example: Deploy a Laravel application in the background with callbacks.
     */
    public function deployApplication(): void
    {
        // Create the actual deployment task
        $deploymentTask = new class extends BaseTask
        {
            public function handle(): void
            {
                // Configure callbacks for the deployment
                $this->setCallbackUrl('https://api.example.com/webhooks/deployment')
                    ->setCallbackConfig([
                        'timeout' => 60,
                        'max_attempts' => 5,
                        'enabled' => true,
                    ]);

                // Send deployment started callback
                $this->sendCallback(CallbackType::Started, [
                    'event' => 'deployment.started',
                    'environment' => 'production',
                    'version' => 'v2.1.0',
                ]);

                // Step 1: Pull latest code
                $this->sendProgressCallback(['step' => 'pulling_code', 'progress' => 10]);
                $this->runCommand('git pull origin main');

                // Step 2: Install dependencies
                $this->sendProgressCallback(['step' => 'installing_dependencies', 'progress' => 30]);
                $this->runCommand('composer install --no-dev --optimize-autoloader');

                // Step 3: Run migrations
                $this->sendProgressCallback(['step' => 'running_migrations', 'progress' => 60]);
                $this->runCommand('php artisan migrate --force');

                // Step 4: Clear caches
                $this->sendProgressCallback(['step' => 'clearing_caches', 'progress' => 80]);
                $this->runCommand('php artisan cache:clear');
                $this->runCommand('php artisan config:clear');

                // Step 5: Health check
                $this->sendProgressCallback(['step' => 'health_check', 'progress' => 90]);
                $this->runCommand('php artisan health:check');

                // Send completion callback
                $this->sendCallback(CallbackType::Finished, [
                    'event' => 'deployment.completed',
                    'environment' => 'production',
                    'version' => 'v2.1.0',
                    'success' => true,
                ]);
            }
        };

        // Configure the deployment task
        $deploymentTask
            ->setName('production-deployment')
            ->setTimeout(1800) // 30 minutes
            ->setCallbackUrl('https://api.example.com/webhooks/deployment')
            ->setCallbackConfig([
                'timeout' => 60,
                'max_attempts' => 5,
                'delay' => 10,
                'backoff_multiplier' => 1.5,
                'enabled' => true,
            ]);

        // Create background tracking task
        $backgroundTask = new TrackTaskInBackground(
            actualTask: $deploymentTask,
            finishedUrl: 'https://api.example.com/webhooks/deployment/finished',
            failedUrl: 'https://api.example.com/webhooks/deployment/failed',
            timeoutUrl: 'https://api.example.com/webhooks/deployment/timeout'
        );

        // Start the background task
        $backgroundTask->handle();
    }

    /**
     * Example: Database backup with progress tracking and callbacks.
     */
    public function backupDatabase(): void
    {
        $backupTask = new class extends BaseTask
        {
            public function handle(): void
            {
                $this->setCallbackUrl('https://api.example.com/webhooks/backup')
                    ->enableCallbacks();

                // Send backup started callback
                $this->sendCallback(CallbackType::Started, [
                    'event' => 'backup.started',
                    'database' => 'production_db',
                    'backup_type' => 'full',
                ]);

                // Create backup
                $backupFile = 'backup_'.date('Y-m-d_H-i-s').'.sql';

                $this->sendProgressCallback(['step' => 'creating_backup', 'progress' => 25]);
                $this->runCommand("pg_dump production_db > {$backupFile}");

                // Compress backup
                $this->sendProgressCallback(['step' => 'compressing_backup', 'progress' => 50]);
                $this->runCommand("gzip {$backupFile}");

                // Upload to storage
                $this->sendProgressCallback(['step' => 'uploading_backup', 'progress' => 75]);
                $this->runCommand("aws s3 cp {$backupFile}.gz s3://backups/");

                // Verify backup
                $this->sendProgressCallback(['step' => 'verifying_backup', 'progress' => 90]);
                $this->runCommand("aws s3 ls s3://backups/{$backupFile}.gz");

                // Send completion callback
                $this->sendCallback(CallbackType::Finished, [
                    'event' => 'backup.completed',
                    'database' => 'production_db',
                    'backup_file' => "{$backupFile}.gz",
                    'backup_size' => filesize("{$backupFile}.gz"),
                    'success' => true,
                ]);
            }
        };

        $backupTask
            ->setName('database-backup')
            ->setTimeout(3600) // 1 hour
            ->setCallbackConfig([
                'timeout' => 30,
                'max_attempts' => 3,
                'enabled' => true,
            ]);

        $backgroundTask = new TrackTaskInBackground(
            actualTask: $backupTask,
            finishedUrl: 'https://api.example.com/webhooks/backup/finished',
            failedUrl: 'https://api.example.com/webhooks/backup/failed',
            timeoutUrl: 'https://api.example.com/webhooks/backup/timeout'
        );

        $backgroundTask->handle();
    }

    /**
     * Example: Using BackgroundTaskTracker directly for custom tracking.
     */
    public function customBackgroundTracking(): void
    {
        $tracker = app(BackgroundTaskTracker::class);

        // Create a custom task
        $customTask = new class extends BaseTask
        {
            public function handle(): void
            {
                // Simulate long-running task
                for ($i = 1; $i <= 10; $i++) {
                    sleep(2); // Simulate work

                    // Send progress updates
                    $this->sendProgressCallback([
                        'step' => "processing_step_{$i}",
                        'progress' => $i * 10,
                        'message' => "Processing step {$i} of 10",
                    ]);
                }
            }
        };

        $customTask
            ->setName('custom-long-running-task')
            ->setTimeout(600) // 10 minutes
            ->setCallbackUrl('https://api.example.com/webhooks/custom-task')
            ->enableCallbacks();

        // Create task model
        $taskModel = Task::create([
            'name' => 'custom-long-running-task',
            'status' => TaskStatus::Pending,
            'script' => $customTask->getScript(),
            'options' => $customTask->getOptions(),
            'timeout' => 600,
            'user_id' => auth()->id(),
        ]);

        // Set task model on the task instance
        $customTask->setTaskModel($taskModel);

        // Start background tracking
        $tracker->startTracking($taskModel, $customTask);

        // The task will now be monitored in the background with callbacks
        Log::info('Custom background task started', [
            'task_id' => $taskModel->id,
            'task_name' => $taskModel->name,
        ]);
    }

    /**
     * Example: Monitoring multiple background tasks.
     */
    public function monitorBackgroundTasks(): void
    {
        $tracker = app(BackgroundTaskTracker::class);

        // Get all running background tasks
        $runningTasks = $tracker->getRunningTasks();

        foreach ($runningTasks as $taskInfo) {
            Log::info('Background task status', [
                'task_id' => $taskInfo['id'],
                'task_name' => $taskInfo['name'],
                'status' => $taskInfo['status'],
                'progress' => $taskInfo['progress'],
                'duration' => $taskInfo['duration'],
            ]);
        }

        // Clean up old completed tasks
        $deletedCount = $tracker->cleanupOldTasks(30); // Keep last 30 days

        Log::info('Cleaned up old background tasks', [
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Example: Webhook endpoint to receive task callbacks.
     */
    public function handleTaskCallback(): void
    {
        // This would be in a controller handling webhook requests
        $payload = request()->all();

        Log::info('Received task callback', $payload);

        switch ($payload['event'] ?? '') {
            case 'task_started':
                $this->handleTaskStarted($payload);
                break;

            case 'task_progress':
                $this->handleTaskProgress($payload);
                break;

            case 'task_completed':
                $this->handleTaskCompleted($payload);
                break;

            case 'task_failed':
                $this->handleTaskFailed($payload);
                break;

            case 'task_timeout':
                $this->handleTaskTimeout($payload);
                break;

            default:
                Log::warning('Unknown task callback event', $payload);
        }
    }

    /**
     * Handle task started callback.
     */
    protected function handleTaskStarted(array $payload): void
    {
        // Update UI to show task is running
        // Send notification to user
        // Update dashboard status

        Log::info('Task started', [
            'task_id' => $payload['task_id'],
            'task_name' => $payload['task_name'],
            'started_at' => $payload['started_at'],
        ]);
    }

    /**
     * Handle task progress callback.
     */
    protected function handleTaskProgress(array $payload): void
    {
        // Update progress bar
        // Send real-time updates to frontend
        // Update task status in database

        Log::info('Task progress update', [
            'task_id' => $payload['task_id'],
            'progress' => $payload['percentage'],
            'message' => $payload['message'] ?? '',
        ]);
    }

    /**
     * Handle task completed callback.
     */
    protected function handleTaskCompleted(array $payload): void
    {
        // Update UI to show completion
        // Send success notification
        // Trigger post-completion actions

        Log::info('Task completed', [
            'task_id' => $payload['task_id'],
            'task_name' => $payload['task_name'],
            'duration' => $payload['duration'],
            'exit_code' => $payload['exit_code'],
        ]);
    }

    /**
     * Handle task failed callback.
     */
    protected function handleTaskFailed(array $payload): void
    {
        // Update UI to show failure
        // Send error notification
        // Trigger error handling procedures

        Log::error('Task failed', [
            'task_id' => $payload['task_id'],
            'task_name' => $payload['task_name'],
            'error' => $payload['error'] ?? 'Unknown error',
        ]);
    }

    /**
     * Handle task timeout callback.
     */
    protected function handleTaskTimeout(array $payload): void
    {
        // Update UI to show timeout
        // Send timeout notification
        // Trigger timeout handling procedures

        Log::warning('Task timed out', [
            'task_id' => $payload['task_id'],
            'task_name' => $payload['task_name'],
            'timeout_duration' => $payload['timeout_duration'],
        ]);
    }

    /**
     * Example: Complete background task tracking with proper configuration.
     */
    public function completeBackgroundTaskExample(): void
    {
        // Step 1: Create a task that needs background tracking
        $longRunningTask = new class extends BaseTask
        {
            public function handle(): void
            {
                // Simulate a long-running process
                $steps = [
                    'Initializing system' => 10,
                    'Loading data' => 25,
                    'Processing records' => 50,
                    'Validating results' => 75,
                    'Finalizing' => 90,
                    'Cleanup' => 100,
                ];

                foreach ($steps as $step => $progress) {
                    // Send progress callback
                    $this->sendProgressCallback([
                        'step' => $step,
                        'progress' => $progress,
                        'message' => "Currently: {$step}",
                    ]);

                    // Simulate work
                    sleep(rand(2, 5));
                }

                // Send completion callback
                $this->sendCallback(CallbackType::Finished, [
                    'event' => 'task_completed',
                    'success' => true,
                    'total_records_processed' => 1000,
                    'processing_time' => '2 minutes',
                ]);
            }
        };

        // Step 2: Configure the task
        $longRunningTask
            ->setName('data-processing-task')
            ->setTimeout(1800) // 30 minutes
            ->setCallbackUrl('https://api.example.com/webhooks/data-processing')
            ->setCallbackConfig([
                'timeout' => 30,
                'max_attempts' => 3,
                'delay' => 5,
                'backoff_multiplier' => 2,
                'enabled' => true,
            ]);

        // Step 3: Create background tracking wrapper
        $backgroundTask = new TrackTaskInBackground(
            actualTask: $longRunningTask,
            finishedUrl: 'https://api.example.com/webhooks/data-processing/finished',
            failedUrl: 'https://api.example.com/webhooks/data-processing/failed',
            timeoutUrl: 'https://api.example.com/webhooks/data-processing/timeout'
        );

        // Step 4: Start the background task
        try {
            $backgroundTask->handle();

            Log::info('Background task started successfully', [
                'task_name' => $longRunningTask->getName(),
                'timeout' => $longRunningTask->getTimeout(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to start background task', [
                'task_name' => $longRunningTask->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
