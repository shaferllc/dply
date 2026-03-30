<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class StreamingLogger implements StreamingLoggerInterface
{
    /**
     * Registered stream handlers.
     *
     * @var array<int, array{handler: callable, channel: ?string}>
     */
    protected array $streamHandlers = [];

    /**
     * Whether streaming is enabled.
     */
    protected bool $streamingEnabled;

    /**
     * The default log level for streaming.
     */
    protected string $defaultLevel;

    /**
     * Create a new StreamingLogger instance.
     */
    public function __construct()
    {
        $this->streamingEnabled = config('task-runner.logging.streaming.enabled', true);
        $this->defaultLevel = config('task-runner.logging.streaming.default_level', 'info');
    }

    /**
     * Log a message with streaming support.
     */
    public function log(string $level, string $message, array $context = [], bool $stream = false): void
    {
        // Always log to Laravel's logging system
        Log::log($level, $message, $context);

        // Stream if requested or if streaming is enabled by default
        if ($stream || $this->shouldStreamByDefault($level)) {
            $this->stream($level, $message, $context);
        }
    }

    /**
     * Stream a message immediately to all registered stream handlers.
     */
    public function stream(string $level, string $message, array $context = []): void
    {
        if (! $this->streamingEnabled) {
            return;
        }

        $logData = [
            'timestamp' => now()->toISOString(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        foreach ($this->streamHandlers as $handlerData) {
            try {
                $handler = $handlerData['handler'];
                $channel = $handlerData['channel'];

                // If channel is specified, only stream to handlers for that channel
                if ($channel === null || $this->isChannelMatch($channel, $context)) {
                    $handler($logData);
                }
            } catch (\Throwable $e) {
                // Log the error but don't break the streaming
                Log::error('Streaming handler error', [
                    'error' => $e->getMessage(),
                    'handler' => get_class($handler),
                ]);
            }
        }
    }

    /**
     * Add a stream handler for real-time logging.
     */
    public function addStreamHandler(callable $handler, ?string $channel = null): void
    {
        if (! is_callable($handler)) {
            throw new InvalidArgumentException('Handler must be callable');
        }

        $this->streamHandlers[] = [
            'handler' => $handler,
            'channel' => $channel,
        ];
    }

    /**
     * Remove a stream handler.
     */
    public function removeStreamHandler(callable $handler): void
    {
        $this->streamHandlers = array_filter(
            $this->streamHandlers,
            fn ($handlerData) => $handlerData['handler'] !== $handler
        );
    }

    /**
     * Get all registered stream handlers.
     */
    public function getStreamHandlers(): array
    {
        return array_column($this->streamHandlers, 'handler');
    }

    /**
     * Clear all stream handlers.
     */
    public function clearStreamHandlers(): void
    {
        $this->streamHandlers = [];
    }

    /**
     * Stream process output in real-time.
     */
    public function streamProcessOutput(string $type, string $output, array $context = []): void
    {
        $level = $type === 'err' ? 'warning' : 'info';
        $message = trim($output);

        if (! empty($message)) {
            $this->stream($level, $message, array_merge($context, [
                'type' => $type,
                'stream_type' => 'process_output',
            ]));
        }
    }

    /**
     * Stream task lifecycle events.
     */
    public function streamTaskEvent(string $event, array $context = []): void
    {
        $this->stream('info', "Task {$event}", array_merge($context, [
            'stream_type' => 'task_event',
            'event' => $event,
        ]));
    }

    /**
     * Stream error events.
     */
    public function streamError(string $message, array $context = []): void
    {
        $this->stream('error', $message, array_merge($context, [
            'stream_type' => 'error',
        ]));
    }

    /**
     * Stream progress updates.
     */
    public function streamProgress(int $current, int $total, string $message = '', array $context = []): void
    {
        $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;

        $this->stream('info', $message ?: "Progress: {$percentage}%", array_merge($context, [
            'stream_type' => 'progress',
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
        ]));
    }

    /**
     * Stream task chain events.
     */
    public function streamChainEvent(string $event, array $context = []): void
    {
        $this->stream('info', "Chain {$event}", array_merge($context, [
            'stream_type' => 'chain_event',
            'event' => $event,
        ]));
    }

    /**
     * Check if a level should be streamed by default.
     */
    protected function shouldStreamByDefault(string $level): bool
    {
        $streamLevels = config('task-runner.logging.streaming.levels', ['info', 'warning', 'error']);

        return in_array($level, $streamLevels);
    }

    /**
     * Check if a channel matches the handler's channel filter.
     */
    protected function isChannelMatch(?string $handlerChannel, array $context): bool
    {
        if ($handlerChannel === null) {
            return true;
        }

        $contextChannel = $context['channel'] ?? null;

        return $contextChannel === $handlerChannel;
    }

    /**
     * Enable or disable streaming.
     */
    public function setStreamingEnabled(bool $enabled): self
    {
        $this->streamingEnabled = $enabled;

        return $this;
    }

    /**
     * Check if streaming is enabled.
     */
    public function isStreamingEnabled(): bool
    {
        return $this->streamingEnabled;
    }

    /**
     * Get the number of registered stream handlers.
     */
    public function getStreamHandlerCount(): int
    {
        return count($this->streamHandlers);
    }
}
