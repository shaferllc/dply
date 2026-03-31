<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Services\ConditionalStreamingService;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\TaskChain;
use App\Modules\TaskRunner\Traits\HasProgressTracking;
use Illuminate\Support\Facades\Log;

/**
 * Example task demonstrating all streaming features.
 */
class AdvancedTaskExample extends Task
{
    use HasProgressTracking;

    public string $database = 'myapp';

    public string $backupPath = '/backups';

    public string $priority = 'high';

    public string $category = 'backup';

    public function render(): string
    {
        // Initialize progress tracking with 5 steps
        $this->initializeProgress(5, [
            'Step 1: Database backup started',
            'Step 2: Compressing backup files',
            'Step 3: Uploading to cloud storage',
            'Step 4: Verifying backup integrity',
            'Step 5: Cleanup temporary files',
        ]);

        return <<<BASH
        #!/bin/bash
        set -euo pipefail
        
        echo "Starting advanced backup process..."
        
        # Step 1: Database backup
        echo "Creating database backup..."
        mysqldump -u root -p {$this->database} > {$this->backupPath}/backup_$(date +%Y%m%d_%H%M%S).sql
        
        # Step 2: Compress backup
        echo "Compressing backup files..."
        gzip {$this->backupPath}/*.sql
        
        # Step 3: Upload to cloud
        echo "Uploading to cloud storage..."
        aws s3 cp {$this->backupPath}/*.sql.gz s3://my-backup-bucket/
        
        # Step 4: Verify integrity
        echo "Verifying backup integrity..."
        sha256sum {$this->backupPath}/*.sql.gz > {$this->backupPath}/checksums.txt
        
        # Step 5: Cleanup
        echo "Cleaning up temporary files..."
        rm -f {$this->backupPath}/*.sql.gz
        
        echo "Advanced backup completed successfully!"
        BASH;
    }

    /**
     * Example of using task chains with streaming.
     */
    public static function runBackupChain(): array
    {
        $chain = TaskChain::make()
            ->withStreaming(true)
            ->stopOnFailure(false);

        // Add multiple backup tasks
        $chain->addMany([
            new self(['database' => 'app1', 'priority' => 'critical']),
            new self(['database' => 'app2', 'priority' => 'high']),
            new self(['database' => 'app3', 'priority' => 'normal']),
        ]);

        return $chain->run();
    }

    /**
     * Example of conditional streaming configuration.
     */
    public static function runWithConditionalStreaming(): void
    {
        $task = new self;
        $conditionalService = app(ConditionalStreamingService::class);

        // Configure streaming based on task characteristics
        $conditionalService->configureTaskStreaming($task, [
            'priority' => ConditionalStreamingService::PRIORITY_HIGH,
            'category' => ConditionalStreamingService::CATEGORY_BACKUP,
            'notify_on_completion' => true,
            'notify_on_error' => true,
        ]);

        // Check if task should be streamed
        if ($conditionalService->shouldStreamTask($task, [
            'min_priority' => ConditionalStreamingService::PRIORITY_NORMAL,
            'categories' => [ConditionalStreamingService::CATEGORY_BACKUP],
            'business_hours_only' => false,
        ])) {
            // Execute task with streaming
            TaskRunner::run($task);
        }
    }

    /**
     * Example of progress tracking with custom steps.
     */
    public static function runWithProgressTracking(): void
    {
        $task = new self;

        // The progress tracking is automatically handled by the trait
        // Each step in the script will trigger progress updates
        TaskRunner::run($task);
    }

    /**
     * Example of WebSocket broadcasting.
     */
    public static function runWithWebSocketBroadcasting(): void
    {
        // Enable WebSocket broadcasting in config
        config(['task-runner.logging.streaming.handlers.websocket' => true]);

        $task = new self;
        TaskRunner::run($task);

        // The TaskRunnerBroadcaster will automatically handle WebSocket events
        // Frontend can listen to these events:
        // - Echo.channel('task-runner').listen('log', ...)
        // - Echo.channel('task-runner').listen('task-event', ...)
        // - Echo.channel('task-runner').listen('progress', ...)
    }

    /**
     * Example of metrics dashboard integration.
     */
    public static function runWithMetricsTracking(): void
    {
        $task = new self;

        // The TaskMetricsDashboard component will automatically receive updates
        // via WebSocket events and update the dashboard in real-time
        TaskRunner::run($task);
    }

    /**
     * Example of parallel task execution.
     */
    public static function runParallelTasks(): array
    {
        $chain = TaskChain::make()
            ->withStreaming(true);

        // Add tasks for parallel execution
        $chain->addMany([
            new self(['database' => 'app1']),
            new self(['database' => 'app2']),
            new self(['database' => 'app3']),
        ]);

        return $chain->runParallel();
    }

    /**
     * Example of custom streaming handlers.
     */
    public static function runWithCustomHandlers(): void
    {
        $streamingLogger = app(StreamingLoggerInterface::class);

        // Add custom handler for this specific task
        $streamingLogger->addStreamHandler(function ($logData) {
            // Custom logic for handling streaming data
            if ($logData['level'] === 'error') {
                // Send custom error notification
                Log::error('Custom error handler triggered', $logData);
            }

            if ($logData['context']['stream_type'] ?? '' === 'progress') {
                // Update custom progress bar
                $percentage = $logData['context']['percentage'] ?? 0;
                echo "Custom Progress: {$percentage}%\n";
            }
        }, 'custom-handler');

        $task = new self;
        TaskRunner::run($task);
    }

    /**
     * Example of file streaming.
     */
    public static function runWithFileStreaming(): void
    {
        // Enable file streaming in config
        config(['task-runner.logging.streaming.handlers.file' => true]);

        $task = new self;
        TaskRunner::run($task);

        // Logs will be written to: storage/logs/task-runner-streaming.log
    }

    /**
     * Example of console streaming with colors.
     */
    public static function runWithConsoleStreaming(): void
    {
        // Console streaming is enabled by default
        $task = new self;
        TaskRunner::run($task);

        // Output will be colored based on log level:
        // - Info: Green
        // - Warning: Yellow
        // - Error: Red
        // - Debug: White
    }

    /**
     * Example of task with all features combined.
     */
    public static function runCompleteExample(): array
    {
        // 1. Configure conditional streaming
        $task = new self;
        $conditionalService = app(ConditionalStreamingService::class);
        $conditionalService->configureTaskStreaming($task, [
            'priority' => ConditionalStreamingService::PRIORITY_CRITICAL,
            'category' => ConditionalStreamingService::CATEGORY_BACKUP,
            'notify_on_completion' => true,
            'notify_on_error' => true,
        ]);

        // 2. Create a task chain with streaming
        $chain = TaskChain::make()
            ->withStreaming(true)
            ->stopOnFailure(true);

        $chain->add($task);

        // 3. Run the chain
        $results = $chain->run();

        // 4. Return results for further processing
        return $results;
    }
}
