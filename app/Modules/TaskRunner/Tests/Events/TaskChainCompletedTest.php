<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Events;

use App\Modules\TaskRunner\Events\TaskChainCompleted;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskChainCompleted Event', function () {
    beforeEach(function () {
        Event::fake();
    });

    it('creates event with required properties', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $chainId = 'chain-123';
        $summary = [
            'total_tasks' => 3,
            'completed_tasks' => 3,
            'successful_tasks' => 3,
            'failed_tasks' => 0,
            'success_rate' => 100.0,
            'duration' => 45.5,
        ];
        $startedAt = now()->toISOString();

        $event = new TaskChainCompleted($tasks, $chainId, $summary, $startedAt);

        expect($event->tasks)->toBe($tasks);
        expect($event->chainId)->toBe($chainId);
        expect($event->summary)->toBe($summary);
        expect($event->startedAt)->toBe($startedAt);
    });

    it('gets total tasks from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['total_tasks' => 3];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getTotalTasks())->toBe(3);
    });

    it('gets total tasks when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getTotalTasks())->toBe(0);
    });

    it('gets completed tasks from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['completed_tasks' => 3];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getCompletedTasks())->toBe(3);
    });

    it('gets completed tasks when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getCompletedTasks())->toBe(0);
    });

    it('gets successful tasks from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['successful_tasks' => 2];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getSuccessfulTasks())->toBe(2);
    });

    it('gets successful tasks when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getSuccessfulTasks())->toBe(0);
    });

    it('gets failed tasks from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['failed_tasks' => 1];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getFailedTasks())->toBe(1);
    });

    it('gets failed tasks when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getFailedTasks())->toBe(0);
    });

    it('gets success rate from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['success_rate' => 85.5];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getSuccessRate())->toBe(85.5);
    });

    it('gets success rate when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getSuccessRate())->toBe(0.0);
    });

    it('gets duration from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['duration' => 120.5];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getDuration())->toBe(120.5);
    });

    it('gets duration when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getDuration())->toBe(0.0);
    });

    it('gets duration for humans', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['duration' => 3661]; // 1 hour, 1 minute, 1 second
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        $duration = $event->getDurationForHumans();
        expect($duration)->toContain('61m');
        expect($duration)->toContain('1s');
    });

    it('gets duration for humans with zero duration', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['duration' => 0];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getDurationForHumans())->toBe('0ms');
    });

    it('gets completed at timestamp', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $startedAt = now()->toISOString();
        $summary = ['duration' => 60];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, $startedAt);

        $completedAt = $event->getCompletedAt();
        expect($completedAt)->toBe('');
    });

    it('gets results from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $results = ['task1' => 'success', 'task2' => 'success', 'task3' => 'success'];
        $summary = ['results' => $results];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getResults())->toBe($results);
    });

    it('gets results when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getResults())->toBe([]);
    });

    it('checks if chain was successful', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['successful_tasks' => 3, 'failed_tasks' => 0, 'overall_success' => true];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->wasSuccessful())->toBeTrue();
    });

    it('checks if chain was not successful', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['successful_tasks' => 2, 'failed_tasks' => 1];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->wasSuccessful())->toBeFalse();
    });

    it('gets performance metrics from summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $metrics = [
            'average_execution_time' => 15.5,
            'peak_memory_usage' => 512 * 1024 * 1024,
            'total_cpu_time' => 45.2,
        ];
        $summary = ['performance_metrics' => $metrics];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getPerformanceMetrics())->toHaveKeys([
            'total_tasks', 'completed_tasks', 'successful_tasks', 'failed_tasks',
            'success_rate', 'duration', 'duration_human', 'started_at', 'completed_at',
        ]);
    });

    it('gets performance metrics when not in summary', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getPerformanceMetrics())->toHaveKeys([
            'total_tasks', 'completed_tasks', 'successful_tasks', 'failed_tasks',
            'success_rate', 'duration', 'duration_human', 'started_at', 'completed_at',
        ]);
    });

    it('can be serialized and unserialized', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $chainId = 'chain-123';
        $summary = [
            'total_tasks' => 3,
            'completed_tasks' => 3,
            'successful_tasks' => 3,
            'failed_tasks' => 0,
            'success_rate' => 100.0,
            'duration' => 45.5,
        ];
        $startedAt = now()->toISOString();

        $event = new TaskChainCompleted($tasks, $chainId, $summary, $startedAt);

        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        expect($unserialized)->toBeInstanceOf(TaskChainCompleted::class);
        expect($unserialized->tasks)->toHaveCount(count($tasks));
        expect($unserialized->chainId)->toBe($chainId);
        expect($unserialized->summary)->toBe($summary);
        expect($unserialized->startedAt)->toBe($startedAt);
    });

    it('can be dispatched', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = ['total_tasks' => 3, 'completed_tasks' => 3];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        Event::dispatch($event);

        Event::assertDispatched(TaskChainCompleted::class, function ($dispatchedEvent) use ($event) {
            return $dispatchedEvent->chainId === $event->chainId &&
                   $dispatchedEvent->summary === $event->summary &&
                   $dispatchedEvent->startedAt === $event->startedAt;
        });
    });

    it('handles edge case with empty tasks array', function () {
        $tasks = [];
        $summary = ['total_tasks' => 0, 'completed_tasks' => 0];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getTotalTasks())->toBe(0);
        expect($event->getCompletedTasks())->toBe(0);
        expect($event->getSuccessfulTasks())->toBe(0);
        expect($event->getFailedTasks())->toBe(0);
        expect($event->getSuccessRate())->toBe(0.0);
        expect($event->getDuration())->toBe(0.0);
        expect($event->wasSuccessful())->toBeFalse();
    });

    it('handles edge case with partial success', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [
            'total_tasks' => 3,
            'completed_tasks' => 3,
            'successful_tasks' => 2,
            'failed_tasks' => 1,
            'success_rate' => 66.67,
            'duration' => 30.5,
        ];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getSuccessfulTasks())->toBe(2);
        expect($event->getFailedTasks())->toBe(1);
        expect($event->getSuccessRate())->toBe(66.67);
        expect($event->wasSuccessful())->toBeFalse();
    });

    it('handles edge case with very large numbers', function () {
        $tasks = array_fill(0, 1000, new TestTask);
        $summary = [
            'total_tasks' => PHP_INT_MAX,
            'completed_tasks' => PHP_INT_MAX,
            'successful_tasks' => PHP_INT_MAX - 100,
            'failed_tasks' => 100,
            'success_rate' => 99.99,
            'duration' => 3600.0,
        ];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getTotalTasks())->toBe(PHP_INT_MAX);
        expect($event->getCompletedTasks())->toBe(PHP_INT_MAX);
        expect($event->getSuccessfulTasks())->toBe(PHP_INT_MAX - 100);
        expect($event->getFailedTasks())->toBe(100);
        expect($event->getSuccessRate())->toBe(99.99);
        expect($event->getDuration())->toBe(3600.0);
    });

    it('handles floating point precision in success rate', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $summary = [
            'total_tasks' => 3,
            'completed_tasks' => 3,
            'successful_tasks' => 2,
            'failed_tasks' => 1,
            'success_rate' => 66.666666,
            'duration' => 15.123456,
        ];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getSuccessRate())->toBe(66.666666);
        expect($event->getDuration())->toBe(15.123456);
    });

    it('validates timestamp format', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $startedAt = now()->toISOString();
        $summary = ['total_tasks' => 3, 'completed_tasks' => 3];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, $startedAt);

        expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    });

    it('handles complex results structure', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $results = [
            'task1' => [
                'status' => 'success',
                'execution_time' => 10.5,
                'memory_usage' => 1024 * 1024,
                'output' => 'Task 1 completed successfully',
            ],
            'task2' => [
                'status' => 'success',
                'execution_time' => 15.2,
                'memory_usage' => 2048 * 1024,
                'output' => 'Task 2 completed successfully',
            ],
            'task3' => [
                'status' => 'success',
                'execution_time' => 8.7,
                'memory_usage' => 512 * 1024,
                'output' => 'Task 3 completed successfully',
            ],
        ];
        $summary = ['results' => $results];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getResults())->toBe($results);
        expect($event->getResults())->toHaveCount(3);
        expect($event->getResults()['task1']['status'])->toBe('success');
        expect($event->getResults()['task2']['execution_time'])->toBe(15.2);
        expect($event->getResults()['task3']['memory_usage'])->toBe(512 * 1024);
    });

    it('handles performance metrics with various data types', function () {
        $tasks = [new TestTask, new TestTask, new TestTask];
        $metrics = [
            'average_execution_time' => 15.5,
            'peak_memory_usage' => 512 * 1024 * 1024,
            'total_cpu_time' => 45.2,
            'disk_io_read' => 1024 * 1024 * 100,
            'disk_io_write' => 1024 * 1024 * 50,
            'network_bytes_sent' => 1024 * 1024 * 10,
            'network_bytes_received' => 1024 * 1024 * 20,
            'error_count' => 0,
            'warning_count' => 2,
            'efficiency_score' => 0.95,
        ];
        $summary = ['performance_metrics' => $metrics];
        $event = new TaskChainCompleted($tasks, 'chain-123', $summary, now()->toISOString());

        expect($event->getPerformanceMetrics())->toHaveKeys([
            'total_tasks', 'completed_tasks', 'successful_tasks', 'failed_tasks',
            'success_rate', 'duration', 'duration_human', 'started_at', 'completed_at',
        ]);
        expect($event->getPerformanceMetrics())->toHaveCount(9);
        expect($event->getPerformanceMetrics()['total_tasks'])->toBe(0);
        expect($event->getPerformanceMetrics()['completed_tasks'])->toBe(0);
        expect($event->getPerformanceMetrics()['successful_tasks'])->toBe(0);
    });
});
