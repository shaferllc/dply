<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Events\TaskChainStarted;
use App\Modules\TaskRunner\TestTask;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskChainStarted Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $tasks = [new TestTask, new TestTask, new TestTask];
            $chainId = 'chain-123';
            $startedAt = now()->toISOString();
            $options = ['streaming' => true, 'progress_tracking' => true];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->tasks)->toBe($tasks);
            expect($event->chainId)->toBe($chainId);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->options)->toBe($options);
        });

        it('creates event with empty options', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-456';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->options)->toBe([]);
        });
    });

    describe('task counting methods', function () {
        it('returns correct task count', function () {
            $tasks = [new TestTask, new TestTask, new TestTask, new TestTask];
            $chainId = 'chain-count';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->getTaskCount())->toBe(4);
        });

        it('returns zero for empty task array', function () {
            $tasks = [];
            $chainId = 'chain-empty';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->getTaskCount())->toBe(0);
        });
    });

    describe('task information methods', function () {
        it('returns task names', function () {
            $task1 = new TestTask;
            $task2 = new TestTask;
            $tasks = [$task1, $task2];
            $chainId = 'chain-names';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            $taskNames = $event->getTaskNames();
            expect($taskNames)->toBeArray();
            expect($taskNames)->toHaveCount(2);
            expect($taskNames[0])->toBe('test-task');
            expect($taskNames[1])->toBe('test-task');
        });

        it('returns task classes', function () {
            $task1 = new TestTask;
            $task2 = new TestTask;
            $tasks = [$task1, $task2];
            $chainId = 'chain-classes';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            $taskClasses = $event->getTaskClasses();
            expect($taskClasses)->toBeArray();
            expect($taskClasses)->toHaveCount(2);
            expect($taskClasses[0])->toBe(TestTask::class);
            expect($taskClasses[1])->toBe(TestTask::class);
        });

        it('returns empty arrays for empty task list', function () {
            $tasks = [];
            $chainId = 'chain-empty-info';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->getTaskNames())->toBe([]);
            expect($event->getTaskClasses())->toBe([]);
        });
    });

    describe('option checking methods', function () {
        it('checks if streaming is enabled', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-streaming';
            $startedAt = now()->toISOString();
            $options = ['streaming' => true];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->isStreamingEnabled())->toBeTrue();
        });

        it('checks if streaming is disabled', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-no-streaming';
            $startedAt = now()->toISOString();
            $options = ['streaming' => false];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->isStreamingEnabled())->toBeFalse();
        });

        it('defaults streaming to true when not specified', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-default-streaming';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->isStreamingEnabled())->toBeTrue();
        });

        it('checks if progress tracking is enabled', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-progress';
            $startedAt = now()->toISOString();
            $options = ['progress_tracking' => true];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->isProgressTrackingEnabled())->toBeTrue();
        });

        it('checks if progress tracking is disabled', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-no-progress';
            $startedAt = now()->toISOString();
            $options = ['progress_tracking' => false];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->isProgressTrackingEnabled())->toBeFalse();
        });

        it('defaults progress tracking to true when not specified', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-default-progress';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->isProgressTrackingEnabled())->toBeTrue();
        });

        it('checks if chain stops on failure', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-stop-failure';
            $startedAt = now()->toISOString();
            $options = ['stop_on_failure' => true];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->stopsOnFailure())->toBeTrue();
        });

        it('checks if chain continues on failure', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-continue-failure';
            $startedAt = now()->toISOString();
            $options = ['stop_on_failure' => false];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->stopsOnFailure())->toBeFalse();
        });

        it('defaults stop on failure to true when not specified', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-default-stop';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->stopsOnFailure())->toBeTrue();
        });
    });

    describe('timeout handling', function () {
        it('returns timeout value when specified', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-timeout';
            $startedAt = now()->toISOString();
            $options = ['timeout' => 300];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->getTimeout())->toBe(300);
        });

        it('returns null when timeout is not specified', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-no-timeout';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->getTimeout())->toBeNull();
        });

        it('returns null when timeout is explicitly set to null', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-null-timeout';
            $startedAt = now()->toISOString();
            $options = ['timeout' => null];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->getTimeout())->toBeNull();
        });
    });

    describe('event serialization', function () {
        it('can be serialized and unserialized', function () {
            $tasks = [new TestTask, new TestTask];
            $chainId = 'chain-serial';
            $startedAt = now()->toISOString();
            $options = ['streaming' => true, 'timeout' => 300];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            $serialized = serialize($event);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(TaskChainStarted::class);
            expect($unserialized->chainId)->toBe($chainId);
            expect($unserialized->options)->toBe($options);
            expect($unserialized->getTaskCount())->toBe(2);
        });
    });

    describe('event dispatchability', function () {
        it('can be dispatched', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-dispatch';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event)->toBeInstanceOf(TaskChainStarted::class);
            expect(method_exists($event, 'dispatch'))->toBeTrue();
        });
    });

    describe('timestamp formatting', function () {
        it('uses ISO 8601 format', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-time';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            expect($event->startedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
        });

        it('is a valid date', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-valid';
            $startedAt = now()->toISOString();

            $event = new TaskChainStarted($tasks, $chainId, $startedAt);

            $date = new DateTime($event->startedAt);
            expect($date)->toBeInstanceOf(DateTime::class);
        });
    });

    describe('chain ID format', function () {
        it('accepts various chain ID formats', function () {
            $tasks = [new TestTask];

            $event1 = new TaskChainStarted($tasks, 'chain-123', now()->toISOString());
            $event2 = new TaskChainStarted($tasks, 'deployment_chain_456', now()->toISOString());
            $event3 = new TaskChainStarted($tasks, 'uuid-123e4567-e89b-12d3-a456-426614174000', now()->toISOString());

            expect($event1->chainId)->toBe('chain-123');
            expect($event2->chainId)->toBe('deployment_chain_456');
            expect($event3->chainId)->toBe('uuid-123e4567-e89b-12d3-a456-426614174000');
        });
    });

    describe('complex options handling', function () {
        it('preserves complex options', function () {
            $tasks = [new TestTask];
            $chainId = 'chain-complex';
            $startedAt = now()->toISOString();
            $options = [
                'streaming' => true,
                'progress_tracking' => true,
                'stop_on_failure' => false,
                'timeout' => 600,
                'retry_config' => [
                    'max_attempts' => 3,
                    'delay' => 5,
                    'backoff_multiplier' => 2,
                ],
                'logging' => [
                    'level' => 'debug',
                    'format' => 'json',
                    'include_timestamps' => true,
                ],
                'notifications' => [
                    'email' => ['admin@example.com'],
                    'slack' => ['#deployments'],
                ],
            ];

            $event = new TaskChainStarted($tasks, $chainId, $startedAt, $options);

            expect($event->options)->toBe($options);
            expect($event->isStreamingEnabled())->toBeTrue();
            expect($event->isProgressTrackingEnabled())->toBeTrue();
            expect($event->stopsOnFailure())->toBeFalse();
            expect($event->getTimeout())->toBe(600);
            expect($event->options['retry_config']['max_attempts'])->toBe(3);
            expect($event->options['logging']['level'])->toBe('debug');
        });
    });
});
