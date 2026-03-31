<?php

namespace App\Actions\Concerns;

use Spatie\Async\Pool;

/**
 * Executes multiple action calls in parallel.
 *
 * Provides parallel execution capabilities for actions, allowing multiple
 * operations to run simultaneously for improved performance. Uses Spatie Async
 * package (https://github.com/spatie/async) when available.
 *
 * How it works:
 * - Provides static `run()` method for executing callbacks in parallel
 * - Provides static `map()` method for parallel mapping over items
 * - Uses Spatie Async Pool for parallel execution
 * - Falls back to sequential execution if Spatie Async not available
 * - All callbacks execute simultaneously in separate processes
 * - Returns array of results in same order as callbacks
 *
 * Benefits:
 * - Execute multiple operations simultaneously
 * - Improved performance for independent operations
 * - Easy parallel processing
 * - Automatic fallback to sequential execution
 * - Works with any callable (actions, closures, etc.)
 * - Process-based parallelism (true parallel execution)
 *
 * Note: This is NOT a decorator - it provides utility methods that
 * actions can call explicitly. Parallel execution is opt-in and explicit,
 * giving you full control over when and how to parallelize operations.
 *
 * Does it need to be a decorator?
 * No. The current trait-based approach works well because:
 * - It provides static utility methods (run, map)
 * - It doesn't need to intercept execution
 * - Parallel execution is explicit and opt-in
 * - Actions control when to use parallel execution
 * - The trait pattern is simpler for this use case
 *
 * A decorator would only be needed if you wanted to automatically
 * parallelize all action executions, but the current approach gives you
 * explicit control over parallel execution.
 *
 * Requirements:
 * - Spatie Async package: `composer require spatie/async`
 * - PCNTL and POSIX extensions for true parallel execution
 * - Falls back to sequential execution if extensions not available
 *
 * Method Name Conflict Resolution:
 * When using `AsAction` (which includes both `AsObject` and `AsParallel`),
 * the `run()` method from `AsParallel` is aliased to `runParallel()` to avoid
 * conflicts with `AsObject::run()`. Use `runParallel()` when using `AsAction`:
 *
 * ```php
 * class MyAction extends Actions
 * {
 *     use AsAction; // Includes both AsObject and AsParallel
 * }
 *
 * // Use runParallel() for parallel execution:
 * $results = MyAction::runParallel([...]);
 *
 * // Use run() for single action execution:
 * $result = MyAction::run($arg);
 * ```
 *
 * When using `AsParallel` directly (without `AsAction`), you can use `run()`:
 * ```php
 * class MyAction extends Actions
 * {
 *     use AsParallel; // Only AsParallel, no conflict
 * }
 *
 * // Use run() directly:
 * $results = MyAction::run([...]);
 * ```
 *
 * @example
 * // Basic usage - parallel file processing:
 * class ProcessFile extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $filePath): array
 *     {
 *         return processFile($filePath);
 *     }
 * }
 *
 * // Usage:
 * $results = AsParallel::run([
 *     fn () => ProcessFile::run('file1.txt'),
 *     fn () => ProcessFile::run('file2.txt'),
 *     fn () => ProcessFile::run('file3.txt'),
 * ]);
 *
 * // All files processed in parallel
 * // $results = [result1, result2, result3]
 * @example
 * // Using map() for parallel mapping:
 * class TransformData extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(array $data): array
 *     {
 *         return transformData($data);
 *     }
 * }
 *
 * // Usage:
 * $items = [['id' => 1], ['id' => 2], ['id' => 3]];
 * $results = AsParallel::map($items, fn ($item) => TransformData::run($item));
 *
 * // All items transformed in parallel
 * // $results = [transformed1, transformed2, transformed3]
 * @example
 * // Parallel API requests:
 * class FetchApiData extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         return Http::get($endpoint)->json();
 *     }
 * }
 *
 * // Usage:
 * $endpoints = [
 *     'https://api.example.com/users',
 *     'https://api.example.com/posts',
 *     'https://api.example.com/comments',
 * ];
 *
 * $results = AsParallel::map($endpoints, fn ($endpoint) => FetchApiData::run($endpoint));
 *
 * // All API requests made in parallel
 * // Much faster than sequential requests
 * @example
 * // Parallel database operations:
 * class GenerateReport extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $reportType): Report
 *     {
 *         return ReportGenerator::generate($reportType);
 *     }
 * }
 *
 * // Usage:
 * $reportTypes = ['sales', 'inventory', 'revenue', 'expenses'];
 * $reports = AsParallel::map($reportTypes, fn ($type) => GenerateReport::run($type));
 *
 * // All reports generated in parallel
 * @example
 * // Parallel with different actions:
 * class ProcessOrder extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * class SendNotification extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(User $user): void
 *     {
 *         // Send notification
 *     }
 * }
 *
 * // Usage:
 * $results = AsParallel::run([
 *     fn () => ProcessOrder::run($order),
 *     fn () => SendNotification::run($user),
 *     fn () => UpdateInventory::run($items),
 * ]);
 *
 * // All operations execute in parallel
 * @example
 * // Parallel with error handling:
 * class RiskyOperation extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $data): array
 *     {
 *         // Operation that might fail
 *         return processData($data);
 *     }
 * }
 *
 * // Usage with error handling:
 * $items = ['data1', 'data2', 'data3'];
 * $results = AsParallel::map($items, function ($item) {
 *     try {
 *         return RiskyOperation::run($item);
 *     } catch (\Exception $e) {
 *         \Log::error("Failed to process {$item}", ['error' => $e->getMessage()]);
 *
 *         return null; // Return null for failed items
 *     }
 * });
 *
 * // Filter out failed results
 * $successful = array_filter($results, fn ($result) => $result !== null);
 * @example
 * // Parallel processing with progress tracking:
 * class ProcessWithProgress extends Actions
 * {
 *     use AsParallel;
 *     use AsProgressive;
 *
 *     public function handle(string $item): array
 *     {
 *         // Process item with progress tracking
 *         return processItem($item);
 *     }
 * }
 *
 * // Usage:
 * $items = ['item1', 'item2', 'item3'];
 * $results = AsParallel::map($items, fn ($item) => ProcessWithProgress::run($item));
 *
 * // Items processed in parallel, each with its own progress tracking
 * @example
 * // Parallel with conditional execution:
 * class ConditionalProcess extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle($item): ?array
 *     {
 *         // Only process if condition met
 *         if (! $this->shouldProcess($item)) {
 *             return null;
 *         }
 *
 *         return processItem($item);
 *     }
 *
 *     protected function shouldProcess($item): bool
 *     {
 *         // Conditional logic
 *         return true;
 *     }
 * }
 *
 * // Usage:
 * $items = ['item1', 'item2', 'item3'];
 * $results = AsParallel::map($items, fn ($item) => ConditionalProcess::run($item));
 * $filtered = array_filter($results, fn ($result) => $result !== null);
 * @example
 * // Parallel batch processing:
 * class ProcessBatch extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(array $items): array
 *     {
 *         // Process batch of items
 *         return array_map(fn ($item) => processItem($item), $items);
 *     }
 * }
 *
 * // Usage:
 * $batches = [
 *     ['item1', 'item2'],
 *     ['item3', 'item4'],
 *     ['item5', 'item6'],
 * ];
 *
 * $results = AsParallel::map($batches, fn ($batch) => ProcessBatch::run($batch));
 *
 * // All batches processed in parallel
 * @example
 * // Parallel with timeout protection:
 * class TimedOperation extends Actions
 * {
 *     use AsParallel;
 *     use AsTimeout;
 *
 *     public function handle(string $data): array
 *     {
 *         // Operation with timeout
 *         return processData($data);
 *     }
 *
 *     public function getTimeout(): int
 *     {
 *         return 30; // 30 second timeout
 *     }
 * }
 *
 * // Usage:
 * $items = ['data1', 'data2', 'data3'];
 * $results = AsParallel::map($items, fn ($item) => TimedOperation::run($item));
 *
 * // Each operation has its own timeout, all run in parallel
 * @example
 * // Parallel with result aggregation:
 * class AggregateData extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(array $sources): array
 *     {
 *         // Aggregate data from multiple sources
 *         return aggregateData($sources);
 *     }
 * }
 *
 * // Usage:
 * $sources = [
 *     ['source' => 'api1', 'data' => [...]],
 *     ['source' => 'api2', 'data' => [...]],
 *     ['source' => 'api3', 'data' => [...]],
 * ];
 *
 * $results = AsParallel::map($sources, fn ($source) => AggregateData::run($source));
 *
 * // Aggregate all results
 * $aggregated = array_merge(...$results);
 * @example
 * // Parallel with dependency injection:
 * class DependentOperation extends Actions
 * {
 *     use AsParallel;
 *
 *     public function __construct(
 *         public DataService $dataService
 *     ) {}
 *
 *     public function handle(string $key): array
 *     {
 *         return $this->dataService->process($key);
 *     }
 * }
 *
 * // Usage:
 * $keys = ['key1', 'key2', 'key3'];
 * $results = AsParallel::map($keys, fn ($key) => DependentOperation::run($key));
 *
 * // Dependencies are automatically injected for each parallel execution
 * @example
 * // Parallel with rate limiting:
 * class RateLimitedOperation extends Actions
 * {
 *     use AsParallel;
 *     use AsRateLimiter;
 *
 *     public function handle(string $data): array
 *     {
 *         return processData($data);
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 10;
 *     }
 * }
 *
 * // Usage:
 * $items = ['data1', 'data2', 'data3'];
 * $results = AsParallel::map($items, fn ($item) => RateLimitedOperation::run($item));
 *
 * // Each operation is rate limited, but they run in parallel
 * @example
 * // Sequential fallback when Spatie Async not available:
 * class FallbackOperation extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $data): array
 *     {
 *         return processData($data);
 *     }
 * }
 *
 * // Usage (works even if Spatie Async package not installed):
 * $items = ['data1', 'data2', 'data3'];
 * $results = AsParallel::map($items, fn ($item) => FallbackOperation::run($item));
 *
 * // Falls back to sequential execution if Spatie Async not available
 * // Results are still returned in same order
 * @example
 * // Parallel with error handling using Spatie Async:
 * class RiskyParallelOperation extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $data): array
 *     {
 *         // Operation that might fail
 *         return processData($data);
 *     }
 * }
 *
 * // Usage with Spatie Async error handling:
 * $items = ['data1', 'data2', 'data3'];
 * $pool = \Spatie\Async\Pool::create();
 * $results = [];
 * $errors = [];
 *
 * foreach ($items as $index => $item) {
 *     $pool->add(function () use ($item) {
 *         return RiskyParallelOperation::run($item);
 *     })->then(function ($output) use (&$results, $index) {
 *         $results[$index] = $output;
 *     })->catch(function (\Throwable $exception) use (&$errors, $index) {
 *         $errors[$index] = $exception;
 *         \Log::error("Failed to process item {$index}", ['error' => $exception->getMessage()]);
 *     });
 * }
 *
 * $pool->wait();
 *
 * // $results contains successful results
 * // $errors contains exceptions for failed items
 * @example
 * // Parallel with timeout using Spatie Async:
 * class TimedParallelOperation extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $data): array
 *     {
 *         // Long-running operation
 *         return processData($data);
 *     }
 * }
 *
 * // Usage with timeout:
 * $items = ['data1', 'data2', 'data3'];
 * $pool = \Spatie\Async\Pool::create()
 *     ->timeout(30); // 30 second timeout per process
 *
 * foreach ($items as $index => $item) {
 *     $pool->add(function () use ($item) {
 *         return TimedParallelOperation::run($item);
 *     })->then(function ($output) use (&$results, $index) {
 *         $results[$index] = $output;
 *     })->timeout(function () use ($index) {
 *         \Log::warning("Process {$index} timed out");
 *     });
 * }
 *
 * $pool->wait();
 * @example
 * // Parallel with concurrency limit:
 * class LimitedParallelOperation extends Actions
 * {
 *     use AsParallel;
 *
 *     public function handle(string $data): array
 *     {
 *         return processData($data);
 *     }
 * }
 *
 * // Usage with concurrency limit:
 * $items = range(1, 100);
 * $pool = \Spatie\Async\Pool::create()
 *     ->concurrency(10); // Max 10 processes at once
 *
 * $results = AsParallel::map($items, fn ($item) => LimitedParallelOperation::run($item));
 *
 * // Only 10 processes run simultaneously, others wait
 */
trait AsParallel
{
    /**
     * Execute multiple callbacks in parallel.
     * Uses Spatie Async package (https://github.com/spatie/async) when available.
     * Falls back to sequential execution if Spatie Async not available.
     *
     * @param  array<int, callable>  $callbacks  Array of callables to execute
     * @return array Results from each callback in same order
     */
    public static function run(array $callbacks): array
    {
        // Check if Spatie Async package is available
        if (class_exists(Pool::class)) {
            $pool = Pool::create();
            $results = [];
            $index = 0;

            foreach ($callbacks as $callback) {
                $currentIndex = $index++;
                $pool->add(function () use ($callback) {
                    return $callback();
                })->then(function ($output) use (&$results, $currentIndex) {
                    $results[$currentIndex] = $output;
                })->catch(function (\Throwable $exception) use (&$results, $currentIndex) {
                    // Store exception in results to maintain order
                    $results[$currentIndex] = $exception;
                });
            }

            $pool->wait();

            // Re-throw any exceptions that occurred
            foreach ($results as $result) {
                if ($result instanceof \Throwable) {
                    throw $result;
                }
            }

            // Return results in order (ksort to ensure order is maintained)
            ksort($results);

            return array_values($results);
        }

        // Fallback to sequential execution if Spatie Async not available
        return array_map(fn ($callback) => $callback(), $callbacks);
    }

    /**
     * Map over items and execute callback in parallel for each item.
     * Convenience method that wraps items in callbacks and calls run().
     *
     * @param  array  $items  Items to map over
     * @param  callable  $callback  Callback to execute for each item
     * @return array Results from each callback in same order as items
     */
    public static function map(array $items, callable $callback): array
    {
        $callbacks = array_map(fn ($item) => fn () => $callback($item), $items);

        return static::run($callbacks);
    }
}
