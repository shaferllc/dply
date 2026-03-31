<?php

namespace App\Actions\Concerns;

/**
 * Automatically enforces execution timeouts for actions.
 *
 * Uses the decorator pattern to automatically wrap actions and enforce
 * execution timeouts. The TimeoutDecorator intercepts handle() calls
 * and terminates execution if it exceeds the configured timeout.
 *
 * How it works:
 * 1. When an action uses AsTimeout, TimeoutDesignPattern recognizes it
 * 2. ActionManager wraps the action with TimeoutDecorator
 * 3. When handle() is called, the decorator:
 *    - Gets the timeout duration from action
 *    - Executes the action with timeout enforcement
 *    - Throws RuntimeException if execution exceeds timeout
 *    - Adds timeout metadata to the result
 *
 * Benefits:
 * - Prevents long-running actions from hanging
 * - Automatic timeout enforcement
 * - Support for PCNTL (precise) or timer-based (fallback) timeout
 * - Configurable timeout duration
 * - Timeout metadata in results
 * - Seamless integration with other decorators
 *
 * Timeout Methods:
 * - PCNTL (preferred): Uses pcntl_alarm for precise timeout enforcement
 *   Requires PCNTL extension to be loaded
 * - Timer-based (fallback): Checks elapsed time after execution
 *   Works on all systems but less precise
 *
 * @example
 * // Basic usage - timeout happens automatically:
 * class ProcessLargeFile
 * {
 *     use AsAction;
 *     use AsTimeout;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Long-running operation - automatically timed out
 *         return processFile($filePath);
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessLargeFile::run($filePath);
 * // $result->_timeout = [
 * //     'seconds' => 300,
 * //     'enforced' => true, // true if PCNTL available, false otherwise
 * // ];
 *
 * // Access timeout metadata:
 * $timeoutSeconds = $result->_timeout['seconds'] ?? null;
 * $wasEnforced = $result->_timeout['enforced'] ?? false;
 * @example
 * // Customize timeout via attributes (recommended):
 * use App\Actions\Attributes\TimeoutEnabled;
 * use App\Actions\Attributes\TimeoutSeconds;
 *
 * #[TimeoutEnabled(true)]  // Enable timeout
 * #[TimeoutSeconds(600)]   // 10 minutes
 * class ProcessLargeFile
 * {
 *     use AsAction;
 *     use AsTimeout;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Long-running operation
 *         return processFile($filePath);
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessLargeFile::run($filePath);
 * // $result->_timeout = [
 * //     'seconds' => 600,
 * //     'enforced' => true,
 * // ];
 * @example
 * // Disable timeout via attribute:
 * use App\Actions\Attributes\TimeoutEnabled;
 *
 * #[TimeoutEnabled(false)] // Disable timeout for this action
 * class ProcessLargeFile
 * {
 *     use AsAction;
 *     use AsTimeout;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Long-running operation - no timeout enforced
 *         return processFile($filePath);
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessLargeFile::run($filePath);
 * // $result->_timeout is not set when timeout is disabled
 * @example
 * // Customize timeout via method (alternative to attributes):
 * class ProcessLargeFile
 * {
 *     use AsAction;
 *     use AsTimeout;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Long-running operation
 *         return processFile($filePath);
 *     }
 *
 *     protected function getTimeout(): int
 *     {
 *         return 600; // 10 minutes in seconds
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessLargeFile::run($filePath);
 * // $result->_timeout = [
 * //     'seconds' => 600,
 * //     'enforced' => true,
 * // ];
 * @example
 * // Real-world example: Using timeout with other decorators
 * use App\Actions\Attributes\TimeoutEnabled;
 * use App\Actions\Attributes\TimeoutSeconds;
 * use App\Actions\Attributes\TransactionAttempts;
 * use App\Actions\Attributes\TraceName;
 *
 * #[TimeoutEnabled(true)]
 * #[TimeoutSeconds(30)] // 30 second timeout
 * #[TransactionAttempts(1)]
 * #[TraceName('tags.create')]
 * class CreateTag
 * {
 *     use AsAction;
 *     use AsTimeout;
 *
 *     public function handle(Team $team, array $data): Tag
 *     {
 *         // Database operations that might take time
 *         $tag = Tag::findOrCreate($data['name']);
 *         $team->attachTag($tag);
 *
 *         return $tag;
 *     }
 * }
 *
 * // Usage:
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 *
 * // Access timeout metadata along with other decorator metadata:
 * // $tag->_timeout = ['seconds' => 30, 'enforced' => true];
 * // $tag->_transaction = ['used' => true, 'attempts' => 1];
 * // $tag->_trace = ['trace_id' => '...', 'span_id' => '...'];
 * @example
 * // Dynamic timeout based on input or conditions:
 * class ProcessData
 * {
 *     use AsAction;
 *     use AsTimeout;
 *
 *     public function __construct(
 *         public int $dataSize
 *     ) {}
 *
 *     public function handle(array $data): array
 *     {
 *         // Process data based on size
 *         return processData($data);
 *     }
 *
 *     protected function getTimeout(): int
 *     {
 *         // Larger datasets get more time
 *         return match (true) {
 *             $this->dataSize > 1000000 => 600,  // 10 minutes for large datasets
 *             $this->dataSize > 100000 => 300,   // 5 minutes for medium datasets
 *             default => 60,                     // 1 minute for small datasets
 *         };
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessData::make(dataSize: 500000)->handle($data);
 * // $result->_timeout = ['seconds' => 300, 'enforced' => true];
 */
trait AsTimeout
{
    //
}
