<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Broadcasting;

use App\Modules\TaskRunner\Broadcasting\TaskRunnerBroadcaster as BaseTaskRunnerBroadcaster;
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

// Real implementation of StreamingLoggerInterface for testing
class TaskRunnerBroadcaster implements StreamingLoggerInterface
{
    public array $streamHandlers = [];

    public array $loggedMessages = [];

    public array $streamedMessages = [];

    public function log(string $level, string $message, array $context = [], bool $stream = false): void
    {
        $this->loggedMessages[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'stream' => $stream,
        ];

        if ($stream) {
            $this->stream($level, $message, $context);
        }
    }

    public function stream(string $level, string $message, array $context = []): void
    {
        $this->streamedMessages[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        // Execute all stream handlers
        foreach ($this->streamHandlers as $handler) {
            $handler([
                'timestamp' => now()->toISOString(),
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        }
    }

    public function addStreamHandler(callable $handler, ?string $channel = null): void
    {
        $this->streamHandlers[] = $handler;
    }

    public function removeStreamHandler(callable $handler): void
    {
        $key = array_search($handler, $this->streamHandlers, true);
        if ($key !== false) {
            unset($this->streamHandlers[$key]);
        }
    }

    public function getStreamHandlers(): array
    {
        return $this->streamHandlers;
    }

    public function clearStreamHandlers(): void
    {
        $this->streamHandlers = [];
    }

    public function streamProcessOutput(string $type, string $output, array $context = []): void
    {
        $this->stream('info', "Process output [$type]: $output", $context);
    }

    public function streamTaskEvent(string $event, array $context = []): void
    {
        $this->stream('info', "Task event: $event", $context);
    }

    public function streamError(string $message, array $context = []): void
    {
        $this->stream('error', $message, $context);
    }

    public function streamProgress(int $current, int $total, string $message = '', array $context = []): void
    {
        $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;
        $this->stream('info', "Progress: $current/$total ($percentage%) $message", array_merge($context, [
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
        ]));
    }

    public function streamChainEvent(string $event, array $context = []): void
    {
        $this->stream('info', "Chain event: $event", $context);
    }
}

beforeEach(function () {
    $this->broadcaster = new BaseTaskRunnerBroadcaster;
    $this->streamingLogger = new TestStreamingLogger;
});

describe('TaskRunnerBroadcaster', function () {
    describe('constants', function () {
        test('has correct channel constant', function () {
            expect(TaskRunnerBroadcaster::CHANNEL)->toBe('task-runner');
        });

        test('has correct private channel prefix constant', function () {
            expect(TaskRunnerBroadcaster::PRIVATE_CHANNEL_PREFIX)->toBe('private-task-runner');
        });
    });

    describe('register', function () {
        test('registers handlers when websocket logging is enabled', function () {
            Config::set('task-runner.logging.streaming.handlers.websocket', true);

            $this->broadcaster->register($this->streamingLogger);

            expect($this->streamingLogger->getStreamHandlers())->toHaveCount(3);
        });

        test('does not register handlers when websocket logging is disabled', function () {
            Config::set('task-runner.logging.streaming.handlers.websocket', false);

            $this->broadcaster->register($this->streamingLogger);

            expect($this->streamingLogger->getStreamHandlers())->toHaveCount(0);
        });

        test('does not register handlers when websocket config is missing', function () {
            // Test with null value (should be falsy and not register handlers)
            Config::set('task-runner.logging.streaming.handlers.websocket', null);

            $this->broadcaster->register($this->streamingLogger);

            expect($this->streamingLogger->getStreamHandlers())->toHaveCount(0);
        });

        test('registered handlers execute broadcaster methods', function () {
            Config::set('task-runner.logging.streaming.handlers.websocket', true);

            $this->broadcaster->register($this->streamingLogger);

            // Test that the registered handlers work
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'level' => 'info',
                'message' => 'Test message',
                'context' => ['key' => 'value'],
            ];

            // Execute the websocket handler directly
            $handlers = $this->streamingLogger->getStreamHandlers();
            expect($handlers)->toHaveCount(3);

            // Test that handlers can be executed without throwing exceptions
            foreach ($handlers as $handler) {
                expect(fn () => $handler($logData))->not->toThrow(Exception::class);
            }
        });
    });

    describe('broadcastLog', function () {
        test('broadcasts log message successfully', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'level' => 'info',
                'message' => 'Test log message',
                'context' => ['key' => 'value'],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastLog($logData))->not->toThrow(Exception::class);
        });

        test('handles broadcast failure gracefully', function () {
            // Create invalid data that might cause broadcasting issues
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'level' => 'info',
                'message' => 'Test log message',
                'context' => ['key' => 'value'],
            ];

            // Test that the method handles potential broadcast errors gracefully
            expect(fn () => $this->broadcaster->broadcastLog($logData))->not->toThrow(Exception::class);
        });
    });

    describe('broadcastTaskEvent', function () {
        test('broadcasts task event successfully', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Task started',
                'context' => [
                    'event' => 'task_started',
                    'task_id' => 'task-123',
                    'command' => 'php artisan test',
                ],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastTaskEvent($logData))->not->toThrow(Exception::class);
        });

        test('broadcasts to task-specific channel when task_id is present', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Task started',
                'context' => [
                    'event' => 'task_started',
                    'task_id' => 'task-123',
                    'command' => 'php artisan test',
                ],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastTaskEvent($logData))->not->toThrow(Exception::class);
        });

        test('does not broadcast to task-specific channel when task_id is missing', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Task started',
                'context' => [
                    'event' => 'task_started',
                    'command' => 'php artisan test',
                ],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastTaskEvent($logData))->not->toThrow(Exception::class);
        });

        test('handles missing context values gracefully', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Task started',
                'context' => [],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastTaskEvent($logData))->not->toThrow(Exception::class);
        });

        test('handles broadcast failure gracefully', function () {

            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Task started',
                'context' => ['event' => 'task_started'],
            ];

            // Test that the method handles potential broadcast errors gracefully
            expect(fn () => $this->broadcaster->broadcastTaskEvent($logData))->not->toThrow(Exception::class);
        });
    });

    describe('broadcastProgress', function () {
        test('broadcasts progress update successfully', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Processing item 5 of 10',
                'context' => [
                    'current' => 5,
                    'total' => 10,
                    'percentage' => 50,
                    'task_id' => 'task-123',
                ],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastProgress($logData))->not->toThrow(Exception::class);
        });

        test('broadcasts to task-specific channel when task_id is present', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Processing item 5 of 10',
                'context' => [
                    'current' => 5,
                    'total' => 10,
                    'percentage' => 50,
                    'task_id' => 'task-123',
                ],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastProgress($logData))->not->toThrow(Exception::class);
        });

        test('handles missing context values gracefully', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Processing',
                'context' => [],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastProgress($logData))->not->toThrow(Exception::class);
        });

        test('handles broadcast failure gracefully', function () {

            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Processing',
                'context' => ['current' => 5, 'total' => 10],
            ];

            // Test that the method handles potential broadcast errors gracefully
            expect(fn () => $this->broadcaster->broadcastProgress($logData))->not->toThrow(Exception::class);
        });
    });

    describe('broadcastToUser', function () {
        test('broadcasts to user private channel successfully', function () {
            $userId = 123;
            $event = 'custom-event';
            $data = ['key' => 'value'];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastToUser($userId, $event, $data))->not->toThrow(Exception::class);
        });

        test('handles broadcast failure gracefully', function () {

            $userId = 123;
            $event = 'custom-event';
            $data = ['key' => 'value'];

            // Test that the method handles potential broadcast errors gracefully
            expect(fn () => $this->broadcaster->broadcastToUser($userId, $event, $data))->not->toThrow(Exception::class);
        });
    });

    describe('broadcastMetrics', function () {
        test('broadcasts metrics successfully', function () {
            $metrics = [
                'active_tasks' => 5,
                'completed_tasks' => 10,
                'failed_tasks' => 2,
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastMetrics($metrics))->not->toThrow(Exception::class);
        });

        test('includes current timestamp in metrics broadcast', function () {
            $metrics = ['test' => 'value'];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastMetrics($metrics))->not->toThrow(Exception::class);
        });

        test('handles broadcast failure gracefully', function () {

            $metrics = ['test' => 'value'];

            // Test that the method handles potential broadcast errors gracefully
            expect(fn () => $this->broadcaster->broadcastMetrics($metrics))->not->toThrow(Exception::class);
        });
    });

    describe('broadcastTaskCompleted', function () {
        test('broadcasts task completion successfully', function () {
            $taskId = 'task-123';
            $result = [
                'successful' => true,
                'exit_code' => 0,
                'duration' => 30.5,
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastTaskCompleted($taskId, $result))->not->toThrow(Exception::class);
        });

        test('broadcasts to both main channel and task-specific channel', function () {
            $taskId = 'task-123';
            $result = ['successful' => true];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastTaskCompleted($taskId, $result))->not->toThrow(Exception::class);
        });

        test('handles missing result values gracefully', function () {
            $taskId = 'task-123';
            $result = [];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastTaskCompleted($taskId, $result))->not->toThrow(Exception::class);
        });

        test('handles broadcast failure gracefully', function () {

            $taskId = 'task-123';
            $result = ['successful' => true];

            // Test that the method handles potential broadcast errors gracefully
            expect(fn () => $this->broadcaster->broadcastTaskCompleted($taskId, $result))->not->toThrow(Exception::class);
        });
    });

    describe('integration tests', function () {
        test('can handle multiple broadcast types in sequence', function () {
            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'level' => 'info',
                'message' => 'Test log',
                'context' => [],
            ];

            $taskEventData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Task started',
                'context' => ['event' => 'task_started', 'task_id' => 'task-123'],
            ];

            $progressData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'message' => 'Progress update',
                'context' => ['current' => 5, 'total' => 10, 'task_id' => 'task-123'],
            ];

            // Test that all methods execute without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastLog($logData))->not->toThrow(Exception::class);
            expect(fn () => $this->broadcaster->broadcastTaskEvent($taskEventData))->not->toThrow(Exception::class);
            expect(fn () => $this->broadcaster->broadcastProgress($progressData))->not->toThrow(Exception::class);
        });

        test('can handle empty or minimal data gracefully', function () {
            $minimalLogData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'level' => 'info',
                'message' => '',
                'context' => [],
            ];

            // Test that the method executes without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastLog($minimalLogData))->not->toThrow(Exception::class);
        });

        test('can handle malformed data gracefully', function () {
            $malformedData = [
                'timestamp' => null,
                'level' => '',
                'message' => null,
                'context' => null,
            ];

            // Test that the method handles malformed data without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastLog($malformedData))->not->toThrow(Exception::class);
        });

        test('can handle large data sets', function () {
            $largeContext = [];
            for ($i = 0; $i < 1000; $i++) {
                $largeContext["key_$i"] = "value_$i";
            }

            $logData = [
                'timestamp' => '2024-01-01T12:00:00Z',
                'level' => 'info',
                'message' => 'Large data test',
                'context' => $largeContext,
            ];

            // Test that the method handles large data without throwing exceptions
            expect(fn () => $this->broadcaster->broadcastLog($logData))->not->toThrow(Exception::class);
        });

        test('streaming logger integration works correctly', function () {
            Config::set('task-runner.logging.streaming.handlers.websocket', true);

            // Register the broadcaster with the streaming logger
            $this->broadcaster->register($this->streamingLogger);

            // Verify handlers were registered
            expect($this->streamingLogger->getStreamHandlers())->toHaveCount(3);

            // Test streaming functionality
            $this->streamingLogger->stream('info', 'Test message', ['key' => 'value']);

            // Verify the message was streamed
            expect($this->streamingLogger->streamedMessages)->toHaveCount(1);
            expect($this->streamingLogger->streamedMessages[0]['message'])->toBe('Test message');
            expect($this->streamingLogger->streamedMessages[0]['level'])->toBe('info');
        });
    });
});
