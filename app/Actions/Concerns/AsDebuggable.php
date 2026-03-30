<?php

namespace App\Actions\Concerns;

/**
 * Provides enhanced debugging capabilities in development mode.
 *
 * This trait is a marker that enables automatic debugging via DebuggableDecorator.
 * When an action uses AsDebuggable, DebuggableDesignPattern recognizes it and
 * ActionManager wraps the action with DebuggableDecorator.
 *
 * How it works:
 * 1. Action uses AsDebuggable trait (marker)
 * 2. DebuggableDesignPattern recognizes the trait
 * 3. ActionManager wraps action with DebuggableDecorator
 * 4. When handle() is called, the decorator:
 *    - Checks if debugging should be enabled (local/testing only)
 *    - Dumps input parameters and metadata
 *    - Executes the action
 *    - Dumps return value and performance metrics
 *    - On exception, dumps exception details with stack trace
 *    - Returns the result (or re-throws exception)
 *
 * Features:
 * - Automatic debugging in local/testing environments
 * - Input parameter dumping
 * - Return value inspection
 * - Performance metrics (memory usage, execution duration)
 * - Exception debugging with stack traces
 * - Configurable via config file
 * - Zero overhead in production
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Benefits:
 * - Faster debugging during development
 * - Clear visibility into action execution
 * - Performance profiling built-in
 * - Exception details with context
 * - No production overhead
 * - No trait conflicts
 * - Composable with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * DebuggableDecorator, which automatically wraps actions and adds debugging.
 * This follows the same pattern as AsLock, AsLogger, AsMetrics, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `config('actions.debug.enabled', true)` to enable/disable globally
 * - Implement `shouldDebug()` method to customize debug conditions
 * - Only active in 'local' and 'testing' environments by default
 *
 * @example
 * // ============================================
 * // Example 1: Basic Debugging
 * // ============================================
 * class ComplexAction extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(array $data): array
 *     {
 *         // Action logic
 *         return ['processed' => true, 'data' => $data];
 *     }
 * }
 *
 * // In development, automatically dumps:
 * // - Input parameters
 * // - Return values
 * // - Memory usage
 * // - Execution duration
 * ComplexAction::run(['key' => 'value']);
 * @example
 * // ============================================
 * // Example 2: Debugging with Custom Condition
 * // ============================================
 * class ConditionalDebugAction extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(string $input): string
 *     {
 *         return strtoupper($input);
 *     }
 *
 *     protected function shouldDebug(): bool
 *     {
 *         // Only debug when APP_DEBUG is true
 *         return config('app.debug') && app()->environment('local');
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Debugging API Actions
 * // ============================================
 * class ProcessApiRequest extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(Request $request): JsonResponse
 *     {
 *         // Process API request
 *         return response()->json(['status' => 'success']);
 *     }
 * }
 *
 * // In development, see full request/response flow
 * @example
 * // ============================================
 * // Example 4: Debugging Database Operations
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 * }
 *
 * // See input data and created user model in debug output
 * CreateUser::run(['name' => 'John', 'email' => 'john@example.com']);
 * @example
 * // ============================================
 * // Example 5: Debugging Exception Handling
 * // ============================================
 * class RiskyAction extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(int $value): int
 *     {
 *         if ($value < 0) {
 *             throw new \InvalidArgumentException('Value must be positive');
 *         }
 *         return $value * 2;
 *     }
 * }
 *
 * // Exception details automatically dumped:
 * // - Exception class
 * // - Error message
 * // - File and line number
 * // - Stack trace
 * // - Input arguments
 * try {
 *     RiskyAction::run(-1);
 * } catch (\Exception $e) {
 *     // Exception already dumped by decorator
 * }
 * @example
 * // ============================================
 * // Example 6: Debugging Performance
 * // ============================================
 * class SlowOperation extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(): array
 *     {
 *         // Simulate slow operation
 *         sleep(1);
 *         return ['completed' => true];
 *     }
 * }
 *
 * // Debug output shows:
 * // - Execution duration in milliseconds
 * // - Memory usage before/after
 * // - Peak memory usage
 * SlowOperation::run();
 * @example
 * // ============================================
 * // Example 7: Debugging with Other Decorators
 * // ============================================
 * class ComplexProcess extends Actions
 * {
 *     use AsDebuggable;
 *     use AsTransaction;
 *     use AsLogger;
 *
 *     public function handle(Data $data): Result
 *     {
 *         // Complex processing
 *         return new Result($data);
 *     }
 * }
 *
 * // All decorators work together:
 * // - DebuggableDecorator shows execution details
 * // - TransactionDecorator wraps in transaction
 * // - LoggerDecorator logs execution
 * @example
 * // ============================================
 * // Example 8: Debugging Queue Jobs
 * // ============================================
 * class ProcessJob extends Actions implements \Illuminate\Contracts\Queue\ShouldQueue
 * {
 *     use AsDebuggable;
 *
 *     public function handle(array $data): void
 *     {
 *         // Process job
 *     }
 * }
 *
 * // Debug output appears in queue worker logs in development
 * ProcessJob::dispatch(['key' => 'value']);
 * @example
 * // ============================================
 * // Example 9: Debugging Scheduled Tasks
 * // ============================================
 * class ScheduledTask extends Actions
 * {
 *     use AsDebuggable;
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Scheduled task logic
 *     }
 * }
 *
 * // Debug output in console when running scheduled tasks
 * @example
 * // ============================================
 * // Example 10: Debugging Commands
 * // ============================================
 * class ArtisanCommand extends Actions
 * {
 *     use AsDebuggable;
 *     use AsCommand;
 *
 *     public function handle(): void
 *     {
 *         // Command logic
 *     }
 * }
 *
 * // Debug output in console when running artisan commands
 * @example
 * // ============================================
 * // Example 11: Debugging with Large Data
 * // ============================================
 * class ProcessLargeDataset extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(array $largeArray): array
 *     {
 *         // Process large dataset
 *         return array_map(fn($item) => $item * 2, $largeArray);
 *     }
 * }
 *
 * // Debug output shows memory usage for large operations
 * ProcessLargeDataset::run(range(1, 10000));
 * @example
 * // ============================================
 * // Example 12: Debugging Nested Actions
 * // ============================================
 * class ParentAction extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(): void
 *     {
 *         // Call child action
 *         ChildAction::run(['data' => 'value']);
 *     }
 * }
 *
 * class ChildAction extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(array $data): void
 *     {
 *         // Child action logic
 *     }
 * }
 *
 * // Both actions show debug output independently
 * @example
 * // ============================================
 * // Example 13: Debugging with DTOs
 * // ============================================
 * class ProcessWithDTO extends Actions
 * {
 *     use AsDebuggable;
 *     use AsDTO;
 *
 *     public function handle(ProcessDTO $dto): Result
 *     {
 *         // Process with DTO
 *         return new Result($dto);
 *     }
 * }
 *
 * // Debug output shows both array input and DTO object
 * ProcessWithDTO::run(['key' => 'value']);
 * @example
 * // ============================================
 * // Example 14: Debugging API Responses
 * // ============================================
 * class ApiAction extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(Request $request): JsonResponse
 *     {
 *         return response()->json([
 *             'data' => $request->all(),
 *             'status' => 'success',
 *         ]);
 *     }
 * }
 *
 * // See request data and response in debug output
 * @example
 * // ============================================
 * // Example 15: Debugging File Operations
 * // ============================================
 * class ProcessFile extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Process file
 *         return ['processed' => true, 'path' => $filePath];
 *     }
 * }
 *
 * // Debug output shows file path and processing result
 * @example
 * // ============================================
 * // Example 16: Debugging Cache Operations
 * // ============================================
 * class CacheAction extends Actions
 * {
 *     use AsDebuggable;
 *     use AsCache;
 *
 *     public function handle(string $key): mixed
 *     {
 *         // Cache operation
 *         return Cache::get($key);
 *     }
 * }
 *
 * // Debug output shows cache key and retrieved value
 * @example
 * // ============================================
 * // Example 17: Debugging in Tests
 * // ============================================
 * class TestAction extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(string $input): string
 *     {
 *         return strtoupper($input);
 *     }
 * }
 *
 * // In tests, debug output helps verify action behavior
 * test('action transforms input', function () {
 *     $result = TestAction::run('hello');
 *     // Debug output shows input and output
 *     expect($result)->toBe('HELLO');
 * });
 * @example
 * // ============================================
 * // Example 18: Debugging Background Processing
 * // ============================================
 * class BackgroundProcess extends Actions
 * {
 *     use AsDebuggable;
 *
 *     public function handle(array $data): void
 *     {
 *         // Background processing
 *     }
 * }
 *
 * // Debug output in background worker logs
 * @example
 * // ============================================
 * // Example 19: Debugging with Metrics
 * // ============================================
 * class TrackedAction extends Actions
 * {
 *     use AsDebuggable;
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Action with metrics
 *     }
 * }
 *
 * // Combines debug output with metrics tracking
 * @example
 * // ============================================
 * // Example 20: Debugging Complex Workflows
 * // ============================================
 * class WorkflowAction extends Actions
 * {
 *     use AsDebuggable;
 *     use AsPipeline;
 *
 *     public function handle(Data $data): Result
 *     {
 *         return $this->pipeline()
 *             ->send($data)
 *             ->through([
 *                 Step1::class,
 *                 Step2::class,
 *                 Step3::class,
 *             ])
 *             ->then(fn($data) => new Result($data));
 *     }
 * }
 *
 * // Debug output shows each step in the pipeline
 */
trait AsDebuggable
{
    // This is a marker trait - the actual debugging functionality is handled by DebuggableDecorator
    // via the DebuggableDesignPattern and ActionManager
}
