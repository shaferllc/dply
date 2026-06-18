<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\TestTask;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskCompleted Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(5)->toISOString();
            $context = ['user_id' => 1, 'environment' => 'production'];

            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt, $context);

            expect($event->task)->toBe($task);
            expect($event->pendingTask)->toBe($pendingTask);
            expect($event->output)->toBe($output);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->context)->toBe($context);
            expect($event->completedAt)->toBeString();
            expect($event->completedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
            expect($event->duration)->toBeFloat();
            expect($event->duration)->toBeGreaterThan(0);
        });

        it('creates event with empty context when not provided', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(3)->toISOString();

            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->context)->toBe([]);
        });

        it('calculates duration correctly', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(10)->toISOString();

            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->duration)->toBeGreaterThanOrEqual(9.5);
            expect($event->duration)->toBeLessThanOrEqual(10.5);
        });
    });

    describe('task information methods', function () {
        it('returns task name', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskName())->toBe('test-task');
        });

        it('returns task action', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskAction())->toBe('test');
        });

        it('returns task class', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskClass())->toBe(TestTask::class);
        });

        it('returns task data', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskData())->toBeArray();
        });

        it('returns task script', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskScript())->toBeString();
        });

        it('returns task view', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskView())->toBeString();
        });
    });

    describe('output and execution methods', function () {
        it('returns exit code', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getExitCode())->toBe(0);
        });

        it('checks if task was successful', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->wasSuccessful())->toBeTrue();
        });

        it('returns task output', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output content', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getOutput())->toBe('test output content');
        });

        it('returns empty error output', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getErrorOutput())->toBe('');
        });

        it('checks if task timed out', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, true);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->timedOut())->toBeTrue();
        });
    });

    describe('duration methods', function () {
        it('returns duration in seconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(5)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getDuration())->toBeFloat();
            expect($event->getDuration())->toBeGreaterThan(4.5);
            expect($event->getDuration())->toBeLessThan(5.5);
        });

        it('returns duration for humans in milliseconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subMilliseconds(500)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            $duration = $event->getDurationForHumans();
            expect($duration)->toContain('ms');
            expect($duration)->toMatch('/^\d+\.\d+ms$/');
        });

        it('returns duration for humans in seconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(30)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            $duration = $event->getDurationForHumans();
            expect($duration)->toContain('s');
            expect($duration)->toMatch('/^\d+s$/');
        });

        it('returns duration for humans in minutes and seconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subMinutes(2)->subSeconds(30)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            $duration = $event->getDurationForHumans();
            expect($duration)->toMatch('/^\d+m \d+s$/');
        });
    });

    describe('pending task information methods', function () {
        it('checks if task is running in background', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->isBackground())->toBeFalse();
        });

        it('returns connection name when running remotely', function () {
            config()->set('task-runner.connections.production-server', [
                'host' => '127.0.0.1',
                'username' => 'testuser',
                'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBALeQw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\nAgMBAAECQQC1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n-----END RSA PRIVATE KEY-----",
                'script_path' => '/tmp/fake-script',
                // Add other required config keys if needed by Connection::fromArray()
            ]);
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $pendingTask->onConnection('production-server');
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getConnection())->toBe('production-server');
        });

        it('returns null connection when running locally', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getConnection())->toBeNull();
        });

        it('returns task ID', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $pendingTask->id('task-123');
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskId())->toBe('task-123');
        });

        it('returns null task ID when not set', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->getTaskId())->toBeNull();
        });
    });

    describe('performance metrics', function () {
        it('returns performance metrics', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output content', 0, false);
            $startedAt = now()->subSeconds(5)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            $metrics = $event->getPerformanceMetrics();

            expect($metrics)->toBeArray();
            expect($metrics)->toHaveKeys(['duration', 'duration_human', 'started_at', 'completed_at', 'output_size', 'error_size']);
            expect($metrics['duration'])->toBeFloat();
            expect($metrics['duration_human'])->toBeString();
            expect($metrics['started_at'])->toBe($startedAt);
            expect($metrics['completed_at'])->toBe($event->completedAt);
            expect($metrics['output_size'])->toBe(19); // 'test output content' length
            expect($metrics['error_size'])->toBe(0);
        });
    });

    describe('event serialization', function () {
        it('can be serialized and unserialized', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $context = ['test' => 'data'];
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt, $context);

            $serialized = serialize($event);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(TaskCompleted::class);
            expect($unserialized->getTaskName())->toBe('test-task');
            expect($unserialized->context)->toBe($context);
            expect($unserialized->getExitCode())->toBe(0);
        });
    });

    describe('event dispatchability', function () {
        it('can be dispatched', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event)->toBeInstanceOf(TaskCompleted::class);
            expect(method_exists($event, 'dispatch'))->toBeTrue();
        });
    });

    describe('context data handling', function () {
        it('preserves complex context data', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $context = [
                'user' => ['id' => 1, 'name' => 'John'],
                'environment' => 'production',
                'tags' => ['deployment', 'backend'],
                'metadata' => [
                    'deployment_id' => 'deploy-123',
                    'commit_hash' => 'abc123',
                    'branch' => 'main',
                ],
            ];

            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt, $context);

            expect($event->context)->toBe($context);
            expect($event->context['user']['name'])->toBe('John');
            expect($event->context['tags'])->toContain('deployment');
            expect($event->context['metadata']['deployment_id'])->toBe('deploy-123');
        });

        it('handles empty context', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt, []);

            expect($event->context)->toBe([]);
        });
    });

    describe('timestamp formatting', function () {
        it('uses ISO 8601 format for completedAt', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            expect($event->completedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
        });

        it('is a valid date', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $event = new TaskCompleted($task, $pendingTask, $output, $startedAt);

            $date = new DateTime($event->completedAt);
            expect($date)->toBeInstanceOf(DateTime::class);
        });
    });
});
