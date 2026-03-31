<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\ParallelTaskExecutor;
use App\Modules\TaskRunner\TaskChain;

/**
 * Examples demonstrating parallel task execution features.
 */
class ParallelTaskExamples
{
    /**
     * Example using ParallelTaskExecutor directly.
     */
    public function parallelTaskExecutorExample(): void
    {
        echo "=== Parallel Task Executor Example ===\n";

        $executor = ParallelTaskExecutor::make()
            ->withMaxConcurrency(3)
            ->withTimeout(60)
            ->withStreaming(true)
            ->withProgressTracking(true);

        // Add various types of tasks
        $executor->addCommand('Check Disk Space', 'df -h')
            ->addCommand('Check Memory', 'free -h')
            ->addCommand('Check Load Average', 'uptime')
            ->addCommand('Check Network', 'netstat -tuln')
            ->addCommand('Check Processes', 'ps aux | head -10')
            ->addCommand('Check System Info', 'uname -a');

        $results = $executor->run();

        echo "Execution ID: {$results['execution_id']}\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']} seconds\n";
        echo 'Overall success: '.($results['overall_success'] ? 'YES' : 'NO')."\n";
    }

    /**
     * Example using TaskChain with parallel execution.
     */
    public function parallelTaskChainExample(): void
    {
        echo "=== Parallel Task Chain Example ===\n";

        $chain = TaskChain::make()
            ->withParallel(true, 4) // Enable parallel with max 4 concurrent tasks
            ->withTimeout(120)
            ->withStreaming(true)
            ->stopOnFailure(false) // Continue even if some tasks fail
            ->addCommand('Update Package List', 'apt update')
            ->addCommand('Check Disk Usage', 'df -h')
            ->addCommand('Check Memory Usage', 'free -h')
            ->addCommand('Check System Load', 'uptime')
            ->addCommand('Check Network Status', 'netstat -tuln')
            ->addCommand('Check Running Services', 'systemctl list-units --type=service --state=running | head -10')
            ->addCommand('Check Log Files', 'tail -n 5 /var/log/syslog')
            ->addCommand('Check SSL Certificates', 'find /etc/ssl/certs -name "*.pem" -exec openssl x509 -checkend 86400 -noout -in {} \;');

        $results = $chain->run();

        echo "Chain ID: {$results['chain_id']}\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Completed: {$results['completed_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']} seconds\n";
        echo 'Overall success: '.($results['overall_success'] ? 'YES' : 'NO')."\n";
    }

    /**
     * Example using TaskDispatcher for parallel execution.
     */
    public function taskDispatcherParallelExample(): void
    {
        echo "=== Task Dispatcher Parallel Example ===\n";

        // Create multiple tasks
        $tasks = [
            AnonymousTask::command('System Info', 'uname -a && cat /etc/os-release'),
            AnonymousTask::command('Network Info', 'ip addr show && route -n'),
            AnonymousTask::command('Process Info', 'ps aux --sort=-%cpu | head -10'),
            AnonymousTask::command('Disk Info', 'lsblk && df -h'),
            AnonymousTask::command('Memory Info', 'cat /proc/meminfo | head -10'),
            AnonymousTask::command('Load Info', 'cat /proc/loadavg && uptime'),
        ];

        $options = [
            'max_concurrency' => 3,
            'timeout' => 60,
            'streaming' => true,
            'progress_tracking' => true,
            'stop_on_failure' => false,
        ];

        $results = TaskRunner::runParallel($tasks, $options);

        echo "Execution ID: {$results['execution_id']}\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Success rate: {$results['success_rate']}%\n";
        echo "Duration: {$results['duration']} seconds\n";
    }

    /**
     * Example using parallel execution with mixed task types.
     */
    public function mixedParallelTasksExample(): void
    {
        echo "=== Mixed Parallel Tasks Example ===\n";

        $executor = ParallelTaskExecutor::make()
            ->withMaxConcurrency(5)
            ->withTimeout(90);

        // Add command tasks
        $executor->addCommand('System Check', 'uname -a && uptime')
            ->addCommand('Network Check', 'ping -c 3 google.com')
            ->addCommand('Disk Check', 'df -h && iostat -x 1 1');

        // Add callback tasks
        $executor->addCallback('Database Check', function () {
            // Simulate database check
            sleep(2);

            return ['status' => 'healthy', 'connections' => 5];
        })
            ->addCallback('Cache Check', function () {
                // Simulate cache check
                sleep(1);

                return ['status' => 'operational', 'hit_rate' => 0.95];
            });

        // Add view tasks
        $executor->addView('Status Report', 'emails.status-report', [
            'timestamp' => now(),
            'system' => 'production',
        ]);

        $results = $executor->run();

        echo "Mixed parallel execution completed\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Success rate: {$results['success_rate']}%\n";

        // Show some results
        foreach ($results['results'] as $index => $result) {
            $status = $result['success'] ? 'SUCCESS' : 'FAILED';
            echo "  Task {$index}: {$result['task_name']} - {$status}\n";
        }
    }

    /**
     * Example using parallel execution with failure handling.
     */
    public function parallelFailureHandlingExample(): void
    {
        echo "=== Parallel Failure Handling Example ===\n";

        $executor = ParallelTaskExecutor::make()
            ->withMaxConcurrency(3)
            ->withMinSuccess(2) // Require at least 2 successful tasks
            ->withMaxFailures(2) // Allow maximum 2 failures
            ->stopOnFailure(false);

        // Add tasks (some will fail)
        $executor->addCommand('Valid Command', 'echo "Hello World"')
            ->addCommand('Invalid Command', 'nonexistent-command')
            ->addCommand('Another Valid', 'date')
            ->addCommand('Another Invalid', 'invalid-command-123')
            ->addCommand('Final Valid', 'whoami');

        $results = $executor->run();

        echo "Failure handling test completed\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo 'Overall success: '.($results['overall_success'] ? 'YES' : 'NO')."\n";

        // Show failed tasks
        if ($results['failed_tasks'] > 0) {
            echo "Failed tasks:\n";
            foreach ($results['results'] as $index => $result) {
                if (! $result['success']) {
                    $error = $result['error'] ?? "Exit code: {$result['exit_code']}";
                    echo "  - {$result['task_name']}: {$error}\n";
                }
            }
        }
    }

    /**
     * Example using parallel execution with different concurrency levels.
     */
    public function concurrencyLevelsExample(): void
    {
        echo "=== Concurrency Levels Example ===\n";

        $tasks = [
            'Task 1' => 'sleep 2 && echo "Task 1 completed"',
            'Task 2' => 'sleep 3 && echo "Task 2 completed"',
            'Task 3' => 'sleep 1 && echo "Task 3 completed"',
            'Task 4' => 'sleep 2 && echo "Task 4 completed"',
            'Task 5' => 'sleep 1 && echo "Task 5 completed"',
            'Task 6' => 'sleep 3 && echo "Task 6 completed"',
        ];

        $concurrencyLevels = [1, 2, 3, 6];

        foreach ($concurrencyLevels as $concurrency) {
            echo "\nTesting with concurrency level: {$concurrency}\n";

            $startTime = microtime(true);

            $executor = ParallelTaskExecutor::make()
                ->withMaxConcurrency($concurrency);

            foreach ($tasks as $name => $command) {
                $executor->addCommand($name, $command);
            }

            $results = $executor->run();
            $endTime = microtime(true);
            $actualDuration = $endTime - $startTime;

            echo "  Expected duration: ~3 seconds (longest task)\n";
            echo '  Actual duration: '.number_format($actualDuration, 2)." seconds\n";
            echo "  Reported duration: {$results['duration']} seconds\n";
            echo "  Success rate: {$results['success_rate']}%\n";
        }
    }

    /**
     * Example using parallel execution with timeout handling.
     */
    public function parallelTimeoutExample(): void
    {
        echo "=== Parallel Timeout Example ===\n";

        $executor = ParallelTaskExecutor::make()
            ->withMaxConcurrency(3)
            ->withTimeout(5) // 5 second timeout
            ->stopOnFailure(false);

        // Add tasks with different durations
        $executor->addCommand('Quick Task', 'echo "Quick task completed"')
            ->addCommand('Slow Task', 'sleep 10 && echo "Slow task completed"')
            ->addCommand('Medium Task', 'sleep 3 && echo "Medium task completed"')
            ->addCommand('Another Quick', 'echo "Another quick task"')
            ->addCommand('Another Slow', 'sleep 8 && echo "Another slow task"');

        $results = $executor->run();

        echo "Timeout test completed\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Duration: {$results['duration']} seconds\n";

        // Show which tasks timed out
        foreach ($results['results'] as $index => $result) {
            if (! $result['success'] && str_contains($result['error'] ?? '', 'timeout')) {
                echo "  Timed out: {$result['task_name']}\n";
            }
        }
    }

    /**
     * Example using parallel execution with progress tracking.
     */
    public function parallelProgressTrackingExample(): void
    {
        echo "=== Parallel Progress Tracking Example ===\n";

        $executor = ParallelTaskExecutor::make()
            ->withMaxConcurrency(2)
            ->withProgressTracking(true)
            ->withStreaming(true);

        // Add tasks with different durations to see progress
        for ($i = 1; $i <= 6; $i++) {
            $executor->addCommand("Task {$i}", "sleep {$i} && echo 'Task {$i} completed'");
        }

        echo "Starting parallel execution with progress tracking...\n";
        echo "Tasks will complete at different times to show progress updates.\n\n";

        $results = $executor->run();

        echo "\nExecution completed!\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Duration: {$results['duration']} seconds\n";
    }

    /**
     * Example using parallel execution with multiple task chains.
     */
    public function parallelTaskChainsExample(): void
    {
        echo "=== Parallel Task Chains Example ===\n";

        // Create multiple task chains
        $chains = [
            TaskChain::make()
                ->addCommand('Chain 1 - Step 1', 'echo "Chain 1 started"')
                ->addCommand('Chain 1 - Step 2', 'sleep 2 && echo "Chain 1 step 2"')
                ->addCommand('Chain 1 - Step 3', 'echo "Chain 1 completed"'),

            TaskChain::make()
                ->addCommand('Chain 2 - Step 1', 'echo "Chain 2 started"')
                ->addCommand('Chain 2 - Step 2', 'sleep 1 && echo "Chain 2 step 2"')
                ->addCommand('Chain 2 - Step 3', 'echo "Chain 2 completed"'),

            TaskChain::make()
                ->addCommand('Chain 3 - Step 1', 'echo "Chain 3 started"')
                ->addCommand('Chain 3 - Step 2', 'sleep 3 && echo "Chain 3 step 2"')
                ->addCommand('Chain 3 - Step 3', 'echo "Chain 3 completed"'),
        ];

        $options = [
            'max_concurrency' => 2,
            'timeout' => 30,
            'streaming' => true,
        ];

        $results = TaskRunner::runChainsParallel($chains, $options);

        echo "Parallel chains execution completed\n";
        echo 'Total chains: '.count($chains)."\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Duration: {$results['duration']} seconds\n";
    }

    /**
     * Example using parallel execution with resource monitoring.
     */
    public function parallelResourceMonitoringExample(): void
    {
        echo "=== Parallel Resource Monitoring Example ===\n";

        $executor = ParallelTaskExecutor::make()
            ->withMaxConcurrency(4)
            ->withTimeout(60);

        // Add resource monitoring tasks
        $executor->addCommand('CPU Usage', 'top -bn1 | grep "Cpu(s)"')
            ->addCommand('Memory Usage', 'free -h && cat /proc/meminfo | grep -E "MemTotal|MemAvailable"')
            ->addCommand('Disk I/O', 'iostat -x 1 1')
            ->addCommand('Network I/O', 'cat /proc/net/dev | grep -E "eth0|wlan0"')
            ->addCommand('Load Average', 'cat /proc/loadavg')
            ->addCommand('Process Count', 'ps aux | wc -l')
            ->addCommand('Open Files', 'lsof | wc -l')
            ->addCommand('Network Connections', 'netstat -an | wc -l');

        $results = $executor->run();

        echo "Resource monitoring completed\n";
        echo "Total tasks: {$results['total_tasks']}\n";
        echo "Successful: {$results['successful_tasks']}\n";
        echo "Failed: {$results['failed_tasks']}\n";
        echo "Duration: {$results['duration']} seconds\n";

        // Show aggregated output
        echo "\nAggregated Resource Information:\n";
        echo $executor->getAggregatedOutput();
    }

    /**
     * Run all parallel task examples.
     */
    public function runAllExamples(): void
    {
        echo "=== Parallel Task Examples ===\n\n";

        try {
            $this->parallelTaskExecutorExample();
            echo "\n";

            $this->parallelTaskChainExample();
            echo "\n";

            $this->taskDispatcherParallelExample();
            echo "\n";

            $this->mixedParallelTasksExample();
            echo "\n";

            $this->parallelFailureHandlingExample();
            echo "\n";

            $this->concurrencyLevelsExample();
            echo "\n";

            $this->parallelTimeoutExample();
            echo "\n";

            $this->parallelProgressTrackingExample();
            echo "\n";

            $this->parallelTaskChainsExample();
            echo "\n";

            $this->parallelResourceMonitoringExample();
            echo "\n";

        } catch (\Exception $e) {
            echo 'Example failed: '.$e->getMessage()."\n";
        }

        echo "=== All Parallel Task Examples Completed ===\n";
    }
}
