<?php

namespace App\Actions\Concerns;

/**
 * Prevents concurrent execution using distributed locks.
 *
 * This trait is a marker that enables automatic locking via LockDecorator.
 * When an action uses AsLock, LockDesignPattern recognizes it and
 * ActionManager wraps the action with LockDecorator.
 *
 * How it works:
 * 1. Action uses AsLock trait (marker)
 * 2. LockDesignPattern recognizes the trait
 * 3. ActionManager wraps action with LockDecorator
 * 4. When handle() is called, the decorator:
 *    - Attempts to acquire a distributed lock
 *    - If lock cannot be acquired, throws RuntimeException
 *    - Executes the action within the lock
 *    - Automatically releases lock after execution (even on exceptions)
 *    - Returns the result (or re-throws exception)
 *
 * Benefits:
 * - Prevents race conditions in concurrent environments
 * - Works across multiple servers/processes (when using Redis/Memcached)
 * - Automatic lock release (even on exceptions)
 * - Configurable lock keys and timeouts
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * LockDecorator, which automatically wraps actions and adds locking.
 * This follows the same pattern as AsLogger, AsMetrics, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `getLockKey(...$arguments)` method to customize lock key
 * - Set `getLockTimeout()` method to customize lock timeout (default: 10 seconds)
 * - Set `lockTimeout` property to customize lock timeout
 *
 * @example
 * // ============================================
 * // Example 1: Basic Locking
 * // ============================================
 * class UpdateBalance extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(Account $account, float $amount): void
 *     {
 *         // Critical section - only one execution at a time
 *         $account->increment('balance', $amount);
 *     }
 * }
 *
 * // Usage:
 * UpdateBalance::run($account, 100.00);
 * // Prevents concurrent updates to the same account
 * @example
 * // ============================================
 * // Example 2: Custom Lock Key
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order - only one process per order
 *     }
 *
 *     // Customize lock key based on order ID
 *     protected function getLockKey(Order $order): string
 *     {
 *         return "order:{$order->id}:processing";
 *     }
 * }
 *
 * // Ensures each order is only processed once at a time
 * @example
 * // ============================================
 * // Example 3: Custom Lock Timeout
 * // ============================================
 * class LongRunningTask extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(): void
 *     {
 *         // Long-running operation
 *         sleep(30);
 *     }
 *
 *     // Increase timeout for long operations
 *     protected function getLockTimeout(): int
 *     {
 *         return 60; // 60 seconds
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Property-Based Configuration
 * // ============================================
 * class CriticalOperation extends Actions
 * {
 *     use AsLock;
 *
 *     protected int $lockTimeout = 30;
 *
 *     public function handle(): void
 *     {
 *         // Critical operation
 *     }
 * }
 *
 * // Properties are automatically detected and used
 * @example
 * // ============================================
 * // Example 5: User-Specific Locking
 * // ============================================
 * class UpdateUserProfile extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(User $user, array $data): void
 *     {
 *         // Update user profile
 *         $user->update($data);
 *     }
 *
 *     // Lock per user to prevent concurrent updates
 *     protected function getLockKey(User $user): string
 *     {
 *         return "user:{$user->id}:profile:update";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 5; // Quick operation, short timeout
 *     }
 * }
 *
 * // Prevents multiple simultaneous profile updates for the same user
 * @example
 * // ============================================
 * // Example 6: Resource-Specific Locking
 * // ============================================
 * class ReserveInventory extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(Product $product, int $quantity): bool
 *     {
 *         // Check and reserve inventory
 *         if ($product->stock >= $quantity) {
 *             $product->decrement('stock', $quantity);
 *             return true;
 *         }
 *         return false;
 *     }
 *
 *     // Lock per product to prevent overselling
 *     protected function getLockKey(Product $product): string
 *     {
 *         return "product:{$product->id}:inventory";
 *     }
 * }
 *
 * // Prevents race conditions when multiple users try to buy the same product
 * @example
 * // ============================================
 * // Example 7: Background Job Locking
 * // ============================================
 * class ProcessPayment extends Actions implements \Illuminate\Contracts\Queue\ShouldQueue
 * {
 *     use AsLock;
 *
 *     public function handle(Payment $payment): void
 *     {
 *         // Process payment
 *     }
 *
 *     protected function getLockKey(Payment $payment): string
 *     {
 *         return "payment:{$payment->id}:processing";
 *     }
 * }
 *
 * // Queue the job - locking prevents duplicate processing
 * ProcessPayment::dispatch($payment);
 * @example
 * // ============================================
 * // Example 8: Scheduled Task Locking
 * // ============================================
 * class GenerateDailyReport extends Actions
 * {
 *     use AsLock;
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Generate report
 *     }
 *
 *     // Use a fixed lock key for scheduled tasks
 *     protected function getLockKey(): string
 *     {
 *         return 'scheduled:daily:report';
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 300; // 5 minutes for report generation
 *     }
 * }
 *
 * // Prevents multiple instances of scheduled task from running
 * @example
 * // ============================================
 * // Example 9: Handling Lock Failures
 * // ============================================
 * class CriticalUpdate extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(Data $data): void
 *     {
 *         // Critical update
 *     }
 * }
 *
 * // Usage with error handling:
 * try {
 *     CriticalUpdate::run($data);
 * } catch (\RuntimeException $e) {
 *     if (str_contains($e->getMessage(), 'Could not acquire lock')) {
 *         // Another instance is already running
 *         logger()->warning('Skipping update - already in progress');
 *         return;
 *     }
 *     throw $e;
 * }
 * @example
 * // ============================================
 * // Example 10: Combining with Other Decorators
 * // ============================================
 * class ProcessTransaction extends Actions
 * {
 *     use AsLock;
 *     use AsTransaction;
 *     use AsLogger;
 *
 *     public function handle(Transaction $transaction): void
 *     {
 *         // Process transaction
 *     }
 *
 *     protected function getLockKey(Transaction $transaction): string
 *     {
 *         return "transaction:{$transaction->id}";
 *     }
 * }
 *
 * // All decorators work together:
 * // - LockDecorator prevents concurrent processing
 * // - TransactionDecorator ensures database consistency
 * // - LoggerDecorator tracks execution
 * @example
 * // ============================================
 * // Example 11: Multi-Argument Lock Keys
 * // ============================================
 * class TransferFunds extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(Account $from, Account $to, float $amount): void
 *     {
 *         // Transfer funds between accounts
 *     }
 *
 *     // Lock both accounts to prevent deadlocks
 *     protected function getLockKey(Account $from, Account $to): string
 *     {
 *         // Always lock in consistent order (by ID) to prevent deadlocks
 *         $ids = [$from->id, $to->id];
 *         sort($ids);
 *
 *         return "transfer:{$ids[0]}:{$ids[1]}";
 *     }
 * }
 *
 * // Prevents concurrent transfers between the same accounts
 * @example
 * // ============================================
 * // Example 12: Environment-Based Timeouts
 * // ============================================
 * class DataSync extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(): void
 *     {
 *         // Sync data
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         // Longer timeout in production for slower operations
 *         return app()->environment('production') ? 300 : 60;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Lock with Retry Logic
 * // ============================================
 * class ProcessWithRetry extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(): void
 *     {
 *         // Process
 *     }
 * }
 *
 * // Usage with retry:
 * $maxRetries = 3;
 * $retry = 0;
 *
 * while ($retry < $maxRetries) {
 *     try {
 *         ProcessWithRetry::run();
 *         break;
 *     } catch (\RuntimeException $e) {
 *         if (str_contains($e->getMessage(), 'Could not acquire lock')) {
 *             $retry++;
 *             if ($retry < $maxRetries) {
 *                 sleep(1); // Wait before retry
 *                 continue;
 *             }
 *         }
 *         throw $e;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Global Operation Locking
 * // ============================================
 * class SystemMaintenance extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(): void
 *     {
 *         // System maintenance
 *     }
 *
 *     // Use a global lock key
 *     protected function getLockKey(): string
 *     {
 *         return 'system:maintenance';
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 3600; // 1 hour
 *     }
 * }
 *
 * // Ensures only one maintenance operation runs at a time globally
 * @example
 * // ============================================
 * // Example 15: Cache Warming with Locking
 * // ============================================
 * class WarmCache extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(string $cacheKey): void
 *     {
 *         // Warm cache
 *     }
 *
 *     protected function getLockKey(string $cacheKey): string
 *     {
 *         return "cache:warm:{$cacheKey}";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 30; // Cache warming shouldn't take too long
 *     }
 * }
 *
 * // Prevents multiple processes from warming the same cache simultaneously
 * @example
 * // ============================================
 * // Example 16: File Processing with Locking
 * // ============================================
 * class ProcessFile extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(string $filePath): void
 *     {
 *         // Process file
 *     }
 *
 *     protected function getLockKey(string $filePath): string
 *     {
 *         // Lock based on file path to prevent duplicate processing
 *         return "file:process:".md5($filePath);
 *     }
 * }
 *
 * // Prevents the same file from being processed multiple times concurrently
 * @example
 * // ============================================
 * // Example 17: Batch Processing with Locking
 * // ============================================
 * class ProcessBatch extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(int $batchId): void
 *     {
 *         // Process batch
 *     }
 *
 *     protected function getLockKey(int $batchId): string
 *     {
 *         return "batch:{$batchId}:processing";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 600; // 10 minutes for large batches
 *     }
 * }
 *
 * // Prevents duplicate batch processing across multiple workers
 * @example
 * // ============================================
 * // Example 18: API Rate Limiting with Locking
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         // Call external API
 *         return ['status' => 'success'];
 *     }
 *
 *     protected function getLockKey(string $endpoint): string
 *     {
 *         // Lock per endpoint to prevent concurrent calls
 *         return "api:call:".md5($endpoint);
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 5; // Short timeout for API calls
 *     }
 * }
 *
 * // Prevents multiple concurrent calls to the same API endpoint
 * @example
 * // ============================================
 * // Example 19: Database Migration Locking
 * // ============================================
 * class RunMigration extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(string $migrationName): void
 *     {
 *         // Run migration
 *     }
 *
 *     protected function getLockKey(string $migrationName): string
 *     {
 *         return "migration:{$migrationName}";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 300; // 5 minutes for migrations
 *     }
 * }
 *
 * // Prevents the same migration from running multiple times
 * @example
 * // ============================================
 * // Example 20: Notification Sending with Locking
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(User $user, string $type): void
 *     {
 *         // Send notification
 *     }
 *
 *     protected function getLockKey(User $user, string $type): string
 *     {
 *         // Prevent duplicate notifications to the same user
 *         return "notification:{$user->id}:{$type}";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 2; // Very short timeout for notifications
 *     }
 * }
 *
 * // Prevents duplicate notifications from being sent to the same user
 */
trait AsLock
{
    // This is a marker trait - the actual locking is handled by LockDecorator
    // via the LockDesignPattern and ActionManager
}
