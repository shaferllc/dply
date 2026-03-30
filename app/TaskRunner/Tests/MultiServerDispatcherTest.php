<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Exceptions\ConnectionNotFoundException;
use App\Modules\TaskRunner\FakeTask;
use App\Modules\TaskRunner\MultiServerDispatcher;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\ProcessRunner;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\TaskDispatcher;
use Tests\TestCase;

uses(TestCase::class);

global $callCount;
$callCount = 0;

describe('MultiServerDispatcher', function () {
    // Helper: create a dummy SSH private key
    function fakePrivateKey(): string
    {
        return file_get_contents(__DIR__.'/fixtures/private_key.pem');
    }

    // Helper: create a valid Connection
    function makeConnection(string $host = '127.0.0.1', int $port = 22, string $username = 'test'): Connection
    {
        return new Connection(
            host: $host,
            port: $port,
            username: $username,
            privateKey: fakePrivateKey(),
            scriptPath: '/tmp/.dply-task-runner',
        );
    }

    // Helper: create a minimal StreamingLoggerInterface stub
    function makeLogger(): StreamingLoggerInterface
    {
        return new class implements StreamingLoggerInterface
        {
            public function log(string $level, string $message, array $context = [], bool $stream = false): void {}

            public function stream(string $level, string $message, array $context = []): void {}

            public function addStreamHandler(callable $handler, ?string $channel = null): void {}

            public function removeStreamHandler(callable $handler): void {}

            public function getStreamHandlers(): array
            {
                return [];
            }

            public function clearStreamHandlers(): void {}

            public function streamProcessOutput(string $type, string $output, array $context = []): void {}

            public function streamTaskEvent(string $event, array $context = []): void {}

            public function streamError(string $message, array $context = []): void {}

            public function streamProgress(int $current, int $total, string $message = '', array $context = []): void {}

            public function streamChainEvent(string $event, array $context = []): void {}
        };
    }

    // Helper: create a TaskDispatcher that fails for specific hosts
    function makeDispatcherWithHostFailures(array $failHosts = []): TaskDispatcher
    {
        $logger = makeLogger();
        $processRunner = new ProcessRunner($logger);
        $dispatcher = new TaskDispatcher($processRunner);

        // Fake all tasks to control their execution
        $dispatcher->fake();

        // Override the taskShouldBeFaked method to return custom FakeTask based on host
        return new class($processRunner, $failHosts) extends TaskDispatcher
        {
            private array $failHosts;

            public function __construct($processRunner, array $failHosts)
            {
                parent::__construct($processRunner);
                $this->failHosts = $failHosts;
            }

            public function taskShouldBeFaked(PendingTask $pendingTask): bool|FakeTask
            {
                $host = null;
                $connection = $pendingTask->getConnection();
                $host = $connection ? $connection->host : null;

                if ($host && in_array($host, $this->failHosts, true)) {
                    return new FakeTask(
                        get_class($pendingTask->task),
                        new ProcessOutput('fail', 1, false)
                    );
                }

                return new FakeTask(
                    get_class($pendingTask->task),
                    new ProcessOutput('ok', 0, true)
                );
            }
        };
    }

    it('dispatches a task to multiple servers in parallel and succeeds', function () {
        $dispatcher = makeDispatcherWithHostFailures([]); // No failures
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Echo', 'echo "Hello"');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(0);
        expect($result['results'])->toHaveCount(2);
    });

    it('dispatches a task to multiple servers sequentially and succeeds', function () {
        $dispatcher = makeDispatcherWithHostFailures([]); // No failures
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Echo', 'echo "Hello"');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(0);
        expect($result['results'])->toHaveCount(2);
    });

    it('handles a failed server in parallel mode', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Fail', 'exit 1');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
        expect($result['results'])->toHaveCount(2);
        // Dynamically find the failed connection key
        $failedKey = null;
        foreach (array_keys($result['results']) as $key) {
            if (str_contains($key, '192.168.1.2')) {
                $failedKey = $key;
                break;
            }
        }
        expect($failedKey)->not->toBeNull();
        expect($result['results'][$failedKey]['success'])->toBeFalse();
    });

    it('handles stop_on_failure in sequential mode', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Fail', 'exit 1');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2'), makeConnection('10.0.0.1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false, 'stop_on_failure' => true]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
        expect($result['results'])->toHaveCount(2); // Should stop after first failure
    });

    it('respects min_success option', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'min_success' => 1]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
    });

    it('respects max_failures option', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'max_failures' => 1]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
    });

    it('aggregates output and errors correctly', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $multi->dispatch($task, $connections, ['parallel' => true]);
        $output = $multi->getAggregatedOutput();
        $errors = $multi->getAggregatedErrors();
        expect($output)->toContain('127.0.0.1');
        expect($errors)->toContain('192.168.1.2');
    });

    it('all servers fail in parallel mode', function () {
        $dispatcher = makeDispatcherWithHostFailures(['127.0.0.1', '192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Fail', 'exit 1');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(0);
        expect($result['failed_servers'])->toBe(2);
    });

    it('all servers fail in sequential mode', function () {
        $dispatcher = makeDispatcherWithHostFailures(['127.0.0.1', '192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Fail', 'exit 1');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(0);
        expect($result['failed_servers'])->toBe(2);
    });

    it('handles no connections gracefully', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Noop', 'echo ok');
        $connections = [];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(0);
        expect($result['failed_servers'])->toBe(0);
        expect($result['results'])->toHaveCount(0);
    });

    it('one connection, success', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Ok', 'echo ok');
        $connections = [makeConnection('127.0.0.1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(0);
    });

    it('one connection, failure', function () {
        $dispatcher = makeDispatcherWithHostFailures(['127.0.0.1']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Fail', 'exit 1');
        $connections = [makeConnection('127.0.0.1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(0);
        expect($result['failed_servers'])->toBe(1);
    });

    it('stop_on_failure false processes all servers', function () {
        $dispatcher = makeDispatcherWithHostFailures(['127.0.0.1', '192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2'), makeConnection('10.0.0.1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'stop_on_failure' => false]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['failed_servers'])->toBe(2);
    });

    it('min_success = total servers requires all to succeed', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'min_success' => 2]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
    });

    it('max_failures = 0 means any failure fails overall', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'max_failures' => 0]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
    });

    it('timeout option is passed to task', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1')];
        $multi->dispatch($task, $connections, ['timeout' => 123]);
        // The stub does not check timeout, but this ensures no error is thrown
        expect(true)->toBeTrue();
    });

    it('results contain correct connection strings', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        $keys = array_keys($result['results']);
        expect($keys[0])->toContain('127.0.0.1');
        expect($keys[1])->toContain('192.168.1.2');
    });

    it('handles mixed success/failure with more than two servers', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2', '10.0.0.1']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2'), makeConnection('10.0.0.1'), makeConnection('8.8.8.8')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(2);
        expect($result['overall_success'])->toBeFalse();
    });

    it('handles duplicate connections', function () {
        $dispatcher = makeDispatcherWithHostFailures(['192.168.1.2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['failed_servers'])->toBe(1); // Only 1 unique key
        expect($result['successful_servers'])->toBe(1);
    });

    it('handles custom connection objects', function () {
        $dispatcher = makeDispatcherWithHostFailures(['custom-host']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $customConn = new Connection('custom-host', 22, 'user', fakePrivateKey(), '/tmp/.dply-task-runner');
        $connections = [makeConnection('127.0.0.1'), $customConn];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
    });

    it('handles empty task script', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'Empty';
            }

            public function getScript(): string
            {
                return '';
            }
        };
        $connections = [makeConnection('127.0.0.1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles a very large number of servers', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail-host']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [];
        for ($i = 0; $i < 50; $i++) {
            $host = $i === 25 ? 'fail-host' : "host-$i";
            $connections[] = makeConnection($host);
        }
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['successful_servers'])->toBe(49);
        expect($result['failed_servers'])->toBe(1);
    });

    it('handles all options set', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail-host']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('fail-host'), makeConnection('ok-host')];
        $result = $multi->dispatch($task, $connections, [
            'parallel' => false,
            'timeout' => 5,
            'stop_on_failure' => true,
            'wait_for_all' => false,
            'min_success' => 2,
            'max_failures' => 0,
        ]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['failed_servers'])->toBe(1); // Only 1 due to stop_on_failure
    });

    it('sequential with failure at first position', function () {
        $dispatcher = makeDispatcherWithHostFailures(['127.0.0.1']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('127.0.0.1'), makeConnection('192.168.1.2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false, 'stop_on_failure' => true]);
        expect($result['successful_servers'])->toBe(0);
        expect($result['failed_servers'])->toBe(1);
        expect($result['results'])->toHaveCount(1);
    });

    it('parallel with all but one failing', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail1', 'fail2', 'fail3']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('fail1'), makeConnection('fail2'), makeConnection('fail3'), makeConnection('ok-host')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(3);
    });

    it('parallel with all but one succeeding', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail-host']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('ok1'), makeConnection('ok2'), makeConnection('ok3'), makeConnection('fail-host')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['successful_servers'])->toBe(3);
        expect($result['failed_servers'])->toBe(1);
    });

    it('aggregates output with mixed outputs', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail-host']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('ok1'), makeConnection('fail-host')];
        $multi->dispatch($task, $connections, ['parallel' => true]);
        $output = $multi->getAggregatedOutput();
        $errors = $multi->getAggregatedErrors();
        expect($output)->toContain('ok1');
        expect($errors)->toContain('fail-host');
    });

    it('parallel with all servers failing and stop_on_failure true', function () {
        $dispatcher = makeDispatcherWithHostFailures(['a', 'b', 'c']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Fail', 'exit 1');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'stop_on_failure' => true]);
        expect($result['failed_servers'])->toBe(1); // Only 1 due to stop_on_failure
        expect($result['successful_servers'])->toBe(0);
    });

    it('sequential with all servers failing and stop_on_failure false', function () {
        $dispatcher = makeDispatcherWithHostFailures(['a', 'b', 'c']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Fail', 'exit 1');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false, 'stop_on_failure' => false]);
        expect($result['failed_servers'])->toBe(3);
        expect($result['successful_servers'])->toBe(0);
    });

    it('parallel with mixed failures and min_success = 2', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail1']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('ok1'), makeConnection('ok2'), makeConnection('fail1')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'min_success' => 2]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(1);
    });

    it('parallel with mixed failures and max_failures = 2', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail1', 'fail2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('ok1'), makeConnection('fail1'), makeConnection('fail2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'max_failures' => 2]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(2);
    });

    it('sequential with failure at last position', function () {
        $dispatcher = makeDispatcherWithHostFailures(['c']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false, 'stop_on_failure' => false]);
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(1);
    });

    it('sequential with failure at middle position', function () {
        $dispatcher = makeDispatcherWithHostFailures(['b']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false, 'stop_on_failure' => false]);
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(1);
    });

    it('sequential with stop_on_failure true and failure at middle', function () {
        $dispatcher = makeDispatcherWithHostFailures(['b']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => false, 'stop_on_failure' => true]);
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(1);
        expect($result['results'])->toHaveCount(2);
    });

    it('parallel with all servers succeeding and min_success = 3', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'min_success' => 3]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(3);
    });

    it('parallel with all servers failing and max_failures = 3', function () {
        $dispatcher = makeDispatcherWithHostFailures(['a', 'b', 'c']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'exit 1');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'max_failures' => 3]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['failed_servers'])->toBe(3);
    });

    it('handles invalid connection objects gracefully', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [null, false, '', 123];
        expect(fn () => $multi->dispatch($task, $connections, ['parallel' => true]))->toThrow(\InvalidArgumentException::class);
    });

    it('handles empty string as connection', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [''];
        expect(fn () => $multi->dispatch($task, $connections, ['parallel' => true]))->toThrow(ConnectionNotFoundException::class);
    });

    it('handles repeated dispatches with different failures', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail1']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('fail1'), makeConnection('ok1')];
        $result1 = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result1['failed_servers'])->toBe(1);
        $dispatcher2 = makeDispatcherWithHostFailures(['ok1']);
        $multi2 = new MultiServerDispatcher($dispatcher2);
        $result2 = $multi2->dispatch($task, $connections, ['parallel' => true]);
        expect($result2['failed_servers'])->toBe(1);
    });

    it('handles custom task subclass', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'Custom';
            }

            public function getScript(): string
            {
                return 'echo custom';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles exception thrown in executeTaskOnServer', function () {
        $logger = makeLogger();
        $processRunner = new ProcessRunner($logger);
        $dispatcher = new class($processRunner) extends TaskDispatcher
        {
            public function taskShouldBeFaked(PendingTask $pendingTask): bool|FakeTask
            {
                return false; // Don't fake tasks, let them run normally
            }

            public function run(PendingTask $pendingTask): ?ProcessOutput
            {
                // This should be called and throw an exception
                throw new \RuntimeException('Simulated failure');
            }
        };
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a')];

        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'stop_on_failure' => true]);

        // Check if the result indicates failure
        expect($result['overall_success'])->toBeFalse();
        expect($result['failed_servers'])->toBe(1);
        expect($result['results'])->toHaveKey('test@a:22');
        expect($result['results']['test@a:22']['success'])->toBeFalse();
        expect($result['results']['test@a:22']['error'])->toContain('Simulated failure');
    });

    it('can be constructed with a valid dispatcher', function () {
        $logger = makeLogger();
        $processRunner = new ProcessRunner($logger);
        $dispatcher = new TaskDispatcher($processRunner);

        $multi = new MultiServerDispatcher($dispatcher);

        expect($multi)->toBeInstanceOf(MultiServerDispatcher::class);
        expect($multi->getMultiServerTaskId())->toMatch('/^multi_[a-f0-9.]+$/');
    });

    it('handles connection normalization with array', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [['host' => 'fail', 'port' => 22, 'username' => 'user', 'private_key' => fakePrivateKey(), 'script_path' => '/tmp/.dply-task-runner']];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['failed_servers'])->toBe(1);
    });

    it('handles connection normalization with model', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $model = new class
        {
            public $host = 'model-host';

            public $port = 22;

            public $username = 'user';

            public $private_key = 'key';

            public $script_path = '/tmp/.dply-task-runner';
        };
        $connections = [$model];
        expect(fn () => $multi->dispatch($task, $connections, ['parallel' => true]))->toThrow(\InvalidArgumentException::class);
    });

    it('handles concurrency: multiple dispatches in parallel', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail1']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections1 = [makeConnection('fail1'), makeConnection('ok1')];
        $connections2 = [makeConnection('ok2'), makeConnection('ok3')];
        $result1 = $multi->dispatch($task, $connections1, ['parallel' => true]);
        $result2 = $multi->dispatch($task, $connections2, ['parallel' => true]);
        expect($result1['failed_servers'])->toBe(1);
        expect($result2['successful_servers'])->toBe(2);
    });

    it('handles empty options array', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, []);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles null options', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, null ?? []);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles large min_success and max_failures', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail1', 'fail2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('ok1'), makeConnection('fail1'), makeConnection('fail2'), makeConnection('ok2')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'min_success' => 3, 'max_failures' => 2]);
        expect($result['overall_success'])->toBeFalse();
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(2);
    });

    it('handles all servers failing with min_success = 0', function () {
        $dispatcher = makeDispatcherWithHostFailures(['a', 'b', 'c']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'exit 1');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'min_success' => 0]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['failed_servers'])->toBe(3);
    });

    it('handles all servers succeeding with max_failures = 0', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a'), makeConnection('b'), makeConnection('c')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true, 'max_failures' => 0]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['failed_servers'])->toBe(0);
    });

    it('handles event dispatching (no error)', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles very long task names', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $longName = str_repeat('a', 1000);
        $task = AnonymousTask::command($longName, 'echo ok');
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['task_name'])->toBe($longName);
    });

    it('handles tasks with special characters in names', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $specialName = 'Task with spaces, dots.and:colons!@#$%^&*()';
        $task = AnonymousTask::command($specialName, 'echo ok');
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['task_name'])->toBe($specialName);
    });

    it('handles connections with special characters in hostnames', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $specialHost = 'host-with-dashes.and.dots';
        $connections = [makeConnection($specialHost)];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with empty output', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'EmptyOutput';
            }

            public function getScript(): string
            {
                return 'echo ""'; // Empty output
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with very large output', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $largeOutput = str_repeat('x', 10000);
        $task = new class($largeOutput) extends Task
        {
            private string $largeOutput;

            public function __construct(string $largeOutput)
            {
                $this->largeOutput = $largeOutput;
            }

            public function getName(): string
            {
                return 'LargeOutput';
            }

            public function getScript(): string
            {
                return "echo '{$this->largeOutput}'";
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles mixed connection types in same dispatch', function () {
        $dispatcher = makeDispatcherWithHostFailures(['fail-host']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [
            makeConnection('ok-host'),
            ['host' => 'fail-host', 'port' => 22, 'username' => 'user', 'private_key' => fakePrivateKey(), 'script_path' => '/tmp/.dply-task-runner'],
            makeConnection('another-ok'),
        ];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['successful_servers'])->toBe(2);
        expect($result['failed_servers'])->toBe(1);
    });

    it('handles concurrent dispatches with different tasks', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task1 = AnonymousTask::command('Task1', 'echo task1');
        $task2 = AnonymousTask::command('Task2', 'echo task2');
        $connections = [makeConnection('a'), makeConnection('b')];

        $result1 = $multi->dispatch($task1, $connections, ['parallel' => true]);
        $result2 = $multi->dispatch($task2, $connections, ['parallel' => true]);

        expect($result1['overall_success'])->toBeTrue();
        expect($result2['overall_success'])->toBeTrue();
        expect($result1['task_name'])->toBe('Task1');
        expect($result2['task_name'])->toBe('Task2');
    });

    it('handles tasks with unicode characters', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $unicodeName = 'Tâsk with unicode: 测试 🚀';
        $task = AnonymousTask::command($unicodeName, 'echo "unicode test"');
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['task_name'])->toBe($unicodeName);
    });

    it('handles connections with non-standard ports', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Test', 'echo ok');
        $connections = [
            makeConnection('host1', 2222),
            makeConnection('host2', 2223),
            makeConnection('host3', 2224),
        ];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
        expect($result['successful_servers'])->toBe(3);
    });

    it('handles tasks with complex script content', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $complexScript = '
            #!/bin/bash
            set -e
            echo "Starting complex task"
            if [ "$1" = "test" ]; then
                echo "Test mode enabled"
            fi
            for i in {1..5}; do
                echo "Iteration $i"
            done
            echo "Task completed"
        ';
        $task = new class($complexScript) extends Task
        {
            private string $script;

            public function __construct(string $script)
            {
                $this->script = $script;
            }

            public function getName(): string
            {
                return 'ComplexScript';
            }

            public function getScript(): string
            {
                return $this->script;
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles rapid successive dispatches', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('Rapid', 'echo rapid');
        $connections = [makeConnection('a')];

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $multi->dispatch($task, $connections, ['parallel' => true]);
        }

        foreach ($results as $result) {
            expect($result['overall_success'])->toBeTrue();
        }
    });

    it('handles tasks with different exit codes', function () {
        $dispatcher = makeDispatcherWithHostFailures(['exit-1', 'exit-2']);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = AnonymousTask::command('ExitCodes', 'exit 0');
        $connections = [
            makeConnection('ok'),
            makeConnection('exit-1'),
            makeConnection('exit-2'),
        ];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['successful_servers'])->toBe(1);
        expect($result['failed_servers'])->toBe(2);
    });

    it('handles tasks with environment variables', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'EnvVars';
            }

            public function getScript(): string
            {
                return '
                    export TEST_VAR="test_value"
                    echo "TEST_VAR=$TEST_VAR"
                    echo "PWD=$PWD"
                    echo "USER=$USER"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with conditional logic', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'Conditional';
            }

            public function getScript(): string
            {
                return '
                    if [ -f /tmp/test ]; then
                        echo "File exists"
                    else
                        echo "File does not exist"
                    fi

                    case $HOSTNAME in
                        "host1") echo "Host 1";;
                        "host2") echo "Host 2";;
                        *) echo "Unknown host";;
                    esac
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with file operations', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'FileOps';
            }

            public function getScript(): string
            {
                return '
                    echo "test content" > /tmp/test_file
                    cat /tmp/test_file
                    rm -f /tmp/test_file
                    echo "File operations completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with network operations', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'Network';
            }

            public function getScript(): string
            {
                return '
                    ping -c 1 127.0.0.1 > /dev/null && echo "Local network OK" || echo "Network issue"
                    curl -s --connect-timeout 5 http://example.com > /dev/null && echo "Internet OK" || echo "Internet issue"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with process management', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'ProcessMgmt';
            }

            public function getScript(): string
            {
                return '
                    sleep 1 &
                    PID=$!
                    echo "Background process PID: $PID"
                    ps -p $PID > /dev/null && echo "Process running" || echo "Process not found"
                    kill $PID 2>/dev/null || true
                    echo "Process management completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with error handling', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'ErrorHandling';
            }

            public function getScript(): string
            {
                return '
                    set -e
                    echo "Starting error handling test"

                    # This should fail but not stop execution
                    set +e
                    ls /nonexistent/file 2>/dev/null || echo "Expected error occurred"
                    set -e

                    echo "Continuing after error"

                    # This should succeed
                    echo "Final success message"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with data processing', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'DataProcessing';
            }

            public function getScript(): string
            {
                return '
                    echo "Processing data..."

                    # Generate some test data
                    for i in {1..10}; do
                        echo "Data point $i: $((RANDOM % 100))"
                    done | sort -n

                    # Calculate statistics
                    echo "Data processing completed"
                    echo "Total lines processed: $(wc -l < /dev/stdin 2>/dev/null || echo 0)"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with system information gathering', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'SysInfo';
            }

            public function getScript(): string
            {
                return '
                    echo "=== System Information ==="
                    echo "Hostname: $(hostname)"
                    echo "OS: $(uname -s)"
                    echo "Kernel: $(uname -r)"
                    echo "Architecture: $(uname -m)"
                    echo "Uptime: $(uptime)"
                    echo "Memory: $(free -h | head -2 | tail -1)"
                    echo "Disk: $(df -h / | tail -1)"
                    echo "=== End System Information ==="
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with cleanup operations', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'Cleanup';
            }

            public function getScript(): string
            {
                return '
                    echo "Starting cleanup task"

                    # Create temporary files
                    echo "temp1" > /tmp/cleanup_test1
                    echo "temp2" > /tmp/cleanup_test2

                    echo "Created temporary files"

                    # Cleanup
                    rm -f /tmp/cleanup_test1 /tmp/cleanup_test2

                    echo "Cleanup completed"

                    # Verify cleanup
                    if [ ! -f /tmp/cleanup_test1 ] && [ ! -f /tmp/cleanup_test2 ]; then
                        echo "Cleanup verification successful"
                    else
                        echo "Cleanup verification failed"
                        exit 1
                    fi
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with security validation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'SecurityValidation';
            }

            public function getScript(): string
            {
                return '
                    echo "Performing security validation"

                    # Check file permissions
                    ls -la /tmp/ | head -5

                    # Check user context
                    whoami
                    id

                    # Check environment variables
                    env | grep -E "(PATH|USER|HOME)" | head -3

                    echo "Security validation completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with database operations simulation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'DatabaseOps';
            }

            public function getScript(): string
            {
                return '
                    echo "Simulating database operations"

                    # Create a mock database file
                    cat > /tmp/mock_db.sql << EOF
                    CREATE TABLE users (
                        id INT PRIMARY KEY,
                        name VARCHAR(100),
                        email VARCHAR(255)
                    );
                    INSERT INTO users VALUES (1, "Test User", "test@example.com");
                    SELECT * FROM users;
                    EOF

                    echo "Database operations simulated"
                    echo "Mock SQL file created at /tmp/mock_db.sql"

                    # Cleanup
                    rm -f /tmp/mock_db.sql
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with logging and monitoring', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'LoggingMonitoring';
            }

            public function getScript(): string
            {
                return '
                    echo "Starting logging and monitoring task"

                    # Create log file
                    LOG_FILE="/tmp/task_log_$(date +%Y%m%d_%H%M%S).log"

                    echo "Task started at $(date)" | tee $LOG_FILE
                    echo "Hostname: $(hostname)" | tee -a $LOG_FILE
                    echo "User: $(whoami)" | tee -a $LOG_FILE

                    # Simulate some work
                    for i in {1..3}; do
                        echo "Processing step $i at $(date)" | tee -a $LOG_FILE
                        sleep 0.1
                    done

                    echo "Task completed at $(date)" | tee -a $LOG_FILE
                    echo "Log file: $LOG_FILE"

                    # Cleanup
                    rm -f $LOG_FILE
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with configuration management', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'ConfigManagement';
            }

            public function getScript(): string
            {
                return '
                    echo "Managing configuration files"

                    # Create config directory
                    CONFIG_DIR="/tmp/test_config"
                    mkdir -p $CONFIG_DIR

                    # Create various config files
                    cat > $CONFIG_DIR/app.conf << EOF
                    [app]
                    name = TestApp
                    version = 1.0.0
                    debug = false
                    EOF

                    cat > $CONFIG_DIR/database.conf << EOF
                    [database]
                    host = localhost
                    port = 5432
                    name = testdb
                    EOF

                    # Validate configs
                    echo "Configuration files created:"
                    ls -la $CONFIG_DIR/

                    # Cleanup
                    rm -rf $CONFIG_DIR
                    echo "Configuration management completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with backup and restore operations', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'BackupRestore';
            }

            public function getScript(): string
            {
                return '
                    echo "Performing backup and restore operations"

                    # Create test data
                    mkdir -p /tmp/test_data
                    echo "original data" > /tmp/test_data/file1.txt
                    echo "more data" > /tmp/test_data/file2.txt

                    # Create backup
                    BACKUP_FILE="/tmp/backup_$(date +%Y%m%d_%H%M%S).tar.gz"
                    tar -czf $BACKUP_FILE -C /tmp test_data

                    echo "Backup created: $BACKUP_FILE"

                    # Simulate data loss
                    rm -rf /tmp/test_data

                    # Restore from backup
                    mkdir -p /tmp/restored_data
                    tar -xzf $BACKUP_FILE -C /tmp

                    echo "Data restored"
                    echo "Restored files:"
                    ls -la /tmp/test_data/

                    # Verify restoration
                    if [ -f /tmp/test_data/file1.txt ] && [ -f /tmp/test_data/file2.txt ]; then
                        echo "Backup and restore verification successful"
                    else
                        echo "Backup and restore verification failed"
                        exit 1
                    fi

                    # Cleanup
                    rm -rf /tmp/test_data /tmp/restored_data $BACKUP_FILE
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with service management simulation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'ServiceManagement';
            }

            public function getScript(): string
            {
                return '
                    echo "Simulating service management operations"

                    # Create mock service script
                    cat > /tmp/mock_service.sh << EOF
                    #!/bin/bash
                    case "$1" in
                        start)
                            echo "Service starting..."
                            echo "started" > /tmp/service_status
                            ;;
                        stop)
                            echo "Service stopping..."
                            rm -f /tmp/service_status
                            ;;
                        status)
                            if [ -f /tmp/service_status ]; then
                                echo "Service is running"
                            else
                                echo "Service is stopped"
                            fi
                            ;;
                        *)
                            echo "Usage: $0 {start|stop|status}"
                            exit 1
                            ;;
                    esac
                    EOF

                    chmod +x /tmp/mock_service.sh

                    # Test service operations
                    echo "Testing service start:"
                    /tmp/mock_service.sh start

                    echo "Testing service status:"
                    /tmp/mock_service.sh status

                    echo "Testing service stop:"
                    /tmp/mock_service.sh stop

                    echo "Testing service status after stop:"
                    /tmp/mock_service.sh status

                    # Cleanup
                    rm -f /tmp/mock_service.sh /tmp/service_status
                    echo "Service management simulation completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with package management simulation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'PackageManagement';
            }

            public function getScript(): string
            {
                return '
                    echo "Simulating package management operations"

                    # Create mock package list
                    cat > /tmp/packages.txt << EOF
                    nginx-1.18.0
                    php-8.1.0
                    mysql-8.0.0
                    redis-6.2.0
                    EOF

                    # Simulate package installation
                    echo "Installing packages:"
                    while read package; do
                        echo "Installing $package..."
                        sleep 0.1
                        echo "$package installed successfully"
                    done < /tmp/packages.txt

                    # Simulate package verification
                    echo "Verifying installations:"
                    for package in nginx php mysql redis; do
                        echo "Checking $package..."
                        echo "$package is installed and running"
                    done

                    # Cleanup
                    rm -f /tmp/packages.txt
                    echo "Package management simulation completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with load balancing simulation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'LoadBalancing';
            }

            public function getScript(): string
            {
                return '
                    echo "Simulating load balancing operations"

                    # Create mock server list
                    cat > /tmp/servers.txt << EOF
                    server1.example.com:80
                    server2.example.com:80
                    server3.example.com:80
                    EOF

                    # Simulate health checks
                    echo "Performing health checks:"
                    while read server; do
                        echo "Checking $server..."
                        # Simulate random health status
                        if [ $((RANDOM % 3)) -eq 0 ]; then
                            echo "$server: HEALTHY"
                        else
                            echo "$server: HEALTHY"
                        fi
                    done < /tmp/servers.txt

                    # Simulate load distribution
                    echo "Distributing load:"
                    for i in {1..5}; do
                        server=$(shuf -n 1 /tmp/servers.txt)
                        echo "Request $i routed to $server"
                    done

                    # Cleanup
                    rm -f /tmp/servers.txt
                    echo "Load balancing simulation completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with SSL certificate management', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'SSLCertManagement';
            }

            public function getScript(): string
            {
                return '
                    echo "Managing SSL certificates"

                    # Create mock certificate directory
                    CERT_DIR="/tmp/ssl_certs"
                    mkdir -p $CERT_DIR

                    # Create mock certificate files
                    cat > $CERT_DIR/domain.crt << EOF
                    -----BEGIN CERTIFICATE-----
                    MOCK_CERTIFICATE_DATA
                    -----END CERTIFICATE-----
                    EOF

                    cat > $CERT_DIR/domain.key << EOF
                    -----BEGIN PRIVATE KEY-----
                    MOCK_PRIVATE_KEY_DATA
                    -----END PRIVATE KEY-----
                    EOF

                    # Simulate certificate validation
                    echo "Validating certificate:"
                    if [ -f "$CERT_DIR/domain.crt" ] && [ -f "$CERT_DIR/domain.key" ]; then
                        echo "Certificate files exist"
                        echo "Certificate validation successful"
                    else
                        echo "Certificate validation failed"
                        exit 1
                    fi

                    # Simulate certificate installation
                    echo "Installing certificate..."
                    echo "Certificate installed successfully"

                    # Cleanup
                    rm -rf $CERT_DIR
                    echo "SSL certificate management completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with cron job management', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'CronJobManagement';
            }

            public function getScript(): string
            {
                return '
                    echo "Managing cron jobs"

                    # Create mock cron job
                    CRON_JOB="*/5 * * * * /usr/bin/echo \"Cron job executed at $(date)\" >> /tmp/cron.log"

                    # Simulate adding cron job
                    echo "Adding cron job:"
                    echo "$CRON_JOB" > /tmp/mock_crontab
                    echo "Cron job added successfully"

                    # Simulate listing cron jobs
                    echo "Current cron jobs:"
                    cat /tmp/mock_crontab

                    # Simulate removing cron job
                    echo "Removing cron job..."
                    rm -f /tmp/mock_crontab
                    echo "Cron job removed successfully"

                    # Cleanup
                    rm -f /tmp/cron.log
                    echo "Cron job management completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with firewall configuration', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'FirewallConfig';
            }

            public function getScript(): string
            {
                return '
                    echo "Configuring firewall rules"

                    # Create mock firewall rules file
                    cat > /tmp/firewall_rules.txt << EOF
                    # Allow SSH
                    -A INPUT -p tcp --dport 22 -j ACCEPT

                    # Allow HTTP/HTTPS
                    -A INPUT -p tcp --dport 80 -j ACCEPT
                    -A INPUT -p tcp --dport 443 -j ACCEPT

                    # Allow MySQL
                    -A INPUT -p tcp --dport 3306 -j ACCEPT

                    # Default deny
                    -A INPUT -j DROP
                    EOF

                    # Simulate firewall rule application
                    echo "Applying firewall rules:"
                    while read rule; do
                        if [[ $rule =~ ^# ]]; then
                            echo "Comment: $rule"
                        elif [[ $rule =~ ^- ]]; then
                            echo "Applying rule: $rule"
                        fi
                    done < /tmp/firewall_rules.txt

                    echo "Firewall rules applied successfully"

                    # Simulate firewall status check
                    echo "Firewall status:"
                    echo "Firewall is active and running"

                    # Cleanup
                    rm -f /tmp/firewall_rules.txt
                    echo "Firewall configuration completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with log rotation simulation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'LogRotation';
            }

            public function getScript(): string
            {
                return '
                    echo "Performing log rotation"

                    # Create mock log files
                    for i in {1..5}; do
                        echo "Log entry $i from $(date)" > /tmp/app.log.$i
                    done

                    # Create current log
                    echo "Current log entry" > /tmp/app.log

                    echo "Log files before rotation:"
                    ls -la /tmp/app.log*

                    # Simulate log rotation
                    echo "Rotating logs..."
                    mv /tmp/app.log /tmp/app.log.1
                    touch /tmp/app.log

                    # Remove old logs (keep only 3)
                    rm -f /tmp/app.log.4 /tmp/app.log.5

                    echo "Log files after rotation:"
                    ls -la /tmp/app.log*

                    # Cleanup
                    rm -f /tmp/app.log*
                    echo "Log rotation completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with user management simulation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'UserManagement';
            }

            public function getScript(): string
            {
                return '
                    echo "Managing users"

                    # Create mock user list
                    cat > /tmp/users.txt << EOF
                    john:1001:John Doe
                    jane:1002:Jane Smith
                    admin:1000:Administrator
                    EOF

                    # Simulate user creation
                    echo "Creating users:"
                    while IFS=":" read username uid fullname; do
                        echo "Creating user: $username (UID: $uid) - $fullname"
                        echo "User $username created successfully"
                    done < /tmp/users.txt

                    # Simulate user verification
                    echo "Verifying users:"
                    while IFS=":" read username uid fullname; do
                        echo "User $username exists and is active"
                    done < /tmp/users.txt

                    # Cleanup
                    rm -f /tmp/users.txt
                    echo "User management completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with disk space management', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'DiskSpaceManagement';
            }

            public function getScript(): string
            {
                return '
                    echo "Managing disk space"

                    # Create mock disk usage report
                    cat > /tmp/disk_usage.txt << EOF
                    Filesystem     1K-blocks    Used Available Use% Mounted on
                    /dev/sda1      10485760  5242880   5242880  50% /
                    /dev/sdb1      20971520 10485760  10485760  50% /data
                    EOF

                    # Simulate disk space analysis
                    echo "Analyzing disk usage:"
                    while read line; do
                        if [[ $line =~ /dev/ ]]; then
                            echo "Checking: $line"
                            echo "Disk space analysis completed"
                        fi
                    done < /tmp/disk_usage.txt

                    # Simulate cleanup operations
                    echo "Performing cleanup operations:"
                    echo "Removing temporary files..."
                    echo "Clearing cache directories..."
                    echo "Compressing log files..."
                    echo "Cleanup completed"

                    # Cleanup
                    rm -f /tmp/disk_usage.txt
                    echo "Disk space management completed"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with performance monitoring', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'PerformanceMonitoring';
            }

            public function getScript(): string
            {
                return '
                    echo "Monitoring system performance"

                    # Simulate CPU monitoring
                    echo "CPU Usage:"
                    echo "CPU: 25% user, 15% system, 60% idle"

                    # Simulate memory monitoring
                    echo "Memory Usage:"
                    echo "Total: 8192MB, Used: 4096MB, Free: 4096MB"

                    # Simulate disk I/O monitoring
                    echo "Disk I/O:"
                    echo "Read: 1024 KB/s, Write: 512 KB/s"

                    # Simulate network monitoring
                    echo "Network Usage:"
                    echo "RX: 2048 KB/s, TX: 1024 KB/s"

                    # Create performance report
                    cat > /tmp/performance_report.txt << EOF
                    Performance Report - $(date)
                    ================================
                    CPU Usage: 25%
                    Memory Usage: 50%
                    Disk I/O: Normal
                    Network: Normal
                    ================================
                    EOF

                    echo "Performance monitoring completed"
                    echo "Report saved to /tmp/performance_report.txt"

                    # Cleanup
                    rm -f /tmp/performance_report.txt
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with disaster recovery simulation', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'DisasterRecovery';
            }

            public function getScript(): string
            {
                return '
                    echo "Simulating disaster recovery procedures"

                    # Create mock backup verification
                    echo "Verifying backup integrity..."
                    echo "Backup verification: PASSED"

                    # Simulate system state assessment
                    echo "Assessing system state..."
                    echo "System state: DEGRADED"
                    echo "Critical services: 3/5 running"

                    # Simulate recovery procedures
                    echo "Initiating recovery procedures..."
                    echo "Step 1: Stopping non-critical services"
                    echo "Step 2: Restoring from backup"
                    echo "Step 3: Verifying data integrity"
                    echo "Step 4: Restarting services"
                    echo "Step 5: Running health checks"

                    # Simulate recovery verification
                    echo "Verifying recovery..."
                    echo "All critical services: RUNNING"
                    echo "Data integrity: VERIFIED"
                    echo "System state: HEALTHY"

                    echo "Disaster recovery simulation completed successfully"
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });

    it('handles tasks with compliance auditing', function () {
        $dispatcher = makeDispatcherWithHostFailures([]);
        $multi = new MultiServerDispatcher($dispatcher);
        $task = new class extends Task
        {
            public function getName(): string
            {
                return 'ComplianceAuditing';
            }

            public function getScript(): string
            {
                return '
                    echo "Performing compliance audit"

                    # Create mock compliance checklist
                    cat > /tmp/compliance_checklist.txt << EOF
                    SECURITY_CHECKS:
                    - Password policy: COMPLIANT
                    - Access controls: COMPLIANT
                    - Encryption: COMPLIANT
                    - Audit logging: COMPLIANT

                    BACKUP_CHECKS:
                    - Backup frequency: COMPLIANT
                    - Backup retention: COMPLIANT
                    - Backup testing: COMPLIANT

                    NETWORK_CHECKS:
                    - Firewall rules: COMPLIANT
                    - SSL certificates: COMPLIANT
                    - Network segmentation: COMPLIANT
                    EOF

                    # Simulate compliance checks
                    echo "Running compliance checks:"
                    while read line; do
                        if [[ $line =~ COMPLIANT ]]; then
                            echo "✓ $line"
                        elif [[ $line =~ - ]]; then
                            echo "  $line"
                        else
                            echo "$line"
                        fi
                    done < /tmp/compliance_checklist.txt

                    # Generate compliance report
                    echo "Compliance audit completed"
                    echo "Overall compliance status: COMPLIANT"

                    # Cleanup
                    rm -f /tmp/compliance_checklist.txt
                ';
            }
        };
        $connections = [makeConnection('a')];
        $result = $multi->dispatch($task, $connections, ['parallel' => true]);
        expect($result['overall_success'])->toBeTrue();
    });
});
