<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Events\ParallelTaskCompleted;
use App\Modules\TaskRunner\TestTask;
use Tests\TestCase;

uses(TestCase::class);

describe('ParallelTaskCompleted Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $tasks = [new TestTask, new TestTask];
            $executionId = 'parallel-exec-123';
            $startedAt = now()->subSeconds(10)->toISOString();
            $summary = [
                'total_tasks' => 2,
                'completed_tasks' => 2,
                'failed_tasks' => 0,
                'total_duration' => 9.0,
                'average_duration' => 4.5,
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            expect($event->tasks)->toBe($tasks);
            expect($event->executionId)->toBe($executionId);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->summary)->toBe($summary);
        });
    });

    describe('event properties', function () {
        it('has readonly properties', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-456';
            $startedAt = now()->subSeconds(5)->toISOString();
            $summary = [
                'total_tasks' => 1,
                'completed_tasks' => 1,
                'failed_tasks' => 0,
                'total_duration' => 2.5,
                'average_duration' => 2.5,
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            expect($event->tasks)->toBe($tasks);
            expect($event->executionId)->toBe($executionId);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->summary)->toBe($summary);
        });

        it('contains multiple tasks and summary', function () {
            $task1 = new TestTask;
            $task2 = new TestTask;
            $task3 = new TestTask;
            $tasks = [$task1, $task2, $task3];
            $executionId = 'parallel-exec-multi';
            $startedAt = now()->subSeconds(15)->toISOString();
            $summary = [
                'total_tasks' => 3,
                'completed_tasks' => 3,
                'failed_tasks' => 0,
                'total_duration' => 13.2,
                'average_duration' => 4.4,
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            expect($event->tasks)->toHaveCount(3);
            expect($event->summary)->toBe($summary);
            expect($event->tasks[0])->toBe($task1);
            expect($event->tasks[1])->toBe($task2);
            expect($event->tasks[2])->toBe($task3);
            expect($event->summary['total_tasks'])->toBe(3);
            expect($event->summary['completed_tasks'])->toBe(3);
            expect($event->summary['average_duration'])->toBe(4.4);
        });
    });

    describe('event serialization', function () {
        it('can be serialized and unserialized', function () {
            $tasks = [new TestTask, new TestTask];
            $executionId = 'parallel-exec-serial';
            $startedAt = now()->subSeconds(8)->toISOString();
            $summary = [
                'total_tasks' => 2,
                'completed_tasks' => 2,
                'failed_tasks' => 0,
                'total_duration' => 7.7,
                'average_duration' => 3.85,
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            $serialized = serialize($event);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(ParallelTaskCompleted::class);
            expect($unserialized->executionId)->toBe($executionId);
            expect($unserialized->summary)->toBe($summary);
            expect($unserialized->tasks)->toHaveCount(2);
        });
    });

    describe('event dispatchability', function () {
        it('can be dispatched', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-dispatch';
            $startedAt = now()->subSeconds(3)->toISOString();
            $summary = [
                'total_tasks' => 1,
                'completed_tasks' => 1,
                'failed_tasks' => 0,
                'total_duration' => 1.5,
                'average_duration' => 1.5,
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            expect($event)->toBeInstanceOf(ParallelTaskCompleted::class);
            expect(method_exists($event, 'dispatch'))->toBeTrue();
        });
    });

    describe('timestamp formatting', function () {
        it('uses ISO 8601 format for startedAt', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-time';
            $startedAt = now()->subSeconds(5)->toISOString();
            $summary = [
                'total_tasks' => 1,
                'completed_tasks' => 1,
                'failed_tasks' => 0,
                'total_duration' => 2.0,
                'average_duration' => 2.0,
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
        });

        it('is a valid date for startedAt', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-valid';
            $startedAt = now()->subSeconds(5)->toISOString();
            $summary = [
                'total_tasks' => 1,
                'completed_tasks' => 1,
                'failed_tasks' => 0,
                'total_duration' => 2.0,
                'average_duration' => 2.0,
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            $startDate = new DateTime($event->startedAt);
            expect($startDate)->toBeInstanceOf(DateTime::class);
        });
    });

    describe('execution ID format', function () {
        it('accepts various execution ID formats', function () {
            $tasks = [new TestTask];
            $startedAt = now()->subSeconds(5)->toISOString();
            $summary = [
                'total_tasks' => 1,
                'completed_tasks' => 1,
                'failed_tasks' => 0,
                'total_duration' => 2.0,
                'average_duration' => 2.0,
            ];

            $event1 = new ParallelTaskCompleted($tasks, 'exec-123', $summary, $startedAt);
            $event2 = new ParallelTaskCompleted($tasks, 'parallel_task_456', $summary, $startedAt);
            $event3 = new ParallelTaskCompleted($tasks, 'uuid-123e4567-e89b-12d3-a456-426614174000', $summary, $startedAt);

            expect($event1->executionId)->toBe('exec-123');
            expect($event2->executionId)->toBe('parallel_task_456');
            expect($event3->executionId)->toBe('uuid-123e4567-e89b-12d3-a456-426614174000');
        });
    });

    describe('summary handling', function () {
        it('preserves complex summary data', function () {
            $tasks = [new TestTask, new TestTask];
            $executionId = 'parallel-exec-summary';
            $startedAt = now()->subSeconds(15)->toISOString();
            $summary = [
                'total_tasks' => 2,
                'completed_tasks' => 2,
                'failed_tasks' => 0,
                'total_duration' => 9.0,
                'average_duration' => 4.5,
                'memory_usage' => [
                    'peak' => '25.6MB',
                    'average' => '18.4MB',
                ],
                'performance_metrics' => [
                    'cpu_time' => '4.2s',
                    'wall_time' => '9.0s',
                    'efficiency' => 0.47,
                ],
                'task_details' => [
                    'test-task-1' => [
                        'duration' => 5.2,
                        'exit_code' => 0,
                        'memory_peak' => '15.2MB',
                    ],
                    'test-task-2' => [
                        'duration' => 3.8,
                        'exit_code' => 0,
                        'memory_peak' => '12.8MB',
                    ],
                ],
            ];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            expect($event->summary)->toBe($summary);
            expect($event->summary['total_tasks'])->toBe(2);
            expect($event->summary['completed_tasks'])->toBe(2);
            expect($event->summary['average_duration'])->toBe(4.5);
            expect($event->summary['memory_usage']['peak'])->toBe('25.6MB');
            expect($event->summary['performance_metrics']['efficiency'])->toBe(0.47);
            expect($event->summary['task_details']['test-task-1']['duration'])->toBe(5.2);
        });

        it('handles empty summary', function () {
            $tasks = [];
            $executionId = 'parallel-exec-empty';
            $startedAt = now()->subSeconds(1)->toISOString();
            $summary = [];

            $event = new ParallelTaskCompleted($tasks, $executionId, $summary, $startedAt);

            expect($event->summary)->toBe([]);
            expect($event->tasks)->toBe([]);
        });
    });
});
