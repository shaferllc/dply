<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Contracts;

interface StreamingLoggerInterface
{
    /**
     * Log a message with streaming support.
     *
     * @param  string  $level  The log level (debug, info, warning, error)
     * @param  string  $message  The message to log
     * @param  array  $context  Additional context data
     * @param  bool  $stream  Whether to stream this message immediately
     */
    public function log(string $level, string $message, array $context = [], bool $stream = false): void;

    /**
     * Stream a message immediately to all registered stream handlers.
     *
     * @param  string  $level  The log level
     * @param  string  $message  The message to stream
     * @param  array  $context  Additional context data
     */
    public function stream(string $level, string $message, array $context = []): void;

    /**
     * Add a stream handler for real-time logging.
     *
     * @param  callable  $handler  The handler function
     * @param  string|null  $channel  The channel to listen to (null for all)
     */
    public function addStreamHandler(callable $handler, ?string $channel = null): void;

    /**
     * Remove a stream handler.
     *
     * @param  callable  $handler  The handler to remove
     */
    public function removeStreamHandler(callable $handler): void;

    /**
     * Get all registered stream handlers.
     *
     * @return array<int, callable>
     */
    public function getStreamHandlers(): array;

    /**
     * Clear all stream handlers.
     */
    public function clearStreamHandlers(): void;

    /**
     * Stream process output in real-time.
     */
    public function streamProcessOutput(string $type, string $output, array $context = []): void;

    /**
     * Stream task lifecycle events.
     */
    public function streamTaskEvent(string $event, array $context = []): void;

    /**
     * Stream error events.
     */
    public function streamError(string $message, array $context = []): void;

    /**
     * Stream progress updates.
     */
    public function streamProgress(int $current, int $total, string $message = '', array $context = []): void;

    /**
     * Stream task chain events.
     */
    public function streamChainEvent(string $event, array $context = []): void;
}
