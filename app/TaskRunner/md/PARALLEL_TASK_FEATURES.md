# TaskRunner Parallel Task Features

This document explains the parallel task execution features in TaskRunner, allowing you to run multiple tasks concurrently for improved performance and efficiency.

## Overview

TaskRunner now supports parallel task execution through multiple approaches:
- **ParallelTaskExecutor**: Dedicated class for running tasks in parallel
- **TaskChain with Parallel Mode**: Enhanced task chains that can execute tasks concurrently
- **TaskDispatcher Parallel Methods**: Convenient methods for parallel execution

## ParallelTaskExecutor

The `ParallelTaskExecutor` class provides dedicated parallel task execution with full control over concurrency and execution options.

### Basic Usage

```php
use App\Modules\TaskRunner\ParallelTaskExecutor;

$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(3)
    ->withTimeout(60)
    ->withStreaming(true)
    ->withProgressTracking(true);

// Add tasks
$executor->addCommand('Check Disk Space', 'df -h')
    ->addCommand('Check Memory', 'free -h')
    ->addCommand('Check Load Average', 'uptime')
    ->addCommand('Check Network', 'netstat -tuln')
    ->addCommand('Check Processes', 'ps aux | head -10');

$results = $executor->run();
```

### Configuration Options

```php
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(5)           // Maximum concurrent tasks
    ->withTimeout(120)                 // Timeout in seconds
    ->withStreaming(true)              // Enable real-time streaming
    ->withProgressTracking(true)       // Enable progress tracking
    ->stopOnFailure(false)             // Continue on failure
    ->withMinSuccess(3)                // Require minimum successful tasks
    ->withMaxFailures(2);              // Allow maximum failures
```

### Adding Different Task Types

```php
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(4);

// Command tasks
$executor->addCommand('System Check', 'uname -a && uptime')
    ->addCommand('Network Check', 'ping -c 3 google.com');

// Callback tasks
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

// View tasks
$executor->addView('Status Report', 'emails.status-report', [
    'timestamp' => now(),
    'system' => 'production',
]);

// Anonymous tasks
$executor->addAnonymous(AnonymousTask::command('Custom Task', 'echo "Hello World"'));

$results = $executor->run();
```

## TaskChain with Parallel Execution

TaskChain now supports parallel execution mode, allowing you to run tasks in a chain concurrently.

### Basic Parallel Chain

```php
use App\Modules\TaskRunner\TaskChain;

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
    ->addCommand('Check Running Services', 'systemctl list-units --type=service --state=running | head -10');

$results = $chain->run();
```

### Sequential vs Parallel Comparison

```php
// Sequential execution (default)
$sequentialChain = TaskChain::make()
    ->addCommand('Task 1', 'sleep 2 && echo "Task 1"')
    ->addCommand('Task 2', 'sleep 2 && echo "Task 2"')
    ->addCommand('Task 3', 'sleep 2 && echo "Task 3"');

$sequentialResults = $sequentialChain->run();
// Duration: ~6 seconds

// Parallel execution
$parallelChain = TaskChain::make()
    ->withParallel(true, 3)
    ->addCommand('Task 1', 'sleep 2 && echo "Task 1"')
    ->addCommand('Task 2', 'sleep 2 && echo "Task 2"')
    ->addCommand('Task 3', 'sleep 2 && echo "Task 3"');

$parallelResults = $parallelChain->run();
// Duration: ~2 seconds
```

## TaskDispatcher Parallel Methods

The TaskDispatcher provides convenient methods for parallel execution.

### Running Multiple Tasks in Parallel

```php
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\AnonymousTask;

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
```

### Running Task Chains in Parallel

```php
// Run a single chain in parallel
$chain = TaskChain::make()
    ->addCommand('Step 1', 'echo "Step 1"')
    ->addCommand('Step 2', 'echo "Step 2"')
    ->addCommand('Step 3', 'echo "Step 3"');

$results = TaskRunner::runChainParallel($chain);
```

### Running Multiple Chains in Parallel

```php
// Create multiple chains
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
```

## Concurrency Control

### Setting Maximum Concurrency

```php
// Limit to 2 concurrent tasks
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(2);

// Limit to 10 concurrent tasks
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(10);

// Sequential execution (concurrency = 1)
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(1);
```

### Performance Impact of Concurrency

```php
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
    $executor = ParallelTaskExecutor::make()
        ->withMaxConcurrency($concurrency);

    foreach ($tasks as $name => $command) {
        $executor->addCommand($name, $command);
    }

    $startTime = microtime(true);
    $results = $executor->run();
    $endTime = microtime(true);
    $actualDuration = $endTime - $startTime;

    echo "Concurrency {$concurrency}: " . number_format($actualDuration, 2) . " seconds\n";
}
```

## Failure Handling

### Stop on Failure

```php
// Stop execution when any task fails
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(3)
    ->stopOnFailure(true);

// Continue execution even if some tasks fail
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(3)
    ->stopOnFailure(false);
```

### Minimum Success Requirements

```php
// Require at least 3 successful tasks
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(5)
    ->withMinSuccess(3)
    ->stopOnFailure(false);
```

### Maximum Failure Tolerance

```php
// Allow maximum 2 failures
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(5)
    ->withMaxFailures(2)
    ->stopOnFailure(false);
```

### Failure Analysis

```php
$results = $executor->run();

if ($results['failed_tasks'] > 0) {
    echo "Failed tasks:\n";
    foreach ($results['results'] as $index => $result) {
        if (!$result['success']) {
            $error = $result['error'] ?? "Exit code: {$result['exit_code']}";
            echo "  - {$result['task_name']}: {$error}\n";
        }
    }
}
```

## Timeout Management

### Global Timeout

```php
// Set timeout for entire execution
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(3)
    ->withTimeout(30); // 30 seconds timeout
```

### Individual Task Timeout

```php
// Set timeout for individual tasks
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(3);

$executor->addCommand('Quick Task', 'echo "Quick task"')
    ->addCommand('Slow Task', 'sleep 10 && echo "Slow task"'); // Will timeout if global timeout is 5 seconds
```

### Timeout Handling

```php
$results = $executor->run();

// Check for timed out tasks
foreach ($results['results'] as $index => $result) {
    if (!$result['success'] && str_contains($result['error'] ?? '', 'timeout')) {
        echo "Timed out: {$result['task_name']}\n";
    }
}
```

## Progress Tracking

### Enable Progress Tracking

```php
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(3)
    ->withProgressTracking(true)
    ->withStreaming(true);

// Add tasks with different durations
for ($i = 1; $i <= 6; $i++) {
    $executor->addCommand("Task {$i}", "sleep {$i} && echo 'Task {$i} completed'");
}

$results = $executor->run();
```

### Progress Events

Progress events are dispatched during execution:

```php
// Listen for progress events
Event::listen(TaskChainProgress::class, function (TaskChainProgress $event) {
    echo "Progress: {$event->current}/{$event->total} ({$event->percentage}%) - {$event->message}\n";
});
```

## Resource Monitoring

### Parallel Resource Monitoring

```php
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

// Show aggregated output
echo $executor->getAggregatedOutput();
```

## Event System

### Parallel Execution Events

```php
use App\Modules\TaskRunner\Events\ParallelTaskStarted;
use App\Modules\TaskRunner\Events\ParallelTaskCompleted;
use App\Modules\TaskRunner\Events\ParallelTaskFailed;

// Listen for parallel execution events
Event::listen(ParallelTaskStarted::class, function (ParallelTaskStarted $event) {
    Log::info('Parallel execution started', [
        'execution_id' => $event->executionId,
        'total_tasks' => count($event->tasks),
        'max_concurrency' => $event->options['max_concurrency'] ?? 5,
    ]);
});

Event::listen(ParallelTaskCompleted::class, function (ParallelTaskCompleted $event) {
    Log::info('Parallel execution completed', [
        'execution_id' => $event->executionId,
        'success_rate' => $event->summary['success_rate'],
        'duration' => $event->summary['duration'],
    ]);
});

Event::listen(ParallelTaskFailed::class, function (ParallelTaskFailed $event) {
    Log::error('Parallel execution failed', [
        'execution_id' => $event->executionId,
        'error' => $event->summary['error'] ?? 'Unknown error',
        'failed_tasks' => $event->summary['failed_tasks'],
    ]);
});
```

## Streaming and Real-time Updates

### Enable Streaming

```php
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(3)
    ->withStreaming(true)
    ->withProgressTracking(true);

// Add tasks
$executor->addCommand('Task 1', 'echo "Starting task 1" && sleep 2 && echo "Task 1 done"')
    ->addCommand('Task 2', 'echo "Starting task 2" && sleep 1 && echo "Task 2 done"')
    ->addCommand('Task 3', 'echo "Starting task 3" && sleep 3 && echo "Task 3 done"');

$results = $executor->run();
```

### Custom Streaming Logger

```php
class CustomStreamingLogger implements StreamingLoggerInterface
{
    public function stream(string $channel, array $data): void
    {
        if ($channel === 'parallel-execution') {
            echo "[" . $data['timestamp'] . "] {$data['event']}: " . json_encode($data) . "\n";
        }
    }
}

$executor = ParallelTaskExecutor::make()
    ->withStreamingLogger(new CustomStreamingLogger())
    ->withMaxConcurrency(3);
```

## Performance Considerations

### Optimal Concurrency Levels

```php
// For I/O-bound tasks (network, file operations)
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(10); // Higher concurrency

// For CPU-bound tasks
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(2); // Lower concurrency

// For mixed workloads
$executor = ParallelTaskExecutor::make()
    ->withMaxConcurrency(5); // Balanced concurrency
```

### Memory Management

```php
// For large numbers of tasks, process in batches
$allTasks = [/* large array of tasks */];
$batchSize = 10;

for ($i = 0; $i < count($allTasks); $i += $batchSize) {
    $batch = array_slice($allTasks, $i, $batchSize);
    
    $executor = ParallelTaskExecutor::make()
        ->withMaxConcurrency(5);
    
    foreach ($batch as $task) {
        $executor->add($task);
    }
    
    $results = $executor->run();
    // Process batch results
}
```

### Error Handling Best Practices

```php
try {
    $executor = ParallelTaskExecutor::make()
        ->withMaxConcurrency(3)
        ->withTimeout(60)
        ->withMinSuccess(2)
        ->stopOnFailure(false);

    // Add tasks
    $executor->addCommand('Task 1', 'command1')
        ->addCommand('Task 2', 'command2')
        ->addCommand('Task 3', 'command3');

    $results = $executor->run();

    if ($results['overall_success']) {
        echo "All critical tasks completed successfully\n";
    } else {
        echo "Some tasks failed, but minimum success requirement met\n";
    }

} catch (ParallelTaskException $e) {
    echo "Parallel execution failed: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Unexpected error: " . $e->getMessage() . "\n";
}
```

## Integration with Existing Features

### Parallel Execution with Connection Management

```php
// Run parallel tasks on multiple servers
$connections = ['server1', 'server2', 'server3', 'server4'];

$tasks = [];
foreach ($connections as $connection) {
    $tasks[] = AnonymousTask::command(
        "Check {$connection}",
        'uptime && df -h && free -h'
    )->onConnection($connection);
}

$results = TaskRunner::runParallel($tasks, [
    'max_concurrency' => 4,
    'timeout' => 60,
]);
```

### Parallel Execution with Task Chains

```php
// Create multiple chains for different environments
$environments = ['staging', 'production', 'testing'];

$chains = [];
foreach ($environments as $env) {
    $chains[] = TaskChain::make()
        ->withParallel(true, 3)
        ->addCommand("Deploy to {$env}", "deploy.sh {$env}")
        ->addCommand("Test {$env}", "test.sh {$env}")
        ->addCommand("Monitor {$env}", "monitor.sh {$env}");
}

$results = TaskRunner::runChainsParallel($chains, [
    'max_concurrency' => 2,
    'timeout' => 300,
]);
```

This parallel task execution system provides maximum flexibility and performance while maintaining reliability and error handling capabilities. 