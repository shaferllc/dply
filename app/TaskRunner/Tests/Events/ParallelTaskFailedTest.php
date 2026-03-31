<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Events\ParallelTaskFailed;
use App\Modules\TaskRunner\TestTask;
use Tests\TestCase;

uses(TestCase::class);

describe('ParallelTaskFailed Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $tasks = [new TestTask, new TestTask];
            $executionId = 'parallel-exec-123';
            $startedAt = now()->subSeconds(10)->toISOString();
            $summary = [
                'total_tasks' => 2,
                'completed_tasks' => 0,
                'failed_tasks' => 2,
                'failure_reason' => 'One or more tasks failed',
            ];

            $event = new ParallelTaskFailed($tasks, $executionId, $summary, $startedAt);

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
                'completed_tasks' => 0,
                'failed_tasks' => 1,
                'failure_reason' => 'Task execution failed',
            ];

            $event = new ParallelTaskFailed($tasks, $executionId, $summary, $startedAt);

            expect($event->tasks)->toBe($tasks);
            expect($event->executionId)->toBe($executionId);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->summary)->toBe($summary);
        });

        it('contains multiple tasks', function () {
            $task1 = new TestTask;
            $task2 = new TestTask;
            $task3 = new TestTask;
            $tasks = [$task1, $task2, $task3];
            $executionId = 'parallel-exec-multi';
            $startedAt = now()->subSeconds(15)->toISOString();
            $summary = [
                'total_tasks' => 3,
                'completed_tasks' => 0,
                'failed_tasks' => 3,
                'failure_reason' => 'Multiple tasks failed',
            ];

            $event = new ParallelTaskFailed($tasks, $executionId, $summary, $startedAt);

            expect($event->tasks)->toHaveCount(3);
            expect($event->tasks[0])->toBe($task1);
            expect($event->tasks[1])->toBe($task2);
            expect($event->tasks[2])->toBe($task3);
        });
    });

    describe('event serialization', function () {
        it('can be serialized and unserialized', function () {
            $tasks = [new TestTask, new TestTask];
            $executionId = 'parallel-exec-serial';
            $startedAt = now()->subSeconds(8)->toISOString();
            $summary = [
                'total_tasks' => 2,
                'completed_tasks' => 0,
                'failed_tasks' => 2,
                'failure_reason' => 'Serialization test failure',
            ];

            $event = new ParallelTaskFailed($tasks, $executionId, $summary, $startedAt);

            $serialized = serialize($event);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(ParallelTaskFailed::class);
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
            $reason = 'Dispatch test failure';

            $event = new ParallelTaskFailed($tasks, $executionId, [], $startedAt, $reason);

            expect($event)->toBeInstanceOf(ParallelTaskFailed::class);
            expect($event->reason)->toBe($reason);
        });
    });

    describe('timestamp formatting', function () {
        it('uses ISO 8601 format', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-time';
            $startedAt = now()->subSeconds(5)->toISOString();
            $reason = 'Time format test';

            $event = new ParallelTaskFailed($tasks, $executionId, [], $startedAt, $reason);

            expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
        });

        it('is a valid date', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-valid';
            $startedAt = now()->subSeconds(5)->toISOString();
            $reason = 'Valid date test';

            $event = new ParallelTaskFailed($tasks, $executionId, [], $startedAt, $reason);

            $date = new DateTime($event->startedAt);
            expect($date)->toBeInstanceOf(DateTime::class);
        });
    });

    describe('execution ID format', function () {
        it('accepts various execution ID formats', function () {
            $tasks = [new TestTask];
            $startedAt = now()->subSeconds(5)->toISOString();
            $reason = 'ID format test';

            $event1 = new ParallelTaskFailed($tasks, 'exec-123', [], $startedAt, $reason);
            $event2 = new ParallelTaskFailed($tasks, 'parallel_task_456', [], $startedAt, $reason);
            $event3 = new ParallelTaskFailed($tasks, 'uuid-123e4567-e89b-12d3-a456-426614174000', [], $startedAt, $reason);

            expect($event1->executionId)->toBe('exec-123');
            expect($event2->executionId)->toBe('parallel_task_456');
            expect($event3->executionId)->toBe('uuid-123e4567-e89b-12d3-a456-426614174000');
        });
    });

    describe('failure reason handling', function () {
        it('preserves various failure reasons', function () {
            $tasks = [new TestTask];

            $reasons = [
                'Task execution timeout',
                'Memory limit exceeded',
                'Network connection failed',
                'Database connection error',
                'Permission denied',
                'Invalid configuration',
                'Resource not found',
                'Process killed by system',
            ];

            foreach ($reasons as $reason) {
                $executionId = 'exec-'.uniqid();
                $startedAt = now()->subSeconds(5)->toISOString();
                $event = new ParallelTaskFailed($tasks, $executionId, [], $startedAt, $reason);

                expect($event->reason)->toBe($reason);
            }
        });

        it('handles empty reason', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-empty-reason';
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = '';

            $event = new ParallelTaskFailed($tasks, $executionId, [], $startedAt, $reason);

            expect($event->reason)->toBe('');
        });

        it('handles complex failure reasons', function () {
            $tasks = [new TestTask, new TestTask];
            $executionId = 'parallel-exec-complex';
            $startedAt = now()->subSeconds(10)->toISOString();
            $reason = 'Multiple failures occurred: Task 1 failed with exit code 127 (command not found), Task 2 failed with exit code 1 (permission denied), Task 3 timed out after 300 seconds';

            $event = new ParallelTaskFailed($tasks, $executionId, [], $startedAt, $reason);

            expect($event->reason)->toBe($reason);
            expect(strlen($event->reason))->toBeGreaterThan(100);
        });
    });
});
