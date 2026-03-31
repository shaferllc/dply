<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Services\ConditionalStreamingService;
use App\Modules\TaskRunner\TaskChain;

/**
 * Example demonstrating anonymous task usage with all streaming features.
 */
class AnonymousTaskExample
{
    /**
     * Example of using simple anonymous tasks.
     */
    public static function runSimpleAnonymousTasks(): void
    {
        echo "=== Simple Anonymous Tasks ===\n";

        // Simple command
        $result = TaskRunner::runAnonymous(
            AnonymousTask::command('List Files', 'ls -la')
        );
        echo 'List files result: '.$result->getExitCode()."\n";

        // Multiple commands
        $result = TaskRunner::runAnonymous(
            AnonymousTask::commands('System Info', [
                'uname -a',
                'whoami',
                'pwd',
                'date',
            ])
        );
        echo 'System info result: '.$result->getExitCode()."\n";

        // Inline script
        $result = TaskRunner::runAnonymous(
            AnonymousTask::inline('Custom Script', '
                echo "Hello from anonymous task!"
                echo "Current directory: $(pwd)"
                echo "User: $(whoami)"
                echo "Timestamp: $(date)"
            ')
        );
        echo 'Custom script result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with environment variables.
     */
    public static function runAnonymousTasksWithEnv(): void
    {
        echo "\n=== Anonymous Tasks with Environment ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::withEnv('Database Backup', [
                'DB_HOST' => 'localhost',
                'DB_USER' => 'backup_user',
                'DB_PASS' => 'secure_password',
                'DB_NAME' => 'myapp',
                'BACKUP_DIR' => '/backups',
            ], 'mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME > $BACKUP_DIR/backup_$(date +%Y%m%d_%H%M%S).sql')
        );
        echo 'Database backup result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with conditional logic.
     */
    public static function runAnonymousTasksWithConditionals(): void
    {
        echo "\n=== Anonymous Tasks with Conditionals ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::conditional('Conditional Task', [
                '[ -f /tmp/test.txt ]' => [
                    'echo "File exists, updating..."',
                    'echo "Updated at $(date)" >> /tmp/test.txt',
                ],
                '[ -d /var/log ]' => 'echo "Log directory exists"',
                '[ $(whoami) = "root" ]' => 'echo "Running as root"',
                'default' => 'echo "No conditions met"',
            ])
        );
        echo 'Conditional task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with error handling.
     */
    public static function runAnonymousTasksWithErrorHandling(): void
    {
        echo "\n=== Anonymous Tasks with Error Handling ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::withErrorHandling(
                'Error Handling Task',
                'ls /nonexistent/directory',
                'echo "Directory does not exist, creating it..." && mkdir -p /tmp/backup'
            )
        );
        echo 'Error handling task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with retry logic.
     */
    public static function runAnonymousTasksWithRetry(): void
    {
        echo "\n=== Anonymous Tasks with Retry Logic ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::withRetry(
                'Retry Task',
                'curl -f http://localhost:8080/health',
                3,
                2
            )
        );
        echo 'Retry task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with progress tracking.
     */
    public static function runAnonymousTasksWithProgress(): void
    {
        echo "\n=== Anonymous Tasks with Progress Tracking ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::withProgress('Progress Task', [
                'Step 1: Check system' => 'uname -a',
                'Step 2: Check disk space' => 'df -h',
                'Step 3: Check memory' => 'free -h',
                'Step 4: Check processes' => 'ps aux | head -10',
                'Step 5: Final check' => 'echo "All checks completed"',
            ])
        );
        echo 'Progress task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with cleanup.
     */
    public static function runAnonymousTasksWithCleanup(): void
    {
        echo "\n=== Anonymous Tasks with Cleanup ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::withCleanup(
                'Cleanup Task',
                'echo "Creating temporary file..." && echo "temp data" > /tmp/test_cleanup.txt && cat /tmp/test_cleanup.txt',
                'rm -f /tmp/test_cleanup.txt && echo "Cleanup completed"'
            )
        );
        echo 'Cleanup task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with logging.
     */
    public static function runAnonymousTasksWithLogging(): void
    {
        echo "\n=== Anonymous Tasks with Logging ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::withLogging(
                'Logging Task',
                'echo "This is a test message" && sleep 2 && echo "Task completed successfully"',
                '/tmp/anonymous_task.log'
            )
        );
        echo 'Logging task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with views.
     */
    public static function runAnonymousTasksWithViews(): void
    {
        echo "\n=== Anonymous Tasks with Views ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::view('Database Backup via View', 'tasks.database-backup', [
                'database_name' => 'test_db',
                'backup_path' => '/tmp/backups',
                'compression' => 'gzip',
                'retention_days' => 7,
                'notify_on_success' => 'false',
                'notify_on_error' => 'true',
            ])
        );
        echo 'View task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with callbacks.
     */
    public static function runAnonymousTasksWithCallbacks(): void
    {
        echo "\n=== Anonymous Tasks with Callbacks ===\n";

        $result = TaskRunner::runAnonymous(
            AnonymousTask::callback('Callback Task', function ($task) {
                $timestamp = now()->toISOString();
                $user = get_current_user();
                $hostname = gethostname();

                return "#!/bin/bash\nset -euo pipefail\n\n";

                return "# Callback Task\n";

                return "# Generated: {$timestamp}\n\n";

                return "echo 'Hello from callback!'\n";

                return "echo 'User: {$user}'\n";

                return "echo 'Hostname: {$hostname}'\n";

                return "echo 'Timestamp: {$timestamp}'\n";

                return "echo 'Task completed successfully'\n";
            })
        );
        echo 'Callback task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with streaming.
     */
    public static function runAnonymousTasksWithStreaming(): void
    {
        echo "\n=== Anonymous Tasks with Streaming ===\n";

        // Create a task with progress tracking
        $task = AnonymousTask::withProgress('Streaming Task', [
            'Step 1: Initialize' => 'echo "Initializing..." && sleep 1',
            'Step 2: Process data' => 'echo "Processing data..." && sleep 2',
            'Step 3: Validate' => 'echo "Validating results..." && sleep 1',
            'Step 4: Cleanup' => 'echo "Cleaning up..." && sleep 1',
        ]);

        // Initialize progress tracking
        $task->initializeProgress(4, [
            'Step 1: Initialize',
            'Step 2: Process data',
            'Step 3: Validate',
            'Step 4: Cleanup',
        ]);

        // Configure conditional streaming
        $conditionalService = app(ConditionalStreamingService::class);
        $conditionalService->configureTaskStreaming($task, [
            'priority' => ConditionalStreamingService::PRIORITY_NORMAL,
            'category' => ConditionalStreamingService::CATEGORY_MAINTENANCE,
            'notify_on_completion' => true,
            'notify_on_error' => true,
        ]);

        $result = TaskRunner::runAnonymous($task);
        echo 'Streaming task result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks in chains.
     */
    public static function runAnonymousTasksInChains(): void
    {
        echo "\n=== Anonymous Tasks in Chains ===\n";

        $chain = TaskChain::make()
            ->withStreaming(true)
            ->stopOnFailure(false);

        // Add anonymous tasks to chain
        $chain->addMany([
            AnonymousTask::command('Step 1: Check System', 'uname -a'),
            AnonymousTask::command('Step 2: Check Disk', 'df -h'),
            AnonymousTask::command('Step 3: Check Memory', 'free -h'),
            AnonymousTask::commands('Step 4: Multiple Commands', [
                'echo "Starting step 4"',
                'whoami',
                'pwd',
                'echo "Step 4 completed"',
            ]),
            AnonymousTask::withEnv('Step 5: With Environment', [
                'CUSTOM_VAR' => 'test_value',
            ], 'echo "Custom variable: $CUSTOM_VAR"'),
        ]);

        $results = $chain->run();
        echo 'Chain completed. Successful: '.($results['successful'] ? 'Yes' : 'No')."\n";
        echo 'Total tasks: '.$results['total_tasks']."\n";
        echo 'Successful tasks: '.$results['successful_tasks']."\n";
    }

    /**
     * Example of using facade methods for anonymous tasks.
     */
    public static function runAnonymousTasksWithFacade(): void
    {
        echo "\n=== Anonymous Tasks with Facade ===\n";

        // Using facade methods directly
        $result = TaskRunner::runAnonymous(
            TaskRunner::command('Facade Command', 'echo "Hello from facade!"')
        );
        echo 'Facade command result: '.$result->getExitCode()."\n";

        $result = TaskRunner::runAnonymous(
            TaskRunner::commands('Facade Commands', [
                'echo "Command 1"',
                'echo "Command 2"',
                'echo "Command 3"',
            ])
        );
        echo 'Facade commands result: '.$result->getExitCode()."\n";

        $result = TaskRunner::runAnonymous(
            TaskRunner::view('Facade View', 'tasks.database-backup', [
                'database_name' => 'facade_test',
                'backup_path' => '/tmp/facade_backups',
            ])
        );
        echo 'Facade view result: '.$result->getExitCode()."\n";

        $result = TaskRunner::runAnonymous(
            TaskRunner::callback('Facade Callback', function ($task) {
                return "#!/bin/bash\necho 'Hello from facade callback!'\n";
            })
        );
        echo 'Facade callback result: '.$result->getExitCode()."\n";
    }

    /**
     * Example of using anonymous tasks with complex configurations.
     */
    public static function runComplexAnonymousTasks(): void
    {
        echo "\n=== Complex Anonymous Tasks ===\n";

        // Create a complex task with multiple features
        $task = AnonymousTask::script('Complex Task', '
            #!/bin/bash
            set -euo pipefail

            echo "=== Complex Anonymous Task ==="
            echo "Starting at: $(date)"

            # Set environment variables
            export TASK_ID="'.uniqid().'"
            export TASK_TIMESTAMP="'.now()->toISOString().'"

            echo "Task ID: $TASK_ID"
            echo "Timestamp: $TASK_TIMESTAMP"

            # Check system resources
            echo "=== System Resources ==="
            echo "CPU: $(nproc) cores"
            echo "Memory: $(free -h | grep Mem | awk \'{print $2}\')"
            echo "Disk: $(df -h / | tail -1 | awk \'{print $4}\') available"

            # Perform some operations
            echo "=== Performing Operations ==="
            for i in {1..3}; do
                echo "Operation $i/3"
                sleep 1
            done

            # Create a temporary file
            TEMP_FILE="/tmp/complex_task_$TASK_ID.txt"
            echo "Creating temporary file: $TEMP_FILE"
            echo "Task completed at $(date)" > "$TEMP_FILE"

            # Cleanup
            echo "=== Cleanup ==="
            rm -f "$TEMP_FILE"
            echo "Temporary file removed"

            echo "=== Task Completed Successfully ==="
        ', [
            'timeout' => 30,
            'data' => [
                'complex' => true,
                'features' => ['env', 'logging', 'cleanup', 'progress'],
            ],
        ]);

        // Add data to the task
        $task->addData([
            'additional_info' => 'This is additional data',
            'priority' => 'high',
        ]);

        $result = TaskRunner::runAnonymous($task);
        echo 'Complex task result: '.$result->getExitCode()."\n";
    }

    /**
     * Run all anonymous task examples.
     */
    public static function runAllExamples(): void
    {
        echo "=== Running Anonymous Task Examples ===\n\n";

        self::runSimpleAnonymousTasks();
        self::runAnonymousTasksWithEnv();
        self::runAnonymousTasksWithConditionals();
        self::runAnonymousTasksWithErrorHandling();
        self::runAnonymousTasksWithRetry();
        self::runAnonymousTasksWithProgress();
        self::runAnonymousTasksWithCleanup();
        self::runAnonymousTasksWithLogging();
        self::runAnonymousTasksWithViews();
        self::runAnonymousTasksWithCallbacks();
        self::runAnonymousTasksWithStreaming();
        self::runAnonymousTasksInChains();
        self::runAnonymousTasksWithFacade();
        self::runComplexAnonymousTasks();

        echo "\n=== All Anonymous Task Examples Completed ===\n";
    }
}
