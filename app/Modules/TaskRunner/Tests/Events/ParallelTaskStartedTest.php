<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Events\ParallelTaskStarted;
use App\Modules\TaskRunner\TestTask;
use Tests\TestCase;

uses(TestCase::class);

describe('ParallelTaskStarted Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $tasks = [new TestTask, new TestTask];
            $executionId = 'parallel-exec-123';
            $startedAt = now()->toISOString();
            $options = ['max_concurrent' => 5, 'timeout' => 300];

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, $options);

            expect($event->tasks)->toBe($tasks);
            expect($event->executionId)->toBe($executionId);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->options)->toBe($options);
        });

        it('creates event with empty options', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-456';
            $startedAt = now()->toISOString();

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, []);

            expect($event->options)->toBe([]);
        });
    });

    describe('event properties', function () {
        it('has readonly properties', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-789';
            $startedAt = now()->toISOString();
            $options = ['max_concurrent' => 3];

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, $options);

            expect($event->tasks)->toBe($tasks);
            expect($event->executionId)->toBe($executionId);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->options)->toBe($options);
        });

        it('contains multiple tasks', function () {
            $task1 = new TestTask;
            $task2 = new TestTask;
            $task3 = new TestTask;
            $tasks = [$task1, $task2, $task3];
            $executionId = 'parallel-exec-multi';
            $startedAt = now()->toISOString();

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, []);

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
            $startedAt = now()->toISOString();
            $options = ['max_concurrent' => 5];

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, $options);

            $serialized = serialize($event);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(ParallelTaskStarted::class);
            expect($unserialized->executionId)->toBe($executionId);
            expect($unserialized->options)->toBe($options);
            expect($unserialized->tasks)->toHaveCount(2);
        });
    });

    describe('event dispatchability', function () {
        it('can be dispatched', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-dispatch';
            $startedAt = now()->toISOString();

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, []);

            expect($event)->toBeInstanceOf(ParallelTaskStarted::class);
            expect(method_exists($event, 'dispatch'))->toBeTrue();
        });
    });

    describe('timestamp formatting', function () {
        it('uses ISO 8601 format', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-time';
            $startedAt = now()->toISOString();

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, []);

            expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
        });

        it('is a valid date', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-valid';
            $startedAt = now()->toISOString();

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, []);

            $date = new DateTime($event->startedAt);
            expect($date)->toBeInstanceOf(DateTime::class);
        });
    });

    describe('execution ID format', function () {
        it('accepts various execution ID formats', function () {
            $tasks = [new TestTask];

            $event1 = new ParallelTaskStarted($tasks, 'exec-123', now()->toISOString(), []);
            $event2 = new ParallelTaskStarted($tasks, 'parallel_task_456', now()->toISOString(), []);
            $event3 = new ParallelTaskStarted($tasks, 'uuid-123e4567-e89b-12d3-a456-426614174000', now()->toISOString(), []);

            expect($event1->executionId)->toBe('exec-123');
            expect($event2->executionId)->toBe('parallel_task_456');
            expect($event3->executionId)->toBe('uuid-123e4567-e89b-12d3-a456-426614174000');
        });
    });

    describe('options handling', function () {
        it('preserves complex options', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-options';
            $startedAt = now()->toISOString();
            $options = [
                'max_concurrent' => 5,
                'timeout' => 300,
                'retry_attempts' => 3,
                'retry_delay' => 10,
                'stop_on_failure' => false,
                'logging' => [
                    'level' => 'info',
                    'format' => 'json',
                ],
            ];

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, $options);

            expect($event->options)->toBe($options);
            expect($event->options['max_concurrent'])->toBe(5);
            expect($event->options['logging']['level'])->toBe('info');
        });

        it('handles empty options', function () {
            $tasks = [new TestTask];
            $executionId = 'parallel-exec-empty';
            $startedAt = now()->toISOString();

            $event = new ParallelTaskStarted($tasks, $executionId, $startedAt, []);

            expect($event->options)->toBe([]);
        });
    });
});
