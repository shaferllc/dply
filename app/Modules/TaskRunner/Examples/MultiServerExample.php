<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Events\MultiServerTaskCompleted;
use App\Modules\TaskRunner\Events\MultiServerTaskFailed;
use App\Modules\TaskRunner\Events\MultiServerTaskStarted;
use App\Modules\TaskRunner\Facades\TaskRunner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Example demonstrating multi-server task dispatch features.
 */
class MultiServerExample
{
    /**
     * Example of basic multi-server dispatch.
     */
    public static function runBasicMultiServerDispatch(): void
    {
        echo "=== Basic Multi-Server Dispatch ===\n";

        $connections = ['server1', 'server2', 'server3'];
        $task = AnonymousTask::command('System Check', 'uname -a && whoami && pwd');

        $results = TaskRunner::dispatchToMultipleServers($task, $connections);

        echo "Multi-server task completed!\n";
        echo "Task ID: {$results['multi_server_task_id']}\n";
        echo "Total servers: {$results['total_servers']}\n";
        echo "Successful: {$results['successful_servers']}\n";
        echo "Failed: {$results['failed_servers']}\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']}s\n";
        echo 'Overall success: '.($results['overall_success'] ? 'Yes' : 'No')."\n\n";
    }

    /**
     * Example of parallel vs sequential execution.
     */
    public static function runParallelVsSequential(): void
    {
        echo "=== Parallel vs Sequential Execution ===\n";

        $connections = ['server1', 'server2', 'server3', 'server4'];
        $task = AnonymousTask::command('Sleep Test', 'sleep 2 && echo "Completed on $(hostname)"');

        // Parallel execution
        echo "Running in parallel...\n";
        $startTime = microtime(true);
        $parallelResults = TaskRunner::dispatchToMultipleServers($task, $connections, [
            'parallel' => true,
        ]);
        $parallelDuration = microtime(true) - $startTime;

        echo 'Parallel execution completed in '.round($parallelDuration, 2)."s\n";
        echo "Success rate: {$parallelResults['success_rate']}%\n\n";

        // Sequential execution
        echo "Running sequentially...\n";
        $startTime = microtime(true);
        $sequentialResults = TaskRunner::dispatchToMultipleServers($task, $connections, [
            'parallel' => false,
        ]);
        $sequentialDuration = microtime(true) - $startTime;

        echo 'Sequential execution completed in '.round($sequentialDuration, 2)."s\n";
        echo "Success rate: {$sequentialResults['success_rate']}%\n\n";

        echo 'Speed improvement: '.round(($sequentialDuration / $parallelDuration), 2)."x faster with parallel execution\n\n";
    }

    /**
     * Example of failure handling options.
     */
    public static function runFailureHandling(): void
    {
        echo "=== Failure Handling Options ===\n";

        $connections = ['server1', 'server2', 'server3', 'server4'];

        // Task that will fail on some servers
        $task = AnonymousTask::command('Conditional Fail', '
            if [ "$(hostname)" = "server2" ]; then
                echo "Failing on server2"
                exit 1
            else
                echo "Success on $(hostname)"
                exit 0
            fi
        ');

        // Stop on first failure
        echo "1. Stop on first failure:\n";
        try {
            $results = TaskRunner::dispatchToMultipleServers($task, $connections, [
                'stop_on_failure' => true,
            ]);
            echo "   Completed with success rate: {$results['success_rate']}%\n";
        } catch (\Exception $e) {
            echo "   Failed: {$e->getMessage()}\n";
        }

        // Continue despite failures
        echo "2. Continue despite failures:\n";
        $results = TaskRunner::dispatchToMultipleServers($task, $connections, [
            'stop_on_failure' => false,
        ]);
        echo "   Completed with success rate: {$results['success_rate']}%\n";

        // Minimum success requirement
        echo "3. Minimum success requirement (2 servers):\n";
        $results = TaskRunner::dispatchToMultipleServers($task, $connections, [
            'min_success' => 2,
        ]);
        echo '   Overall success: '.($results['overall_success'] ? 'Yes' : 'No')."\n";

        // Maximum failures allowed
        echo "4. Maximum failures allowed (1 server):\n";
        $results = TaskRunner::dispatchToMultipleServers($task, $connections, [
            'max_failures' => 1,
        ]);
        echo '   Overall success: '.($results['overall_success'] ? 'Yes' : 'No')."\n\n";
    }

    /**
     * Example of timeout handling.
     */
    public static function runTimeoutHandling(): void
    {
        echo "=== Timeout Handling ===\n";

        $connections = ['server1', 'server2', 'server3'];

        // Task that takes different time on different servers
        $task = AnonymousTask::command('Variable Duration', '
            case "$(hostname)" in
                "server1") sleep 1; echo "Quick on server1" ;;
                "server2") sleep 5; echo "Slow on server2" ;;
                "server3") sleep 2; echo "Medium on server3" ;;
            esac
        ');

        // With timeout
        echo "With 3-second timeout:\n";
        $results = TaskRunner::dispatchToMultipleServers($task, $connections, [
            'timeout' => 3,
        ]);
        echo "Success rate: {$results['success_rate']}%\n";
        echo 'Failed servers: '.implode(', ', $results['failed_connections'])."\n\n";

        // Without timeout
        echo "Without timeout:\n";
        $results = TaskRunner::dispatchToMultipleServers($task, $connections);
        echo "Success rate: {$results['success_rate']}%\n\n";
    }

    /**
     * Example of using multi-server events.
     */
    public static function runMultiServerEvents(): void
    {
        echo "=== Multi-Server Events ===\n";

        // Register event listeners
        Event::listen(MultiServerTaskStarted::class, function (MultiServerTaskStarted $event) {
            echo "🚀 Multi-server task started: {$event->getTaskName()}\n";
            echo "   Servers: {$event->getServerCount()}\n";
            echo '   Parallel: '.($event->isParallel() ? 'Yes' : 'No')."\n";
            echo "   Task ID: {$event->multiServerTaskId}\n\n";
        });

        Event::listen(MultiServerTaskCompleted::class, function (MultiServerTaskCompleted $event) {
            echo "✅ Multi-server task completed: {$event->getTaskName()}\n";
            echo "   Success rate: {$event->getSuccessRate()}%\n";
            echo "   Duration: {$event->getDurationForHumans()}\n";
            echo "   Successful: {$event->getSuccessfulServers()}/{$event->getTotalServers()}\n\n";
        });

        Event::listen(MultiServerTaskFailed::class, function (MultiServerTaskFailed $event) {
            echo "❌ Multi-server task failed: {$event->getTaskName()}\n";
            echo "   Success rate: {$event->getSuccessRate()}%\n";
            echo "   Error: {$event->getErrorMessage()}\n";
            echo "   Failed connection: {$event->getFailedConnection()}\n\n";
        });

        // Run multi-server task
        $connections = ['server1', 'server2', 'server3'];
        $task = AnonymousTask::command('Event Test', 'echo "Hello from $(hostname)"');

        $results = TaskRunner::dispatchToMultipleServers($task, $connections);
        echo "Event test completed with success rate: {$results['success_rate']}%\n\n";
    }

    /**
     * Example of complex multi-server scenarios.
     */
    public static function runComplexScenarios(): void
    {
        echo "=== Complex Multi-Server Scenarios ===\n";

        $connections = ['web1', 'web2', 'web3', 'db1', 'db2', 'cache1'];

        // Scenario 1: Database backup across multiple DB servers
        echo "1. Database backup across multiple servers:\n";
        $backupTask = AnonymousTask::withEnv('Database Backup', [
            'DB_NAME' => 'myapp',
            'BACKUP_DIR' => '/backups',
        ], 'mysqldump -u root -p $DB_NAME > $BACKUP_DIR/backup_$(date +%Y%m%d_%H%M%S).sql');

        $dbConnections = ['db1', 'db2'];
        $backupResults = TaskRunner::dispatchToMultipleServers($backupTask, $dbConnections, [
            'parallel' => true,
            'timeout' => 300, // 5 minutes
            'min_success' => 1, // At least one backup must succeed
        ]);

        echo "   Backup success rate: {$backupResults['success_rate']}%\n";

        // Scenario 2: Web server maintenance
        echo "2. Web server maintenance:\n";
        $maintenanceTask = AnonymousTask::commands('Web Maintenance', [
            'echo "Starting maintenance on $(hostname)"',
            'systemctl reload nginx',
            'systemctl reload php-fpm',
            'echo "Maintenance completed on $(hostname)"',
        ]);

        $webConnections = ['web1', 'web2', 'web3'];
        $maintenanceResults = TaskRunner::dispatchToMultipleServers($maintenanceTask, $webConnections, [
            'parallel' => false, // Sequential to avoid downtime
            'stop_on_failure' => true,
        ]);

        echo "   Maintenance success rate: {$maintenanceResults['success_rate']}%\n";

        // Scenario 3: Cache clearing
        echo "3. Cache clearing:\n";
        $cacheTask = AnonymousTask::command('Cache Clear', 'redis-cli FLUSHALL && echo "Cache cleared on $(hostname)"');

        $cacheConnections = ['cache1'];
        $cacheResults = TaskRunner::dispatchToMultipleServers($cacheTask, $cacheConnections);

        echo "   Cache clear success rate: {$cacheResults['success_rate']}%\n\n";
    }

    /**
     * Example of monitoring and alerting with multi-server tasks.
     */
    public static function runMonitoringAndAlerting(): void
    {
        echo "=== Multi-Server Monitoring and Alerting ===\n";

        // Set up monitoring listeners
        Event::listen(MultiServerTaskCompleted::class, function (MultiServerTaskCompleted $event) {
            // Alert on low success rates
            if ($event->getSuccessRate() < 80) {
                Log::warning('Low multi-server success rate', [
                    'task_name' => $event->getTaskName(),
                    'success_rate' => $event->getSuccessRate(),
                    'total_servers' => $event->getTotalServers(),
                    'failed_servers' => $event->getFailedServers(),
                ]);
            }

            // Alert on slow execution
            if ($event->getDuration() > 60) {
                Log::warning('Slow multi-server task execution', [
                    'task_name' => $event->getTaskName(),
                    'duration' => $event->getDurationForHumans(),
                    'total_servers' => $event->getTotalServers(),
                ]);
            }
        });

        Event::listen(MultiServerTaskFailed::class, function (MultiServerTaskFailed $event) {
            // Critical alert on complete failure
            if ($event->getSuccessRate() === 0) {
                Log::critical('Complete multi-server task failure', [
                    'task_name' => $event->getTaskName(),
                    'failed_connections' => $event->getFailedConnections(),
                    'error_message' => $event->getErrorMessage(),
                ]);
            }
        });

        // Run various scenarios
        $connections = ['server1', 'server2', 'server3', 'server4'];

        // Quick task (should succeed)
        $quickTask = AnonymousTask::command('Quick Check', 'echo "Quick check on $(hostname)"');
        $quickResults = TaskRunner::dispatchToMultipleServers($quickTask, $connections);
        echo "Quick check success rate: {$quickResults['success_rate']}%\n";

        // Slow task (should trigger duration alert)
        $slowTask = AnonymousTask::command('Slow Task', 'sleep 3 && echo "Slow task on $(hostname)"');
        $slowResults = TaskRunner::dispatchToMultipleServers($slowTask, $connections);
        echo "Slow task success rate: {$slowResults['success_rate']}%\n";

        // Failing task (should trigger failure alerts)
        $failingTask = AnonymousTask::command('Failing Task', 'exit 1');
        $failingResults = TaskRunner::dispatchToMultipleServers($failingTask, $connections);
        echo "Failing task success rate: {$failingResults['success_rate']}%\n\n";
    }

    /**
     * Example of result analysis and reporting.
     */
    public static function runResultAnalysis(): void
    {
        echo "=== Multi-Server Result Analysis ===\n";

        $connections = ['prod1', 'prod2', 'prod3', 'staging1', 'staging2'];
        $task = AnonymousTask::command('System Analysis', '
            echo "=== System Analysis on $(hostname) ==="
            echo "CPU: $(nproc) cores"
            echo "Memory: $(free -h | grep Mem | awk \'{print $2}\')"
            echo "Disk: $(df -h / | tail -1 | awk \'{print $4}\') available"
            echo "Load: $(uptime | awk -F\'load average:\' \'{print $2}\')"
        ');

        $results = TaskRunner::dispatchToMultipleServers($task, $connections);

        echo "=== Analysis Results ===\n";
        echo "Task ID: {$results['multi_server_task_id']}\n";
        echo "Total servers analyzed: {$results['total_servers']}\n";
        echo "Successful analyses: {$results['successful_servers']}\n";
        echo "Failed analyses: {$results['failed_servers']}\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']}s\n\n";

        echo "=== Server Results ===\n";
        foreach ($results['results'] as $connection => $result) {
            $status = $result['success'] ? '✅' : '❌';
            echo "{$status} {$connection}: ";

            if ($result['success']) {
                echo "Exit code {$result['exit_code']}, Duration {$result['duration']}s\n";
                echo '   Output: '.substr($result['output'], 0, 100)."...\n";
            } else {
                echo "Failed: {$result['error']}\n";
            }
        }

        echo "\n=== Performance Summary ===\n";
        $successfulResults = array_filter($results['results'], fn ($r) => $r['success']);
        if (! empty($successfulResults)) {
            $durations = array_column($successfulResults, 'duration');
            echo 'Average duration: '.round(array_sum($durations) / count($durations), 2)."s\n";
            echo 'Min duration: '.min($durations)."s\n";
            echo 'Max duration: '.max($durations)."s\n";
        }

        echo 'Overall success: '.($results['overall_success'] ? 'Yes' : 'No')."\n\n";
    }

    /**
     * Run all multi-server examples.
     */
    public static function runAllExamples(): void
    {
        echo "=== Running Multi-Server Examples ===\n\n";

        self::runBasicMultiServerDispatch();
        self::runParallelVsSequential();
        self::runFailureHandling();
        self::runTimeoutHandling();
        self::runMultiServerEvents();
        self::runComplexScenarios();
        self::runMonitoringAndAlerting();
        self::runResultAnalysis();

        echo "=== All Multi-Server Examples Completed ===\n";
    }
}
