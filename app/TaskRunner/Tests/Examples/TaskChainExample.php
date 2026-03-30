<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Examples;

use App\Modules\TaskRunner\Events\TaskChainCompleted;
use App\Modules\TaskRunner\Events\TaskChainFailed;
use App\Modules\TaskRunner\Events\TaskChainProgress;
use App\Modules\TaskRunner\Events\TaskChainStarted;
use App\Modules\TaskRunner\Facades\TaskRunner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Example demonstrating task chaining with streaming output.
 */
class TaskChainExample
{
    /**
     * Example of basic task chaining.
     */
    public static function runBasicChain(): void
    {
        echo "=== Basic Task Chain ===\n";

        $chain = TaskRunner::chain()
            ->addCommand('System Info', 'uname -a')
            ->addCommand('Current Directory', 'pwd')
            ->addCommand('User Info', 'whoami')
            ->addCommand('Process Count', 'ps aux | wc -l');

        $results = $chain->run();

        echo "Chain completed!\n";
        echo "Chain ID: {$results['chain_id']}\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Completed: {$results['completed_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']}s\n";
        echo 'Overall success: '.($results['overall_success'] ? 'Yes' : 'No')."\n\n";
    }

    /**
     * Example of task chaining with streaming events.
     */
    public static function runChainWithEvents(): void
    {
        echo "=== Task Chain with Events ===\n";

        // Register event listeners
        Event::listen(TaskChainStarted::class, function (TaskChainStarted $event) {
            echo "🚀 Task chain started: {$event->chainId}\n";
            echo "   Tasks: {$event->getTaskCount()}\n";
            echo '   Task names: '.implode(', ', $event->getTaskNames())."\n\n";
        });

        Event::listen(TaskChainProgress::class, function (TaskChainProgress $event) {
            $progressBar = $event->getProgressBar(30);
            echo "📊 Progress: {$progressBar} {$event->getPercentageInt()}%\n";
            echo "   Current: {$event->getCurrentTaskName()} ({$event->currentTask}/{$event->totalTasks})\n";
            echo "   Message: {$event->message}\n\n";
        });

        Event::listen(TaskChainCompleted::class, function (TaskChainCompleted $event) {
            echo "✅ Task chain completed: {$event->chainId}\n";
            echo "   Success rate: {$event->getSuccessRate()}%\n";
            echo "   Duration: {$event->getDurationForHumans()}\n";
            echo '   Overall success: '.($event->wasSuccessful() ? 'Yes' : 'No')."\n\n";
        });

        Event::listen(TaskChainFailed::class, function (TaskChainFailed $event) {
            echo "❌ Task chain failed: {$event->chainId}\n";
            echo "   Reason: {$event->getFailureReason()}\n";
            echo "   Failed task: {$event->getFailedTaskIndex()}\n";
            echo "   Success rate: {$event->getSuccessRate()}%\n\n";
        });

        // Run the chain
        $chain = TaskRunner::chain()
            ->addCommand('Step 1: Check System', 'echo "System check started" && uname -a')
            ->addCommand('Step 2: Check Disk', 'echo "Disk check started" && df -h')
            ->addCommand('Step 3: Check Memory', 'echo "Memory check started" && free -h')
            ->addCommand('Step 4: Check Network', 'echo "Network check started" && netstat -i')
            ->withProgressTracking(true);

        $results = $chain->run();
        echo "Chain execution completed!\n\n";
    }

    /**
     * Example of complex task chaining with different task types.
     */
    public static function runComplexChain(): void
    {
        echo "=== Complex Task Chain ===\n";

        $chain = TaskRunner::chain()
            ->addCommand('Database Backup', 'mysqldump -u root -p myapp > backup.sql')
            ->addCommands('System Maintenance', [
                'echo "Starting system maintenance"',
                'apt update',
                'apt upgrade -y',
                'systemctl restart nginx',
                'echo "System maintenance completed"',
            ])
            ->addView('Generate Report', 'reports.system-status', [
                'timestamp' => now(),
                'server' => gethostname(),
            ])
            ->addCallback('Data Processing', function () {
                echo "Processing data...\n";
                sleep(2);
                echo "Data processing completed\n";

                return true;
            })
            ->withOptions([
                'stop_on_failure' => true,
                'timeout' => 300,
                'progress_tracking' => true,
            ]);

        $results = $chain->run();

        echo "=== Chain Results ===\n";
        foreach ($results['results'] as $index => $result) {
            $status = $result['success'] ? '✅' : '❌';
            echo "{$status} {$result['task_name']}\n";

            if ($result['success']) {
                echo "   Exit code: {$result['exit_code']}\n";
                if (! empty($result['output'])) {
                    echo '   Output: '.substr($result['output'], 0, 100)."...\n";
                }
            } else {
                echo "   Error: {$result['error']}\n";
            }
            echo "\n";
        }
    }

    /**
     * Example of task chaining with failure handling.
     */
    public static function runChainWithFailureHandling(): void
    {
        echo "=== Task Chain with Failure Handling ===\n";

        // Chain that stops on failure
        echo "1. Chain with stop on failure:\n";
        try {
            $chain = TaskRunner::chain()
                ->addCommand('Step 1: Success', 'echo "Step 1 completed successfully"')
                ->addCommand('Step 2: Will Fail', 'exit 1')
                ->addCommand('Step 3: Never Reached', 'echo "This will not run"')
                ->stopOnFailure(true);

            $results = $chain->run();
            echo "   Chain completed successfully\n";
        } catch (\Exception $e) {
            echo "   Chain failed: {$e->getMessage()}\n";
        }

        // Chain that continues despite failures
        echo "2. Chain that continues despite failures:\n";
        $chain = TaskRunner::chain()
            ->addCommand('Step 1: Success', 'echo "Step 1 completed successfully"')
            ->addCommand('Step 2: Will Fail', 'exit 1')
            ->addCommand('Step 3: Will Run', 'echo "Step 3 completed successfully"')
            ->stopOnFailure(false);

        $results = $chain->run();
        echo "   Chain completed with success rate: {$results['success_rate']}%\n";
        echo "   Successful tasks: {$results['successful_tasks']}/{$results['total_tasks']}\n\n";
    }

    /**
     * Example of task chaining with timeout handling.
     */
    public static function runChainWithTimeout(): void
    {
        echo "=== Task Chain with Timeout ===\n";

        // Chain with timeout
        echo "1. Chain with 5-second timeout:\n";
        try {
            $chain = TaskRunner::chain()
                ->addCommand('Quick Task', 'echo "Quick task completed"')
                ->addCommand('Slow Task', 'sleep 10 && echo "Slow task completed"')
                ->addCommand('Another Quick Task', 'echo "Another quick task completed"')
                ->withTimeout(5);

            $results = $chain->run();
            echo "   Chain completed successfully\n";
        } catch (\Exception $e) {
            echo "   Chain failed: {$e->getMessage()}\n";
        }

        // Chain without timeout
        echo "2. Chain without timeout:\n";
        $chain = TaskRunner::chain()
            ->addCommand('Quick Task', 'echo "Quick task completed"')
            ->addCommand('Slow Task', 'sleep 3 && echo "Slow task completed"')
            ->addCommand('Another Quick Task', 'echo "Another quick task completed"');

        $results = $chain->run();
        echo "   Chain completed with success rate: {$results['success_rate']}%\n\n";
    }

    /**
     * Example of task chaining with aggregated output.
     */
    public static function runChainWithAggregatedOutput(): void
    {
        echo "=== Task Chain with Aggregated Output ===\n";

        $chain = TaskRunner::chain()
            ->addCommand('System Info', 'uname -a && echo "---"')
            ->addCommand('Disk Usage', 'df -h && echo "---"')
            ->addCommand('Memory Usage', 'free -h && echo "---"')
            ->addCommand('Process Count', 'echo "Total processes: $(ps aux | wc -l)" && echo "---"')
            ->addCommand('Network Interfaces', 'ip addr show && echo "---"');

        $results = $chain->run();

        echo "=== Aggregated Output ===\n";
        $aggregatedOutput = $chain->getAggregatedOutput();
        echo $aggregatedOutput;

        echo "\n=== Aggregated Errors ===\n";
        $aggregatedErrors = $chain->getAggregatedErrors();
        if (! empty($aggregatedErrors)) {
            echo $aggregatedErrors;
        } else {
            echo "No errors occurred.\n";
        }

        echo "\n=== Performance Summary ===\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']}s\n\n";
    }

    /**
     * Example of task chaining for deployment workflow.
     */
    public static function runDeploymentChain(): void
    {
        echo "=== Deployment Task Chain ===\n";

        $chain = TaskRunner::chain()
            ->addCommand('Pre-deployment Check', '
                echo "Checking system status..."
                systemctl is-active --quiet nginx && echo "Nginx is running"
                systemctl is-active --quiet mysql && echo "MySQL is running"
                echo "Pre-deployment check completed"
            ')
            ->addCommand('Backup Database', '
                echo "Creating database backup..."
                mysqldump -u root -p myapp > backup_$(date +%Y%m%d_%H%M%S).sql
                echo "Database backup completed"
            ')
            ->addCommand('Update Code', '
                echo "Updating application code..."
                cd /var/www/myapp
                git pull origin main
                composer install --no-dev --optimize-autoloader
                echo "Code update completed"
            ')
            ->addCommand('Clear Cache', '
                echo "Clearing application cache..."
                cd /var/www/myapp
                php artisan cache:clear
                php artisan config:clear
                php artisan route:clear
                echo "Cache cleared"
            ')
            ->addCommand('Run Migrations', '
                echo "Running database migrations..."
                cd /var/www/myapp
                php artisan migrate --force
                echo "Migrations completed"
            ')
            ->addCommand('Restart Services', '
                echo "Restarting services..."
                systemctl reload nginx
                systemctl reload php-fpm
                echo "Services restarted"
            ')
            ->addCommand('Post-deployment Check', '
                echo "Performing post-deployment checks..."
                curl -f http://localhost/health || echo "Health check failed"
                echo "Post-deployment check completed"
            ')
            ->withOptions([
                'stop_on_failure' => true,
                'timeout' => 600, // 10 minutes
                'progress_tracking' => true,
            ]);

        $results = $chain->run();

        echo "=== Deployment Results ===\n";
        echo 'Deployment '.($results['overall_success'] ? 'SUCCESSFUL' : 'FAILED')."\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']}s\n";
        echo "Tasks completed: {$results['completed_tasks']}/{$results['total_tasks']}\n\n";

        if (! $results['overall_success']) {
            echo "=== Failed Tasks ===\n";
            foreach ($results['results'] as $index => $result) {
                if (! $result['success']) {
                    echo "❌ {$result['task_name']}: {$result['error']}\n";
                }
            }
        }
    }

    /**
     * Example of task chaining with monitoring and alerting.
     */
    public static function runChainWithMonitoring(): void
    {
        echo "=== Task Chain with Monitoring ===\n";

        // Set up monitoring listeners
        Event::listen(TaskChainCompleted::class, function (TaskChainCompleted $event) {
            // Alert on low success rates
            if ($event->getSuccessRate() < 100) {
                Log::warning('Task chain completed with failures', [
                    'chain_id' => $event->chainId,
                    'success_rate' => $event->getSuccessRate(),
                    'total_tasks' => $event->getTotalTasks(),
                    'failed_tasks' => $event->getFailedTasks(),
                ]);
            }

            // Alert on slow execution
            if ($event->getDuration() > 60) {
                Log::warning('Task chain took longer than expected', [
                    'chain_id' => $event->chainId,
                    'duration' => $event->getDurationForHumans(),
                    'total_tasks' => $event->getTotalTasks(),
                ]);
            }

            // Log successful completion
            Log::info('Task chain completed successfully', [
                'chain_id' => $event->chainId,
                'success_rate' => $event->getSuccessRate(),
                'duration' => $event->getDurationForHumans(),
            ]);
        });

        Event::listen(TaskChainFailed::class, function (TaskChainFailed $event) {
            // Critical alert on chain failure
            Log::critical('Task chain failed', [
                'chain_id' => $event->chainId,
                'failure_reason' => $event->getFailureReason(),
                'failed_task_index' => $event->getFailedTaskIndex(),
                'success_rate' => $event->getSuccessRate(),
            ]);
        });

        // Run various chains
        $chains = [
            'Quick Health Check' => TaskRunner::chain()
                ->addCommand('System Load', 'uptime')
                ->addCommand('Disk Space', 'df -h /')
                ->addCommand('Memory Usage', 'free -h'),

            'Database Maintenance' => TaskRunner::chain()
                ->addCommand('Backup Database', 'mysqldump -u root -p myapp > backup.sql')
                ->addCommand('Optimize Tables', 'mysql -u root -p -e "OPTIMIZE TABLE myapp.*"')
                ->addCommand('Check Integrity', 'mysqlcheck -u root -p myapp'),

            'Application Update' => TaskRunner::chain()
                ->addCommand('Pull Code', 'cd /var/www/myapp && git pull')
                ->addCommand('Install Dependencies', 'cd /var/www/myapp && composer install')
                ->addCommand('Run Migrations', 'cd /var/www/myapp && php artisan migrate')
                ->addCommand('Clear Cache', 'cd /var/www/myapp && php artisan cache:clear'),
        ];

        foreach ($chains as $name => $chain) {
            echo "Running: {$name}\n";
            try {
                $results = $chain->run();
                echo "   ✅ Completed with {$results['success_rate']}% success rate\n";
            } catch (\Exception $e) {
                echo "   ❌ Failed: {$e->getMessage()}\n";
            }
        }

        echo "\nMonitoring and alerting completed!\n\n";
    }

    /**
     * Run all task chain examples.
     */
    public static function runAllExamples(): void
    {
        echo "=== Running Task Chain Examples ===\n\n";

        self::runBasicChain();
        self::runChainWithEvents();
        self::runComplexChain();
        self::runChainWithFailureHandling();
        self::runChainWithTimeout();
        self::runChainWithAggregatedOutput();
        self::runDeploymentChain();
        self::runChainWithMonitoring();

        echo "=== All Task Chain Examples Completed ===\n";
    }
}
