<?php

namespace App\Actions\Concerns;

/**
 * Provides lifecycle hooks for action execution.
 *
 * This trait is a marker that enables automatic lifecycle hooks via LifecycleDecorator.
 * When an action uses AsLifecycle, LifecycleDesignPattern recognizes it and
 * ActionManager wraps the action with LifecycleDecorator.
 *
 * How it works:
 * 1. Action uses AsLifecycle trait (marker)
 * 2. LifecycleDesignPattern recognizes the trait
 * 3. ActionManager wraps action with LifecycleDecorator
 * 4. When handle() is called, the decorator invokes lifecycle hooks:
 *    - beforeHandle() - Before execution
 *    - onValidation() - After validation (if applicable)
 *    - onAuthorized() - After authorization (if applicable)
 *    - handle() - Main execution
 *    - afterHandle() - After successful execution
 *    - onSuccess() - On success
 *    - onError() - On exception
 *    - onRetry() - Before retry (if applicable)
 *    - onTimeout() - On timeout (if applicable)
 *    - onCancelled() - On cancellation (if applicable)
 *    - afterExecution() - Always called at the end
 *
 * Benefits:
 * - Comprehensive lifecycle hooks for action execution
 * - Automatic hook invocation in correct order
 * - Error handling with lifecycle hooks
 * - Success/failure tracking
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - Flexible - only implement the hooks you need
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * LifecycleDecorator, which automatically wraps actions and adds lifecycle functionality.
 * This follows the same pattern as AsLogger, AsMetrics, AsLock, and other
 * decorator-based concerns.
 *
 * Available Lifecycle Hooks:
 * - beforeHandle(...$arguments) - Called before handle() executes
 * - onValidation(...$arguments) - Called after validation passes
 * - onAuthorized(...$arguments) - Called after authorization check passes
 * - afterHandle($result, ...$arguments) - Called after handle() succeeds
 * - onSuccess($result, ...$arguments) - Called when handle() succeeds
 * - onError(\Throwable $exception, ...$arguments) - Called when handle() throws
 * - onRetry(int $attempt, \Throwable $exception, ...$arguments) - Called before retry
 * - onTimeout(...$arguments) - Called if execution times out
 * - onCancelled(...$arguments) - Called if execution is cancelled
 * - afterExecution($result = null, ?\Throwable $exception = null, ...$arguments) - Always called
 *
 * @example
 * // ============================================
 * // Example 1: Basic Lifecycle Hooks
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Main logic
 *         $order->process();
 *         return $order;
 *     }
 *
 *     protected function beforeHandle(Order $order): void
 *     {
 *         // Prepare data, validate, etc.
 *         logger()->info('Processing order', ['order_id' => $order->id]);
 *     }
 *
 *     protected function onSuccess(Order $order, Order $result): void
 *     {
 *         // Success-specific logic
 *         event(new OrderProcessed($result));
 *     }
 *
 *     protected function onError(\Throwable $exception, Order $order): void
 *     {
 *         // Error handling
 *         logger()->error('Order processing failed', [
 *             'order_id' => $order->id,
 *             'error' => $exception->getMessage(),
 *         ]);
 *     }
 *
 *     protected function afterExecution(Order $result = null, ?\Throwable $exception = null, Order $order): void
 *     {
 *         // Final cleanup
 *         logger()->info('Order processing completed', ['order_id' => $order->id]);
 *     }
 * }
 *
 * // Lifecycle hooks are automatically called in order
 * @example
 * // ============================================
 * // Example 2: Validation Hook
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 *
 *     protected function beforeHandle(array $data): void
 *     {
 *         // Initial setup
 *     }
 *
 *     protected function onValidation(array $data): void
 *     {
 *         // Called after validation passes (if using AsValidated)
 *         // Additional validation logic
 *     }
 *
 *     protected function onSuccess(User $user, array $data): void
 *     {
 *         // Send welcome email
 *         Mail::to($user)->send(new WelcomeEmail);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Authorization Hook
 * // ============================================
 * class DeleteResource extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(Resource $resource): void
 *     {
 *         $resource->delete();
 *     }
 *
 *     protected function onAuthorized(Resource $resource): void
 *     {
 *         // Called after authorization check passes (if using AsAuthorized)
 *         // Log the action
 *         ActivityLog::create([
 *             'action' => 'delete',
 *             'resource_id' => $resource->id,
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Retry Hook
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsLifecycle;
 *     use AsRetry;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         return Http::get($endpoint)->json();
 *     }
 *
 *     protected function onRetry(int $attempt, \Throwable $exception, string $endpoint): void
 *     {
 *         // Called before each retry attempt
 *         logger()->warning('Retrying API call', [
 *             'attempt' => $attempt,
 *             'endpoint' => $endpoint,
 *             'error' => $exception->getMessage(),
 *         ]);
 *     }
 *
 *     protected function onError(\Throwable $exception, string $endpoint): void
 *     {
 *         // Called after all retries fail
 *         Notification::route('mail', 'admin@example.com')
 *             ->notify(new ApiCallFailed($endpoint, $exception));
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Timeout Hook
 * // ============================================
 * class LongRunningTask extends Actions
 * {
 *     use AsLifecycle;
 *     use AsTimeout;
 *
 *     public function handle(): void
 *     {
 *         // Long-running operation
 *         sleep(60);
 *     }
 *
 *     protected function onTimeout(): void
 *     {
 *         // Called if execution times out
 *         logger()->error('Task timed out');
 *         // Cleanup resources
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Cancellation Hook
 * // ============================================
 * class ProcessQueue extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(array $items): void
 *     {
 *         foreach ($items as $item) {
 *             // Check for cancellation
 *             if ($this->isCancelled()) {
 *                 $this->onCancelled($items);
 *                 return;
 *             }
 *             // Process item
 *         }
 *     }
 *
 *     protected function onCancelled(array $items): void
 *     {
 *         // Called if execution is cancelled
 *         logger()->info('Processing cancelled', ['items_remaining' => count($items)]);
 *         // Save progress, cleanup, etc.
 *     }
 *
 *     protected function isCancelled(): bool
 *     {
 *         // Check cancellation flag
 *         return false;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Combining with Other Decorators
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsLifecycle;
 *     use AsTransaction;
 *     use AsLogger;
 *     use AsLock;
 *
 *     public function handle(Payment $payment): void
 *     {
 *         // Process payment
 *     }
 *
 *     protected function beforeHandle(Payment $payment): void
 *     {
 *         // Prepare payment processing
 *     }
 *
 *     protected function onSuccess(Payment $payment, $result): void
 *     {
 *         // Send confirmation
 *         event(new PaymentProcessed($payment));
 *     }
 * }
 *
 * // All decorators work together:
 * // - LifecycleDecorator provides hooks
 * // - TransactionDecorator ensures database consistency
 * // - LoggerDecorator tracks execution
 * // - LockDecorator prevents concurrent processing
 * @example
 * // ============================================
 * // Example 8: Database Transaction Lifecycle
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsLifecycle;
 *     use AsTransaction;
 *
 *     public function handle(array $data): Order
 *     {
 *         return Order::create($data);
 *     }
 *
 *     protected function beforeHandle(array $data): void
 *     {
 *         // Validate inventory before transaction starts
 *         $this->validateInventory($data['items']);
 *     }
 *
 *     protected function onSuccess(Order $order, array $data): void
 *     {
 *         // Called after transaction commits
 *         event(new OrderCreated($order));
 *     }
 *
 *     protected function onError(\Throwable $exception, array $data): void
 *     {
 *         // Called if transaction rolls back
 *         logger()->error('Order creation failed', ['data' => $data]);
 *     }
 *
 *     protected function validateInventory(array $items): void
 *     {
 *         // Validation logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Background Job Lifecycle
 * // ============================================
 * class ProcessEmailQueue extends Actions implements \Illuminate\Contracts\Queue\ShouldQueue
 * {
 *     use AsLifecycle;
 *
 *     public function handle(Email $email): void
 *     {
 *         // Send email
 *         Mail::send($email);
 *     }
 *
 *     protected function beforeHandle(Email $email): void
 *     {
 *         // Mark email as processing
 *         $email->update(['status' => 'processing']);
 *     }
 *
 *     protected function onSuccess(Email $email, $result): void
 *     {
 *         // Mark email as sent
 *         $email->update(['status' => 'sent', 'sent_at' => now()]);
 *     }
 *
 *     protected function onError(\Throwable $exception, Email $email): void
 *     {
 *         // Mark email as failed
 *         $email->update([
 *             'status' => 'failed',
 *             'error' => $exception->getMessage(),
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: API Request Lifecycle
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(string $endpoint, array $data): array
 *     {
 *         return Http::post($endpoint, $data)->json();
 *     }
 *
 *     protected function beforeHandle(string $endpoint, array $data): void
 *     {
 *         // Log request
 *         logger()->info('API request', ['endpoint' => $endpoint]);
 *     }
 *
 *     protected function onSuccess(array $result, string $endpoint, array $data): void
 *     {
 *         // Cache successful responses
 *         Cache::put("api:{$endpoint}", $result, 3600);
 *     }
 *
 *     protected function onError(\Throwable $exception, string $endpoint, array $data): void
 *     {
 *         // Log error and notify
 *         logger()->error('API request failed', [
 *             'endpoint' => $endpoint,
 *             'error' => $exception->getMessage(),
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 11: File Processing Lifecycle
 * // ============================================
 * class ProcessUploadedFile extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Process file
 *         return ['processed' => true];
 *     }
 *
 *     protected function beforeHandle(string $filePath): void
 *     {
 *         // Validate file exists
 *         if (! file_exists($filePath)) {
 *             throw new \RuntimeException("File not found: {$filePath}");
 *         }
 *     }
 *
 *     protected function onSuccess(array $result, string $filePath): void
 *     {
 *         // Move file to processed directory
 *         Storage::move($filePath, "processed/{$filePath}");
 *     }
 *
 *     protected function onError(\Throwable $exception, string $filePath): void
 *     {
 *         // Move file to failed directory
 *         Storage::move($filePath, "failed/{$filePath}");
 *     }
 *
 *     protected function afterExecution($result = null, ?\Throwable $exception = null, string $filePath): void
 *     {
 *         // Always cleanup temp files
 *         $this->cleanupTempFiles($filePath);
 *     }
 *
 *     protected function cleanupTempFiles(string $filePath): void
 *     {
 *         // Cleanup logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Cache Operations Lifecycle
 * // ============================================
 * class UpdateCache extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(string $key, $value): void
 *     {
 *         Cache::put($key, $value);
 *     }
 *
 *     protected function beforeHandle(string $key, $value): void
 *     {
 *         // Invalidate related caches
 *         $this->invalidateRelated($key);
 *     }
 *
 *     protected function onSuccess($result, string $key, $value): void
 *     {
 *         // Log cache update
 *         logger()->debug('Cache updated', ['key' => $key]);
 *     }
 *
 *     protected function invalidateRelated(string $key): void
 *     {
 *         // Invalidation logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Notification Lifecycle
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         Notification::send($user, new AppNotification($message));
 *     }
 *
 *     protected function beforeHandle(User $user, string $message): void
 *     {
 *         // Check if user wants notifications
 *         if (! $user->notifications_enabled) {
 *             throw new \RuntimeException('User has notifications disabled');
 *         }
 *     }
 *
 *     protected function onSuccess($result, User $user, string $message): void
 *     {
 *         // Track notification sent
 *         NotificationLog::create([
 *             'user_id' => $user->id,
 *             'message' => $message,
 *             'sent_at' => now(),
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Search Index Lifecycle
 * // ============================================
 * class UpdateSearchIndex extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(Product $product): void
 *     {
 *         Search::index('products')->update($product);
 *     }
 *
 *     protected function beforeHandle(Product $product): void
 *     {
 *         // Prepare search data
 *         $this->prepareSearchData($product);
 *     }
 *
 *     protected function onSuccess($result, Product $product): void
 *     {
 *         // Log index update
 *         logger()->info('Search index updated', ['product_id' => $product->id]);
 *     }
 *
 *     protected function onError(\Throwable $exception, Product $product): void
 *     {
 *         // Queue for retry
 *         UpdateSearchIndex::dispatch($product);
 *     }
 *
 *     protected function prepareSearchData(Product $product): void
 *     {
 *         // Preparation logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Audit Trail Lifecycle
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user;
 *     }
 *
 *     protected function beforeHandle(User $user, array $data): void
 *     {
 *         // Store original data for audit
 *         $this->originalData = $user->getOriginal();
 *     }
 *
 *     protected function onSuccess(User $user, User $result, array $data): void
 *     {
 *         // Create audit log
 *         AuditLog::create([
 *             'user_id' => $user->id,
 *             'action' => 'update',
 *             'changes' => array_diff_assoc($data, $this->originalData),
 *         ]);
 *     }
 *
 *     protected function afterExecution($result = null, ?\Throwable $exception = null, User $user, array $data): void
 *     {
 *         // Always clear original data
 *         unset($this->originalData);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Rate Limiting Lifecycle
 * // ============================================
 * class ProcessRequest extends Actions
 * {
 *     use AsLifecycle;
 *     use AsThrottle;
 *
 *     public function handle(Request $request): Response
 *     {
 *         return new Response('Success');
 *     }
 *
 *     protected function beforeHandle(Request $request): void
 *     {
 *         // Check rate limit before processing
 *         $this->checkRateLimit($request);
 *     }
 *
 *     protected function onError(\Throwable $exception, Request $request): void
 *     {
 *         // Log rate limit errors
 *         if ($exception instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
 *             logger()->warning('Rate limit exceeded', ['ip' => $request->ip()]);
 *         }
 *     }
 *
 *     protected function checkRateLimit(Request $request): void
 *     {
 *         // Rate limit check
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Multi-Step Process Lifecycle
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $this->validateOrder($order);
 *         $this->reserveInventory($order);
 *         $this->calculateShipping($order);
 *         $this->processPayment($order);
 *         return $order;
 *     }
 *
 *     protected function beforeHandle(Order $order): void
 *     {
 *         // Initialize processing
 *         $order->update(['status' => 'processing']);
 *     }
 *
 *     protected function onSuccess(Order $order, Order $result): void
 *     {
 *         // Mark as completed
 *         $order->update(['status' => 'completed', 'completed_at' => now()]);
 *         event(new OrderCompleted($order));
 *     }
 *
 *     protected function onError(\Throwable $exception, Order $order): void
 *     {
 *         // Mark as failed
 *         $order->update(['status' => 'failed', 'error' => $exception->getMessage()]);
 *     }
 *
 *     protected function validateOrder(Order $order): void {}
 *     protected function reserveInventory(Order $order): void {}
 *     protected function calculateShipping(Order $order): void {}
 *     protected function processPayment(Order $order): void {}
 * }
 * @example
 * // ============================================
 * // Example 18: Conditional Lifecycle Hooks
 * // ============================================
 * class ConditionalAction extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle($data): mixed
 *     {
 *         return $data;
 *     }
 *
 *     protected function beforeHandle($data): void
 *     {
 *         // Only run in certain environments
 *         if (! app()->environment('production')) {
 *             logger()->debug('Pre-execution', ['data' => $data]);
 *         }
 *     }
 *
 *     protected function onSuccess($result, $data): void
 *     {
 *         // Only send notifications in production
 *         if (app()->environment('production')) {
 *             Notification::send(new ActionCompleted($result));
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Performance Monitoring Lifecycle
 * // ============================================
 * class MonitoredAction extends Actions
 * {
 *     use AsLifecycle;
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Expensive operation
 *         sleep(2);
 *     }
 *
 *     protected function beforeHandle(): void
 *     {
 *         $this->startTime = microtime(true);
 *     }
 *
 *     protected function afterExecution($result = null, ?\Throwable $exception = null): void
 *     {
 *         $duration = microtime(true) - $this->startTime;
 *
 *         if ($duration > 1.0) {
 *             logger()->warning('Slow action execution', [
 *                 'duration' => $duration,
 *                 'action' => get_class($this),
 *             ]);
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Resource Cleanup Lifecycle
 * // ============================================
 * class ProcessWithResources extends Actions
 * {
 *     use AsLifecycle;
 *
 *     protected array $resources = [];
 *
 *     public function handle(): void
 *     {
 *         // Acquire resources
 *         $this->resources[] = $this->acquireResource();
 *         // Use resources
 *     }
 *
 *     protected function beforeHandle(): void
 *     {
 *         // Initialize resource tracking
 *         $this->resources = [];
 *     }
 *
 *     protected function afterExecution($result = null, ?\Throwable $exception = null): void
 *     {
 *         // Always release resources, even on error
 *         foreach ($this->resources as $resource) {
 *             $this->releaseResource($resource);
 *         }
 *         $this->resources = [];
 *     }
 *
 *     protected function acquireResource(): mixed
 *     {
 *         // Acquire logic
 *         return null;
 *     }
 *
 *     protected function releaseResource($resource): void
 *     {
 *         // Release logic
 *     }
 * }
 */
trait AsLifecycle
{
    // This is a marker trait - the actual lifecycle functionality is handled by LifecycleDecorator
    // via the LifecycleDesignPattern and ActionManager
}
