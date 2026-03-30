<?php

namespace App\Actions\Concerns;

/**
 * Debounces rapid calls to actions, executing only after a quiet period.
 *
 * This trait is a marker that enables automatic debouncing via DebounceDecorator.
 * When an action uses AsDebounced, DebounceDesignPattern recognizes it and
 * ActionManager wraps the action with DebounceDecorator.
 *
 * How it works:
 * 1. Action uses AsDebounced trait (marker)
 * 2. DebounceDesignPattern recognizes the trait
 * 3. ActionManager wraps action with DebounceDecorator
 * 4. When handle() is called rapidly, the decorator:
 *    - Stores the latest arguments in cache
 *    - Waits for a quiet period (debounce delay)
 *    - Executes only the last call after the delay
 *    - Cancels previous pending executions
 *
 * Features:
 * - Debounces rapid successive calls
 * - Executes only the last call after quiet period
 * - Configurable debounce delay
 * - Custom debounce keys per action/arguments
 * - Prevents duplicate expensive operations
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Benefits:
 * - Reduces unnecessary executions
 * - Improves performance for expensive operations
 * - Prevents race conditions from rapid calls
 * - Configurable per action
 * - No trait conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - Search index updates
 * - Cache invalidation
 * - API rate limiting
 * - File system operations
 * - Database writes
 * - Notification sending
 * - Analytics tracking
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * DebounceDecorator, which automatically wraps actions and adds debouncing.
 * This follows the same pattern as AsLock, AsLogger, AsMetrics, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getDebounceDelay()` method to customize delay (default: 1000ms)
 * - Set `getDebounceKey(...$arguments)` method to customize debounce key
 * - Set `debounceDelay` property to customize delay
 * - Implement `executeDebounced()` for custom execution logic
 *
 * @example
 * // ============================================
 * // Example 1: Basic Debouncing
 * // ============================================
 * class UpdateSearchIndex extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(Product $product): void
 *     {
 *         // Expensive index update
 *         Search::index('products')->update($product);
 *     }
 *
 *     protected function getDebounceKey(Product $product): string
 *     {
 *         return 'index:'.$product->id;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 5000; // 5 seconds
 *     }
 * }
 *
 * // Rapid calls are debounced - only last call executes after 5 seconds
 * UpdateSearchIndex::run($product); // Called immediately
 * UpdateSearchIndex::run($product); // Cancels previous, waits 5s
 * UpdateSearchIndex::run($product); // Cancels previous, waits 5s
 * // Only the last call executes after 5 seconds
 * @example
 * // ============================================
 * // Example 2: Search Input Debouncing
 * // ============================================
 * class SearchProducts extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $query): array
 *     {
 *         // Expensive search operation
 *         return Product::search($query)->get();
 *     }
 *
 *     protected function getDebounceKey(string $query): string
 *     {
 *         return 'search:'.md5($query);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 300; // 300ms - fast for user experience
 *     }
 * }
 *
 * // User types "laptop" quickly:
 * // - "l" -> debounced
 * // - "la" -> cancels "l", debounced
 * // - "lap" -> cancels "la", debounced
 * // - "lapt" -> cancels "lap", debounced
 * // - "lapto" -> cancels "lapt", debounced
 * // - "laptop" -> cancels "lapto", executes after 300ms
 * @example
 * // ============================================
 * // Example 3: Cache Invalidation Debouncing
 * // ============================================
 * class InvalidateCache extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $cacheKey): void
 *     {
 *         // Expensive cache invalidation
 *         Cache::forget($cacheKey);
 *         Cache::tags($cacheKey)->flush();
 *     }
 *
 *     protected function getDebounceKey(string $cacheKey): string
 *     {
 *         return 'cache:invalidate:'.$cacheKey;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 2000; // 2 seconds
 *     }
 * }
 *
 * // Multiple cache invalidations are batched
 * @example
 * // ============================================
 * // Example 4: API Rate Limiting
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $endpoint, array $data): array
 *     {
 *         // External API call
 *         return Http::post($endpoint, $data)->json();
 *     }
 *
 *     protected function getDebounceKey(string $endpoint): string
 *     {
 *         return 'api:call:'.md5($endpoint);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 1000; // 1 second between calls
 *     }
 * }
 *
 * // Prevents rapid API calls to the same endpoint
 * @example
 * // ============================================
 * // Example 5: File System Operations
 * // ============================================
 * class WriteToFile extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $filePath, string $content): void
 *     {
 *         // File write operation
 *         file_put_contents($filePath, $content);
 *     }
 *
 *     protected function getDebounceKey(string $filePath): string
 *     {
 *         return 'file:write:'.md5($filePath);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 500; // 500ms
 *     }
 * }
 *
 * // Prevents excessive file writes
 * @example
 * // ============================================
 * // Example 6: Database Writes
 * // ============================================
 * class UpdateUserActivity extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(User $user, string $activity): void
 *     {
 *         // Database write
 *         ActivityLog::create([
 *             'user_id' => $user->id,
 *             'activity' => $activity,
 *         ]);
 *     }
 *
 *     protected function getDebounceKey(User $user): string
 *     {
 *         return 'activity:'.$user->id;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 3000; // 3 seconds - batch user activities
 *     }
 * }
 *
 * // Batches user activity updates
 * @example
 * // ============================================
 * // Example 7: Notification Sending
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         // Send notification
 *         $user->notify(new MessageNotification($message));
 *     }
 *
 *     protected function getDebounceKey(User $user, string $message): string
 *     {
 *         return 'notification:'.$user->id;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 2000; // 2 seconds - prevent notification spam
 *     }
 * }
 *
 * // Prevents sending duplicate notifications rapidly
 * @example
 * // ============================================
 * // Example 8: Analytics Tracking
 * // ============================================
 * class TrackEvent extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $event, array $properties): void
 *     {
 *         // Analytics tracking
 *         Analytics::track($event, $properties);
 *     }
 *
 *     protected function getDebounceKey(string $event): string
 *     {
 *         return 'analytics:'.$event;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 1000; // 1 second
 *     }
 * }
 *
 * // Batches analytics events
 * @example
 * // ============================================
 * // Example 9: Combining with Other Decorators
 * // ============================================
 * class ProcessWithDebounce extends Actions
 * {
 *     use AsDebounced;
 *     use AsTransaction;
 *     use AsLogger;
 *
 *     public function handle(Data $data): Result
 *     {
 *         // Process data
 *         return new Result($data);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 2000;
 *     }
 * }
 *
 * // All decorators work together:
 * // - DebounceDecorator debounces rapid calls
 * // - TransactionDecorator wraps in transaction
 * // - LoggerDecorator logs execution
 * @example
 * // ============================================
 * // Example 10: Custom Execution Logic
 * // ============================================
 * class CustomDebouncedAction extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $data): void
 *     {
 *         // Process data
 *     }
 *
 *     protected function executeDebounced(string $key, int $delay, array $arguments): void
 *     {
 *         // Custom execution - e.g., dispatch to queue
 *         ProcessDebouncedJob::dispatch($arguments)
 *             ->delay(now()->addMilliseconds($delay));
 *     }
 * }
 * @example
 * // ============================================
 * // Example 11: Property-Based Configuration
 * // ============================================
 * class QuickDebounce extends Actions
 * {
 *     use AsDebounced;
 *
 *     protected int $debounceDelay = 500; // 500ms
 *
 *     public function handle(): void
 *     {
 *         // Quick operation
 *     }
 * }
 *
 * // Properties are automatically detected
 * @example
 * // ============================================
 * // Example 12: User Input Debouncing
 * // ============================================
 * class SaveUserInput extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(User $user, string $input): void
 *     {
 *         // Save user input (e.g., draft)
 *         $user->update(['draft' => $input]);
 *     }
 *
 *     protected function getDebounceKey(User $user): string
 *     {
 *         return 'input:save:'.$user->id;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 1000; // Save draft after 1 second of no typing
 *     }
 * }
 *
 * // Auto-save drafts after user stops typing
 * @example
 * // ============================================
 * // Example 13: Real-time Updates
 * // ============================================
 * class UpdateLiveData extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $data): void
 *     {
 *         // Update live data
 *         broadcast(new DataUpdated($data));
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 200; // 200ms for real-time feel
 *     }
 * }
 *
 * // Debounces rapid real-time updates
 * @example
 * // ============================================
 * // Example 14: Image Processing
 * // ============================================
 * class ProcessImage extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $imagePath): void
 *     {
 *         // Expensive image processing
 *         Image::make($imagePath)->resize(800, 600)->save();
 *     }
 *
 *     protected function getDebounceKey(string $imagePath): string
 *     {
 *         return 'image:process:'.md5($imagePath);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 3000; // 3 seconds
 *     }
 * }
 *
 * // Prevents duplicate image processing
 * @example
 * // ============================================
 * // Example 15: Email Sending
 * // ============================================
 * class SendEmail extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(User $user, string $subject, string $body): void
 *     {
 *         // Send email
 *         Mail::to($user)->send(new CustomMail($subject, $body));
 *     }
 *
 *     protected function getDebounceKey(User $user, string $subject): string
 *     {
 *         return 'email:'.$user->id.':'.md5($subject);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 5000; // 5 seconds - prevent duplicate emails
 *     }
 * }
 *
 * // Prevents sending duplicate emails rapidly
 * @example
 * // ============================================
 * // Example 16: Configuration Updates
 * // ============================================
 * class UpdateConfig extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $key, mixed $value): void
 *     {
 *         // Update configuration
 *         config([$key => $value]);
 *         Cache::forget('config');
 *     }
 *
 *     protected function getDebounceKey(string $key): string
 *     {
 *         return 'config:update:'.$key;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 1000;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Webhook Calls
 * // ============================================
 * class SendWebhook extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(string $url, array $payload): void
 *     {
 *         // Send webhook
 *         Http::post($url, $payload);
 *     }
 *
 *     protected function getDebounceKey(string $url): string
 *     {
 *         return 'webhook:'.md5($url);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 2000; // 2 seconds
 *     }
 * }
 *
 * // Prevents rapid webhook calls
 * @example
 * // ============================================
 * // Example 18: Log Aggregation
 * // ============================================
 * class AggregateLogs extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(array $logEntries): void
 *     {
 *         // Aggregate and store logs
 *         LogAggregate::create(['entries' => $logEntries]);
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 5000; // 5 seconds - batch log entries
 *     }
 * }
 *
 * // Batches log entries before writing
 * @example
 * // ============================================
 * // Example 19: Price Updates
 * // ============================================
 * class UpdatePrice extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(Product $product, float $price): void
 *     {
 *         // Update product price
 *         $product->update(['price' => $price]);
 *     }
 *
 *     protected function getDebounceKey(Product $product): string
 *     {
 *         return 'price:update:'.$product->id;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 2000; // 2 seconds
 *     }
 * }
 *
 * // Prevents rapid price updates
 * @example
 * // ============================================
 * // Example 20: Status Updates
 * // ============================================
 * class UpdateStatus extends Actions
 * {
 *     use AsDebounced;
 *
 *     public function handle(Model $model, string $status): void
 *     {
 *         // Update model status
 *         $model->update(['status' => $status]);
 *     }
 *
 *     protected function getDebounceKey(Model $model): string
 *     {
 *         return 'status:update:'.get_class($model).':'.$model->id;
 *     }
 *
 *     protected function getDebounceDelay(): int
 *     {
 *         return 1000;
 *     }
 * }
 *
 * // Debounces rapid status changes
 */
trait AsDebounced
{
    // This is a marker trait - the actual debouncing functionality is handled by DebounceDecorator
    // via the DebounceDesignPattern and ActionManager
}
