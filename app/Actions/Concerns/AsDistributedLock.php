<?php

namespace App\Actions\Concerns;

/**
 * Prevents concurrent execution using distributed locks across multiple servers/processes.
 *
 * This trait is a marker that enables automatic distributed locking via DistributedLockDecorator.
 * When an action uses AsDistributedLock, DistributedLockDesignPattern recognizes it and
 * ActionManager wraps the action with DistributedLockDecorator.
 *
 * How it works:
 * 1. Action uses AsDistributedLock trait (marker)
 * 2. DistributedLockDesignPattern recognizes the trait
 * 3. ActionManager wraps action with DistributedLockDecorator
 * 4. When handle() is called, the decorator:
 *    - Attempts to acquire a distributed lock (Cache or Redis)
 *    - If lock cannot be acquired, throws RuntimeException
 *    - Executes the action within the lock
 *    - Automatically releases lock after execution (even on exceptions)
 *    - Returns the result (or re-throws exception)
 *
 * Features:
 * - Distributed locking across multiple servers/processes
 * - Automatic lock release (even on exceptions)
 * - Configurable lock keys and timeouts
 * - Redis fallback for better reliability
 * - Lock metadata tracking (process ID, server, timestamp)
 * - TTL slightly longer than timeout to prevent race conditions
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Benefits:
 * - Prevents race conditions in concurrent environments
 * - Works across multiple servers/processes (when using Redis/Memcached)
 * - Automatic lock release (even on exceptions)
 * - Configurable lock keys and timeouts
 * - Enhanced reliability with Redis fallback
 * - Detailed lock metadata for debugging
 * - No trait conflicts
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * DistributedLockDecorator, which automatically wraps actions and adds distributed locking.
 * This follows the same pattern as AsLock, AsLogger, AsMetrics, and other
 * decorator-based concerns.
 *
 * Difference from AsLock:
 * - AsDistributedLock provides Redis fallback and lock metadata
 * - AsDistributedLock has longer default timeout (60s vs 10s)
 * - AsDistributedLock includes process/server tracking
 * - Use AsDistributedLock for multi-server deployments
 * - Use AsLock for simpler single-server scenarios
 *
 * Configuration:
 * - Set `getLockKey(...$arguments)` method to customize lock key
 * - Set `getLockTimeout()` method to customize lock timeout (default: 60 seconds)
 * - Set `lockTimeout` property to customize lock timeout
 *
 * @example
 * // ============================================
 * // Example 1: Basic Distributed Locking
 * // ============================================
 * class ProcessCriticalTask extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(string $taskId): void
 *     {
 *         // Critical operation that must not run concurrently across servers
 *     }
 * }
 *
 * // Ensures only one instance runs across all servers
 * ProcessCriticalTask::run('task-123');
 * @example
 * // ============================================
 * // Example 2: Custom Lock Key
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order - only one process per order across all servers
 *     }
 *
 *     protected function getLockKey(Order $order): string
 *     {
 *         return "order:{$order->id}:processing";
 *     }
 * }
 *
 * // Ensures each order is only processed once at a time across all servers
 * @example
 * // ============================================
 * // Example 3: Custom Lock Timeout
 * // ============================================
 * class LongRunningTask extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(): void
 *     {
 *         // Long-running operation that may take several minutes
 *         sleep(120);
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 300; // 5 minutes
 *     }
 * }
 *
 * // Longer timeout for operations that take more time
 * @example
 * // ============================================
 * // Example 4: Multi-Server Deployment
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(string $reportType): void
 *     {
 *         // Generate report - only one server should do this
 *     }
 *
 *     protected function getLockKey(string $reportType): string
 *     {
 *         return "report:{$reportType}:generating";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 600; // 10 minutes for report generation
 *     }
 * }
 *
 * // Prevents multiple servers from generating the same report simultaneously
 * @example
 * // ============================================
 * // Example 5: Scheduled Task Locking
 * // ============================================
 * class DailyDataSync extends Actions
 * {
 *     use AsDistributedLock;
 *     use AsSchedule;
 *
 *     public function handle(): void
 *     {
 *         // Sync data from external API
 *     }
 *
 *     protected function getLockKey(): string
 *     {
 *         return 'scheduled:daily:data:sync';
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 1800; // 30 minutes
 *     }
 * }
 *
 * // Prevents multiple instances of scheduled task from running across servers
 * @example
 * // ============================================
 * // Example 6: Queue Worker Locking
 * // ============================================
 * class ProcessPayment extends Actions implements \Illuminate\Contracts\Queue\ShouldQueue
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(Payment $payment): void
 *     {
 *         // Process payment - prevent duplicate processing
 *     }
 *
 *     protected function getLockKey(Payment $payment): string
 *     {
 *         return "payment:{$payment->id}:processing";
 *     }
 * }
 *
 * // Queue the job - locking prevents duplicate processing across workers
 * ProcessPayment::dispatch($payment);
 * @example
 * // ============================================
 * // Example 7: Cache Warming with Locking
 * // ============================================
 * class WarmCache extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(string $cacheKey): void
 *     {
 *         // Warm cache - only one server should do this
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
 * // Prevents multiple servers from warming the same cache simultaneously
 * @example
 * // ============================================
 * // Example 8: Database Migration Locking
 * // ============================================
 * class RunMigration extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(string $migrationName): void
 *     {
 *         // Run migration - only one server should execute
 *     }
 *
 *     protected function getLockKey(string $migrationName): string
 *     {
 *         return "migration:{$migrationName}";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 600; // 10 minutes for migrations
 *     }
 * }
 *
 * // Prevents the same migration from running on multiple servers
 * @example
 * // ============================================
 * // Example 9: File Processing with Locking
 * // ============================================
 * class ProcessFile extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(string $filePath): void
 *     {
 *         // Process file - prevent duplicate processing
 *     }
 *
 *     protected function getLockKey(string $filePath): string
 *     {
 *         return "file:process:".md5($filePath);
 *     }
 * }
 *
 * // Prevents the same file from being processed multiple times across servers
 * @example
 * // ============================================
 * // Example 10: Batch Processing with Locking
 * // ============================================
 * class ProcessBatch extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(int $batchId): void
 *     {
 *         // Process batch - only one server should handle each batch
 *     }
 *
 *     protected function getLockKey(int $batchId): string
 *     {
 *         return "batch:{$batchId}:processing";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 1800; // 30 minutes for large batches
 *     }
 * }
 *
 * // Prevents duplicate batch processing across multiple workers/servers
 * @example
 * // ============================================
 * // Example 11: API Rate Limiting with Locking
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         // Call external API - prevent concurrent calls
 *         return ['status' => 'success'];
 *     }
 *
 *     protected function getLockKey(string $endpoint): string
 *     {
 *         return "api:call:".md5($endpoint);
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 5; // Short timeout for API calls
 *     }
 * }
 *
 * // Prevents multiple concurrent calls to the same API endpoint across servers
 * @example
 * // ============================================
 * // Example 12: Combining with Other Decorators
 * // ============================================
 * class ProcessTransaction extends Actions
 * {
 *     use AsDistributedLock;
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
 * // - DistributedLockDecorator prevents concurrent processing across servers
 * // - TransactionDecorator ensures database consistency
 * // - LoggerDecorator tracks execution
 * @example
 * // ============================================
 * // Example 13: Handling Lock Failures
 * // ============================================
 * class CriticalUpdate extends Actions
 * {
 *     use AsDistributedLock;
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
 *     if (str_contains($e->getMessage(), 'Could not acquire distributed lock')) {
 *         // Another instance is already running on another server
 *         logger()->warning('Skipping update - already in progress on another server');
 *         return;
 *     }
 *     throw $e;
 * }
 * @example
 * // ============================================
 * // Example 14: Global Operation Locking
 * // ============================================
 * class SystemMaintenance extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(): void
 *     {
 *         // System maintenance - only one server should do this
 *     }
 *
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
 * // Ensures only one maintenance operation runs at a time globally across all servers
 * @example
 * // ============================================
 * // Example 15: User-Specific Locking
 * // ============================================
 * class UpdateUserProfile extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(User $user, array $data): void
 *     {
 *         // Update user profile
 *         $user->update($data);
 *     }
 *
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
 * // Prevents multiple simultaneous profile updates for the same user across servers
 * @example
 * // ============================================
 * // Example 16: Resource-Specific Locking
 * // ============================================
 * class ReserveInventory extends Actions
 * {
 *     use AsDistributedLock;
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
 *     protected function getLockKey(Product $product): string
 *     {
 *         return "product:{$product->id}:inventory";
 *     }
 * }
 *
 * // Prevents race conditions when multiple users try to buy the same product across servers
 * @example
 * // ============================================
 * // Example 17: Multi-Argument Lock Keys
 * // ============================================
 * class TransferFunds extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(Account $from, Account $to, float $amount): void
 *     {
 *         // Transfer funds between accounts
 *     }
 *
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
 * // Prevents concurrent transfers between the same accounts across servers
 * @example
 * // ============================================
 * // Example 18: Environment-Based Timeouts
 * // ============================================
 * class DataSync extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(): void
 *     {
 *         // Sync data
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         // Longer timeout in production for slower operations
 *         return app()->environment('production') ? 600 : 60;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Lock with Retry Logic
 * // ============================================
 * class ProcessWithRetry extends Actions
 * {
 *     use AsDistributedLock;
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
 *         if (str_contains($e->getMessage(), 'Could not acquire distributed lock')) {
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
 * // Example 20: Notification Sending with Locking
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsDistributedLock;
 *
 *     public function handle(User $user, string $type): void
 *     {
 *         // Send notification
 *     }
 *
 *     protected function getLockKey(User $user, string $type): string
 *     {
 *         return "notification:{$user->id}:{$type}";
 *     }
 *
 *     protected function getLockTimeout(): int
 *     {
 *         return 2; // Very short timeout for notifications
 *     }
 * }
 *
 * // Prevents duplicate notifications from being sent to the same user across servers
 */
trait AsDistributedLock
{
    // This is a marker trait - the actual distributed locking is handled by DistributedLockDecorator
    // via the DistributedLockDesignPattern and ActionManager
}
