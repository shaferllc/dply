<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\Events\TaskProgress;
use App\Modules\TaskRunner\Events\TaskStarted;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Listeners\TaskEventListener;
use App\Modules\TaskRunner\TaskChain;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Example demonstrating task event usage and listener registration.
 */
class EventExample
{
    /**
     * Register event listeners.
     */
    public static function registerListeners(): void
    {
        // Register the main task event listener
        Event::listen(TaskStarted::class, [TaskEventListener::class, 'handleTaskStarted']);
        Event::listen(TaskCompleted::class, [TaskEventListener::class, 'handleTaskCompleted']);
        Event::listen(TaskFailed::class, [TaskEventListener::class, 'handleTaskFailed']);
        Event::listen(TaskProgress::class, [TaskEventListener::class, 'handleTaskProgress']);

        // Register custom listeners for specific events
        Event::listen(TaskCompleted::class, [self::class, 'handleCustomTaskCompleted']);
        Event::listen(TaskFailed::class, [self::class, 'handleCustomTaskFailed']);
    }

    /**
     * Example of using events with anonymous tasks.
     */
    public static function runAnonymousTaskWithEvents(): void
    {
        echo "=== Running Anonymous Task with Events ===\n";

        // Create a task with progress tracking
        $task = AnonymousTask::withProgress('Event Test Task', [
            'Step 1: Initialize' => 'echo "Initializing..." && sleep 1',
            'Step 2: Process data' => 'echo "Processing data..." && sleep 1',
            'Step 3: Validate' => 'echo "Validating..." && sleep 1',
            'Step 4: Cleanup' => 'echo "Cleaning up..." && sleep 1',
        ]);

        // Initialize progress tracking (this will trigger progress events)
        $task->initializeProgress(4, [
            'Step 1: Initialize',
            'Step 2: Process data',
            'Step 3: Validate',
            'Step 4: Cleanup',
        ]);

        // Run the task
        $result = TaskRunner::runAnonymous($task);

        echo 'Task completed with exit code: '.$result->getExitCode()."\n";
    }

    /**
     * Example of listening to events manually.
     */
    public static function listenToEventsManually(): void
    {
        echo "\n=== Listening to Events Manually ===\n";

        // Listen to specific events
        Event::listen(TaskStarted::class, function (TaskStarted $event) {
            echo "🎯 Task Started: {$event->getTaskName()}\n";
            echo "   Class: {$event->getTaskClass()}\n";
            echo "   Action: {$event->getTaskAction()}\n";
            echo "   ID: {$event->getTaskId()}\n";
            echo "   Started: {$event->startedAt}\n\n";
        });

        Event::listen(TaskCompleted::class, function (TaskCompleted $event) {
            echo "✅ Task Completed: {$event->getTaskName()}\n";
            echo '   Success: '.($event->wasSuccessful() ? 'Yes' : 'No')."\n";
            echo "   Exit Code: {$event->getExitCode()}\n";
            echo "   Duration: {$event->getDurationForHumans()}\n";
            echo '   Output Size: '.strlen($event->getOutput())." bytes\n\n";
        });

        Event::listen(TaskFailed::class, function (TaskFailed $event) {
            echo "❌ Task Failed: {$event->getTaskName()}\n";
            echo "   Reason: {$event->getReason()}\n";
            echo "   Exception: {$event->getExceptionClass()}\n";
            echo "   Duration: {$event->getDurationForHumans()}\n\n";
        });

        Event::listen(TaskProgress::class, function (TaskProgress $event) {
            echo "📊 Progress: {$event->getTaskName()} - {$event->getPercentageInt()}%\n";
            echo "   Step: {$event->getCurrentStep()}/{$event->getTotalSteps()}\n";
            echo "   Current: {$event->getStepName()}\n";
            echo "   Bar: {$event->getProgressBar(10)}\n\n";
        });

        // Run a task to trigger events
        $result = TaskRunner::runAnonymous(
            AnonymousTask::withProgress('Manual Event Test', [
                'Step 1' => 'echo "Step 1 completed"',
                'Step 2' => 'echo "Step 2 completed"',
                'Step 3' => 'echo "Step 3 completed"',
            ])
        );

        echo "Manual event test completed.\n";
    }

    /**
     * Example of using events for monitoring and alerting.
     */
    public static function monitoringAndAlerting(): void
    {
        echo "\n=== Monitoring and Alerting Example ===\n";

        // Set up monitoring listeners
        Event::listen(TaskCompleted::class, function (TaskCompleted $event) {
            // Monitor for slow tasks
            if ($event->getDuration() > 30) {
                Log::warning('Slow task detected', [
                    'task_name' => $event->getTaskName(),
                    'duration' => $event->getDurationForHumans(),
                    'threshold' => '30 seconds',
                ]);
            }

            // Monitor for large output
            if (strlen($event->getOutput()) > 10000) {
                Log::warning('Large task output detected', [
                    'task_name' => $event->getTaskName(),
                    'output_size' => strlen($event->getOutput()),
                    'threshold' => '10KB',
                ]);
            }
        });

        Event::listen(TaskFailed::class, function (TaskFailed $event) {
            // Alert on critical failures
            if ($event->wasTimeout()) {
                Log::critical('Task timeout detected', [
                    'task_name' => $event->getTaskName(),
                    'duration' => $event->getDurationForHumans(),
                    'task_id' => $event->getTaskId(),
                ]);
            }

            // Alert on specific exit codes
            if (in_array($event->getExitCode(), [1, 2, 3])) {
                Log::error('Task failed with critical exit code', [
                    'task_name' => $event->getTaskName(),
                    'exit_code' => $event->getExitCode(),
                    'reason' => $event->getReason(),
                ]);
            }
        });

        // Run tasks to test monitoring
        $tasks = [
            AnonymousTask::command('Quick Task', 'echo "Quick task"'),
            AnonymousTask::command('Slow Task', 'sleep 2 && echo "Slow task"'),
            AnonymousTask::command('Large Output Task', 'for i in {1..1000}; do echo "Line $i"; done'),
            AnonymousTask::command('Failing Task', 'exit 1'),
        ];

        foreach ($tasks as $task) {
            try {
                TaskRunner::runAnonymous($task);
            } catch (\Exception $e) {
                // Expected for failing task
            }
        }

        echo "Monitoring and alerting test completed.\n";
    }

    /**
     * Example of using events for analytics and metrics.
     */
    public static function analyticsAndMetrics(): void
    {
        echo "\n=== Analytics and Metrics Example ===\n";

        $metrics = [
            'total_tasks' => 0,
            'successful_tasks' => 0,
            'failed_tasks' => 0,
            'total_duration' => 0,
            'task_types' => [],
        ];

        Event::listen(TaskStarted::class, function (TaskStarted $event) use (&$metrics) {
            $metrics['total_tasks']++;
            $taskClass = $event->getTaskClass();
            $metrics['task_types'][$taskClass] = ($metrics['task_types'][$taskClass] ?? 0) + 1;
        });

        Event::listen(TaskCompleted::class, function (TaskCompleted $event) use (&$metrics) {
            if ($event->wasSuccessful()) {
                $metrics['successful_tasks']++;
            } else {
                $metrics['failed_tasks']++;
            }
            $metrics['total_duration'] += $event->getDuration();
        });

        Event::listen(TaskFailed::class, function (TaskFailed $event) use (&$metrics) {
            $metrics['failed_tasks']++;
            $metrics['total_duration'] += $event->getDuration();
        });

        // Run various tasks to collect metrics
        $tasks = [
            AnonymousTask::command('Analytics Task 1', 'echo "Task 1"'),
            AnonymousTask::command('Analytics Task 2', 'echo "Task 2"'),
            AnonymousTask::command('Analytics Task 3', 'sleep 1 && echo "Task 3"'),
            AnonymousTask::command('Analytics Task 4', 'exit 1'), // Will fail
        ];

        foreach ($tasks as $task) {
            try {
                TaskRunner::runAnonymous($task);
            } catch (\Exception $e) {
                // Expected for failing task
            }
        }

        // Display metrics
        echo "=== Task Analytics ===\n";
        echo "Total Tasks: {$metrics['total_tasks']}\n";
        echo "Successful: {$metrics['successful_tasks']}\n";
        echo "Failed: {$metrics['failed_tasks']}\n";
        echo 'Success Rate: '.round(($metrics['successful_tasks'] / $metrics['total_tasks']) * 100, 2)."%\n";
        echo 'Total Duration: '.round($metrics['total_duration'], 2)."s\n";
        echo 'Average Duration: '.round($metrics['total_duration'] / $metrics['total_tasks'], 2)."s\n";
        echo "Task Types:\n";
        foreach ($metrics['task_types'] as $type => $count) {
            echo "  {$type}: {$count}\n";
        }
    }

    /**
     * Example of custom event handler for task completed.
     */
    public static function handleCustomTaskCompleted(TaskCompleted $event): void
    {
        // Custom logic for completed tasks
        if ($event->wasSuccessful()) {
            Log::info('Custom: Task completed successfully', [
                'task_name' => $event->getTaskName(),
                'duration' => $event->getDurationForHumans(),
            ]);
        } else {
            Log::warning('Custom: Task completed with errors', [
                'task_name' => $event->getTaskName(),
                'exit_code' => $event->getExitCode(),
            ]);
        }
    }

    /**
     * Example of custom event handler for task failed.
     */
    public static function handleCustomTaskFailed(TaskFailed $event): void
    {
        // Custom logic for failed tasks
        Log::error('Custom: Task failed', [
            'task_name' => $event->getTaskName(),
            'reason' => $event->getReason(),
            'exception' => $event->getExceptionClass(),
        ]);

        // Custom retry logic
        if ($event->getExitCode() === 2) {
            Log::info('Custom: Scheduling retry for exit code 2', [
                'task_name' => $event->getTaskName(),
            ]);
        }
    }

    /**
     * Example of using events with task chains.
     */
    public static function eventsWithTaskChains(): void
    {
        echo "\n=== Events with Task Chains ===\n";

        // Track chain progress
        $chainMetrics = [
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'failed_tasks' => 0,
        ];

        Event::listen(TaskStarted::class, function (TaskStarted $event) use (&$chainMetrics) {
            $chainMetrics['total_tasks']++;
            echo "🔗 Chain Task Started: {$event->getTaskName()}\n";
        });

        Event::listen(TaskCompleted::class, function (TaskCompleted $event) use (&$chainMetrics) {
            if ($event->wasSuccessful()) {
                $chainMetrics['completed_tasks']++;
                echo "✅ Chain Task Completed: {$event->getTaskName()}\n";
            } else {
                $chainMetrics['failed_tasks']++;
                echo "❌ Chain Task Failed: {$event->getTaskName()}\n";
            }
        });

        // Create a task chain
        $chain = TaskChain::make()
            ->withStreaming(true)
            ->stopOnFailure(false);

        // Add tasks to chain
        $chain->addMany([
            AnonymousTask::command('Chain Step 1', 'echo "Step 1"'),
            AnonymousTask::command('Chain Step 2', 'echo "Step 2"'),
            AnonymousTask::command('Chain Step 3', 'echo "Step 3"'),
            AnonymousTask::command('Chain Step 4', 'exit 1'), // Will fail
            AnonymousTask::command('Chain Step 5', 'echo "Step 5"'),
        ]);

        // Run the chain
        $results = $chain->run();

        echo "\n=== Chain Results ===\n";
        echo "Total Tasks: {$chainMetrics['total_tasks']}\n";
        echo "Completed: {$chainMetrics['completed_tasks']}\n";
        echo "Failed: {$chainMetrics['failed_tasks']}\n";
        echo 'Chain Success: '.($results['successful'] ? 'Yes' : 'No')."\n";
    }

    /**
     * Run all event examples.
     */
    public static function runAllExamples(): void
    {
        echo "=== Running Task Event Examples ===\n\n";

        // Register listeners
        self::registerListeners();

        // Run examples
        self::runAnonymousTaskWithEvents();
        self::listenToEventsManually();
        self::monitoringAndAlerting();
        self::analyticsAndMetrics();
        self::eventsWithTaskChains();

        echo "\n=== All Event Examples Completed ===\n";
    }
}
