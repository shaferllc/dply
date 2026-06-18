<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\TestTask;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskFailed Event', function () {
    describe('event construction', function () {
        it('creates event with required properties', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed due to error');
            $startedAt = now()->subSeconds(5)->toISOString();
            $reason = 'Process exited with non-zero code';
            $context = ['user_id' => 1, 'environment' => 'production'];

            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason, $context);

            expect($event->task)->toBe($task);
            expect($event->pendingTask)->toBe($pendingTask);
            expect($event->output)->toBe($output);
            expect($event->exception)->toBe($exception);
            expect($event->startedAt)->toBe($startedAt);
            expect($event->reason)->toBe($reason);
            expect($event->context)->toBe($context);
            expect($event->failedAt)->toBeString();
            expect($event->failedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
            expect($event->duration)->toBeFloat();
            expect($event->duration)->toBeGreaterThan(0);
        });

        it('creates event with null output and exception', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $startedAt = now()->subSeconds(3)->toISOString();
            $reason = 'Task was cancelled';

            $event = new TaskFailed($task, $pendingTask, null, null, $startedAt, $reason);

            expect($event->output)->toBeNull();
            expect($event->exception)->toBeNull();
            expect($event->context)->toBe([]);
        });

        it('calculates duration correctly', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(10)->toISOString();
            $reason = 'Process failed';

            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->duration)->toBeGreaterThanOrEqual(9.5);
            expect($event->duration)->toBeLessThanOrEqual(10.5);
        });
    });

    describe('task information methods', function () {
        it('returns task name', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskName())->toBe('test-task');
        });

        it('returns task action', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskAction())->toBe('test');
        });

        it('returns task class', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskClass())->toBe(TestTask::class);
        });

        it('returns task data', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskData())->toBeArray();
        });

        it('returns task script', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskScript())->toBeString();
        });

        it('returns task view', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskView())->toBeString();
        });
    });

    describe('output and execution methods', function () {
        it('returns exit code when output is available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getExitCode())->toBe(1);
        });

        it('returns null exit code when output is not available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Task was cancelled';
            $event = new TaskFailed($task, $pendingTask, null, $exception, $startedAt, $reason);

            expect($event->getExitCode())->toBeNull();
        });

        it('returns task output when available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output content', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getOutput())->toBe('error output content');
        });

        it('returns empty output when not available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Task was cancelled';
            $event = new TaskFailed($task, $pendingTask, null, $exception, $startedAt, $reason);

            expect($event->getOutput())->toBe('');
        });

        it('checks if task timed out when output is available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, true);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process timed out';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->timedOut())->toBeTrue();
        });

        it('returns false for timeout when output is not available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Task was cancelled';
            $event = new TaskFailed($task, $pendingTask, null, $exception, $startedAt, $reason);

            expect($event->timedOut())->toBeFalse();
        });
    });

    describe('exception methods', function () {
        it('returns exception message when exception is available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed due to network error');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getExceptionMessage())->toBe('Task failed due to network error');
        });

        it('returns empty exception message when exception is not available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, null, $startedAt, $reason);

            expect($event->getExceptionMessage())->toBe('');
        });

        it('returns exception class when exception is available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getExceptionClass())->toBe(Exception::class);
        });

        it('returns empty exception class when exception is not available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, null, $startedAt, $reason);

            expect($event->getExceptionClass())->toBe('');
        });

        it('returns exception trace when exception is available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getExceptionTrace())->toBeArray();
            expect($event->getExceptionTrace())->not->toBeEmpty();
        });

        it('returns empty exception trace when exception is not available', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, null, $startedAt, $reason);

            expect($event->getExceptionTrace())->toBe([]);
        });
    });

    describe('failure reason and type methods', function () {
        it('returns failure reason', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process exited with non-zero code';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getReason())->toBe('Process exited with non-zero code');
        });

        it('checks if failure was due to timeout', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, true);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process timed out';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->wasTimeout())->toBeTrue();
        });

        it('checks if failure was due to exception', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Exception occurred';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->wasException())->toBeTrue();
        });

        it('checks if failure was due to non-zero exit code', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->wasExitCode())->toBeTrue();
        });

        it('returns false for exit code failure when exit code is 0', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 0, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->wasExitCode())->toBeFalse();
        });
    });

    describe('duration methods', function () {
        it('returns duration in seconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(5)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getDuration())->toBeFloat();
            expect($event->getDuration())->toBeGreaterThan(4.5);
            expect($event->getDuration())->toBeLessThan(5.5);
        });

        it('returns duration for humans in milliseconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subMilliseconds(500)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            $duration = $event->getDurationForHumans();
            expect($duration)->toContain('ms');
            expect($duration)->toMatch('/^\d+\.\d+ms$/');
        });

        it('returns duration for humans in seconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(30)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            $duration = $event->getDurationForHumans();
            expect($duration)->toMatch('/^\d+(\.\d+)?s$/');
        });

        it('returns duration for humans in minutes and seconds', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subMinutes(2)->subSeconds(30)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            $duration = $event->getDurationForHumans();
            expect($duration)->toMatch('/^\d+m \d+(\.\d+)?s$/');
        });
    });

    describe('pending task information methods', function () {
        it('checks if task is running in background', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->isBackground())->toBeFalse();
        });

        it('returns connection name when running remotely', function () {
            config()->set('task-runner.connections.production-server', [
                'host' => '127.0.0.1',
                'username' => 'testuser',
                'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBALeQw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\nAgMBAAECQQC1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw1Qw\n-----END RSA PRIVATE KEY-----",
                'script_path' => '/tmp/fake-script',
                // Add other required config keys if needed
            ]);
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $pendingTask->onConnection('production-server');
            $output = new ProcessOutput('test output', 0, false);
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $exception = new Exception('Test exception');
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getConnection())->toBe('production-server');
        });

        it('returns null connection when running locally', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getConnection())->toBeNull();
        });

        it('returns task ID', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $pendingTask->id('task-123');
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskId())->toBe('task-123');
        });

        it('returns null task ID when not set', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->getTaskId())->toBeNull();
        });
    });

    describe('failure details', function () {
        it('returns failure details', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output content', 1, false);
            $exception = new Exception('Task failed due to error');
            $startedAt = now()->subSeconds(5)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            $details = $event->getFailureDetails();

            expect($details)->toBeArray();
            expect($details)->toHaveKeys([
                'reason', 'exception_class', 'exception_message', 'exit_code',
                'timed_out', 'duration', 'duration_human', 'started_at',
                'failed_at', 'output_size',
            ]);
            expect($details['reason'])->toBe('Process failed');
            expect($details['exception_class'])->toBe(Exception::class);
            expect($details['exception_message'])->toBe('Task failed due to error');
            expect($details['exit_code'])->toBe(1);
            expect($details['timed_out'])->toBeFalse();
            expect($details['duration'])->toBeFloat();
            expect($details['duration_human'])->toBeString();
            expect($details['started_at'])->toBe($startedAt);
            expect($details['failed_at'])->toBe($event->failedAt);
            expect($details['output_size'])->toBe(20); // 'error output content' length
        });
    });

    describe('event serialization', function () {
        it('can be serialized and unserialized', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $context = ['test' => 'data'];
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason, $context);

            $pendingTask->onOutput = null;
            $serialized = serialize($event);
            $unserialized = unserialize($serialized);

            expect($unserialized)->toBeInstanceOf(TaskFailed::class);
            expect($unserialized->getTaskName())->toBe('test-task');
            expect($unserialized->context)->toBe($context);
            expect($unserialized->getReason())->toBe('Process failed');
        });

        it('can serialize a minimal TaskFailed event', function () {
            $event = new TaskFailed(
                new TestTask,
                new PendingTask(new TestTask),
                new ProcessOutput('output', 1, false),
                null,
                now()->toISOString(),
                'Minimal reason',
                []
            );
            $serialized = serialize($event);
            $unserialized = unserialize($serialized);
            expect($unserialized)->toBeInstanceOf(TaskFailed::class);
            expect($unserialized->getReason())->toBe('Minimal reason');
        });
    })->skip();

    describe('event dispatchability', function () {
        it('can be dispatched', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event)->toBeInstanceOf(TaskFailed::class);
            expect(method_exists($event, 'dispatch'))->toBeTrue();
        });
    });

    describe('context data handling', function () {
        it('preserves complex context data', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
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

            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason, $context);

            expect($event->context)->toBe($context);
            expect($event->context['user']['name'])->toBe('John');
            expect($event->context['tags'])->toContain('deployment');
            expect($event->context['metadata']['deployment_id'])->toBe('deploy-123');
        });

        it('handles empty context', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason, []);

            expect($event->context)->toBe([]);
        });
    });

    describe('timestamp formatting', function () {
        it('uses ISO 8601 format for failedAt', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            expect($event->failedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
        });

        it('is a valid date', function () {
            $task = new TestTask;
            $pendingTask = new PendingTask($task);
            $output = new ProcessOutput('error output', 1, false);
            $exception = new Exception('Task failed');
            $startedAt = now()->subSeconds(1)->toISOString();
            $reason = 'Process failed';
            $event = new TaskFailed($task, $pendingTask, $output, $exception, $startedAt, $reason);

            $date = new DateTime($event->failedAt);
            expect($date)->toBeInstanceOf(DateTime::class);
        });
    });
});
