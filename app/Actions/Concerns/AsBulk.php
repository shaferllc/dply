<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\BulkDecorator;
use Illuminate\Support\Collection;

/**
 * Optimizes actions for bulk operations with batching and chunking.
 *
 * This trait is a marker that enables automatic bulk processing via BulkDecorator.
 * When an action uses AsBulk, BulkDesignPattern recognizes it and
 * ActionManager wraps the action with BulkDecorator.
 *
 * How it works:
 * 1. Action uses AsBulk trait (marker)
 * 2. BulkDesignPattern recognizes the trait
 * 3. ActionManager wraps action with BulkDecorator
 * 4. When bulk() is called, the decorator:
 *    - Checks for custom handleBulk() method
 *    - If exists, uses optimized bulk handler
 *    - Otherwise, processes items in batches
 *    - Optionally wraps batches in transactions
 *
 * Features:
 * - Automatic batching and chunking
 * - Custom bulk handlers for optimization
 * - Transaction support per batch
 * - Configurable batch size
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Improves performance for bulk operations
 * - Reduces memory usage with chunking
 * - Prevents timeouts on large datasets
 * - Transaction safety per batch
 * - Composable with other decorators
 * - No trait method conflicts
 *
 * Use Cases:
 * - Bulk notifications
 * - Mass data imports
 * - Batch updates
 * - Bulk email sending
 * - Data synchronization
 * - Report generation
 * - File processing
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * BulkDecorator, which automatically wraps actions and adds bulk processing.
 * This follows the same pattern as AsDebounced, AsCostTracked, AsCompensatable, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `batchSize` property or `getBatchSize()` method (default: 100)
 * - Optionally implement `handleBulk(Collection $items)` for optimized bulk processing
 * - Optionally implement `shouldUseTransaction()` to control transaction usage (default: true)
 *
 * @example
 * // ============================================
 * // Example 1: Basic Bulk Processing
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         // Send single notification
 *         Mail::to($user)->send(new NotificationMail($message));
 *     }
 *
 *     // Optional: customize batch size
 *     protected function getBatchSize(): int
 *     {
 *         return 100;
 *     }
 * }
 *
 * // Usage:
 * $users = User::where('active', true)->get();
 * SendNotification::bulk($users->map(fn($u) => [$u, 'Hello']));
 * // Automatically batches and processes in chunks of 100
 * @example
 * // ============================================
 * // Example 2: Optimized Bulk Handler
 * // ============================================
 * class BulkCreateUsers extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(array $userData): User
 *     {
 *         return User::create($userData);
 *     }
 *
 *     // Optimized bulk implementation
 *     protected function handleBulk(Collection $items): void
 *     {
 *         // Use bulk insert instead of individual creates
 *         $data = $items->map(fn($item) => is_array($item) ? $item : $item->toArray())->toArray();
 *         DB::table('users')->insert($data);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 500; // Larger batch for bulk insert
 *     }
 *
 *     protected function shouldUseTransaction(): bool
 *     {
 *         return true; // Wrap in transaction for safety
 *     }
 * }
 *
 * // Uses optimized bulk insert instead of individual creates
 * @example
 * // ============================================
 * // Example 3: Bulk Email Sending
 * // ============================================
 * class SendBulkEmail extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(string $email, string $subject, string $body): void
 *     {
 *         Mail::to($email)->send(new CustomMail($subject, $body));
 *     }
 *
 *     protected function handleBulk(Collection $items): void
 *     {
 *         // Queue emails in bulk instead of sending immediately
 *         $items->each(function ($item) {
 *             [$email, $subject, $body] = is_array($item) ? $item : [$item->email, $item->subject, $item->body];
 *             SendEmailJob::dispatch($email, $subject, $body);
 *         });
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 50; // Smaller batches for email queueing
 *     }
 * }
 *
 * // Queues emails in bulk instead of sending synchronously
 * @example
 * // ============================================
 * // Example 4: Bulk Database Updates
 * // ============================================
 * class UpdateUserStatus extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(User $user, string $status): void
 *     {
 *         $user->update(['status' => $status]);
 *     }
 *
 *     protected function handleBulk(Collection $items): void
 *     {
 *         // Use bulk update instead of individual updates
 *         $items->each(function ($item) {
 *             [$user, $status] = is_array($item) ? $item : [$item->user, $item->status];
 *             DB::table('users')
 *                 ->where('id', $user->id)
 *                 ->update(['status' => $status]);
 *         });
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 200;
 *     }
 * }
 *
 * // Uses bulk database updates
 * @example
 * // ============================================
 * // Example 5: Bulk File Processing
 * // ============================================
 * class ProcessFiles extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(string $filePath): void
 *     {
 *         // Process single file
 *         $this->processFile($filePath);
 *     }
 *
 *     protected function handleBulk(Collection $items): void
 *     {
 *         // Process files in parallel batches
 *         $items->chunk(10)->each(function (Collection $chunk) {
 *             $chunk->each(function ($item) {
 *                 $filePath = is_array($item) ? $item[0] : $item;
 *                 ProcessFileJob::dispatch($filePath);
 *             });
 *         });
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 10; // Smaller batches for file processing
 *     }
 *
 *     protected function shouldUseTransaction(): bool
 *     {
 *         return false; // No transaction needed for file processing
 *     }
 * }
 *
 * // Processes files in batches and queues for parallel processing
 * @example
 * // ============================================
 * // Example 6: Property-Based Configuration
 * // ============================================
 * class ConfigurableBulkAction extends Actions
 * {
 *     use AsBulk;
 *
 *     public int $batchSize = 250;
 *
 *     public function handle($item): void
 *     {
 *         // Process item
 *     }
 * }
 *
 * // Uses property for batch size
 * @example
 * // ============================================
 * // Example 7: Bulk with Mapper Function
 * // ============================================
 * class TransformAndProcess extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(array $data): void
 *     {
 *         // Process transformed data
 *         Model::create($data);
 *     }
 * }
 *
 * // Usage:
 * $rawData = collect([...]);
 * TransformAndProcess::bulk($rawData, function ($item) {
 *     return [
 *         'name' => $item->name,
 *         'email' => $item->email,
 *         'processed_at' => now(),
 *     ];
 * });
 *
 * // Mapper transforms items before processing
 * @example
 * // ============================================
 * // Example 8: Bulk API Calls
 * // ============================================
 * class SyncToExternalAPI extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(Model $model): void
 *     {
 *         Http::post('https://api.example.com/sync', $model->toArray());
 *     }
 *
 *     protected function handleBulk(Collection $items): void
 *     {
 *         // Use batch API endpoint if available
 *         $data = $items->map(fn($item) => is_array($item) ? $item[0]->toArray() : $item->toArray())->toArray();
 *         Http::post('https://api.example.com/sync/batch', ['items' => $data]);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 50; // API batch limit
 *     }
 * }
 *
 * // Uses batch API endpoint when available
 * @example
 * // ============================================
 * // Example 9: Bulk with Progress Tracking
 * // ============================================
 * class BulkImportData extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(array $row): void
 *     {
 *         ImportRow::create($row);
 *     }
 *
 *     protected function processBatch(Collection $batch): void
 *     {
 *         // Track progress
 *         $progress = app('progress');
 *         $progress->advance($batch->count());
 *
 *         parent::processBatch($batch);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 100;
 *     }
 * }
 *
 * // Tracks progress during bulk import
 * @example
 * // ============================================
 * // Example 10: Bulk with Error Handling
 * // ============================================
 * class ResilientBulkAction extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle($item): void
 *     {
 *         // Process item
 *     }
 *
 *     protected function processBatch(Collection $batch): void
 *     {
 *         try {
 *             parent::processBatch($batch);
 *         } catch (\Exception $e) {
 *             // Log error but continue with next batch
 *             \Log::error('Batch processing failed', [
 *                 'batch_size' => $batch->count(),
 *                 'error' => $e->getMessage(),
 *             ]);
 *
 *             // Optionally retry failed items
 *             $this->retryFailedItems($batch);
 *         }
 *     }
 *
 *     protected function retryFailedItems(Collection $batch): void
 *     {
 *         // Queue failed items for retry
 *         $batch->each(function ($item) {
 *             RetryItemJob::dispatch($item);
 *         });
 *     }
 * }
 *
 * // Handles errors gracefully and retries failed items
 * @example
 * // ============================================
 * // Example 11: Bulk with Different Item Types
 * // ============================================
 * class FlexibleBulkAction extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle($item): void
 *     {
 *         // Handle single item (can be array, object, or primitive)
 *         if (is_array($item)) {
 *             // Process array
 *         } elseif (is_object($item)) {
 *             // Process object
 *         } else {
 *             // Process primitive
 *         }
 *     }
 * }
 *
 * // Handles different item types in bulk
 * @example
 * // ============================================
 * // Example 12: Bulk with Conditional Processing
 * // ============================================
 * class ConditionalBulkAction extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle($item): void
 *     {
 *         // Process item
 *     }
 *
 *     protected function handleBulk(Collection $items): void
 *     {
 *         // Filter items before processing
 *         $filtered = $items->filter(fn($item) => $this->shouldProcess($item));
 *
 *         // Process filtered items in batches
 *         $filtered->chunk($this->getBatchSize())->each(function (Collection $chunk) {
 *             $this->processBatch($chunk);
 *         });
 *     }
 *
 *     protected function shouldProcess($item): bool
 *     {
 *         // Custom filtering logic
 *         return true;
 *     }
 * }
 *
 * // Filters items before bulk processing
 * @example
 * // ============================================
 * // Example 13: Bulk with Rate Limiting
 * // ============================================
 * class RateLimitedBulkAction extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle($item): void
 *     {
 *         // Process item
 *     }
 *
 *     protected function processBatch(Collection $batch): void
 *     {
 *         // Add delay between batches to respect rate limits
 *         parent::processBatch($batch);
 *
 *         // Wait before next batch
 *         usleep(100000); // 100ms delay
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 10; // Smaller batches for rate-limited operations
 *     }
 * }
 *
 * // Adds delays between batches to respect rate limits
 * @example
 * // ============================================
 * // Example 14: Bulk Data Synchronization
 * // ============================================
 * class SyncDataToRemote extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(Model $model): void
 *     {
 *         // Sync single model
 *         RemoteService::sync($model);
 *     }
 *
 *     protected function handleBulk(Collection $items): void
 *     {
 *         // Use bulk sync endpoint
 *         $models = $items->map(fn($item) => is_array($item) ? $item[0] : $item);
 *         RemoteService::bulkSync($models);
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 100;
 *     }
 *
 *     protected function shouldUseTransaction(): bool
 *     {
 *         return false; // External sync doesn't need local transaction
 *     }
 * }
 *
 * // Uses bulk sync endpoint for efficiency
 */
trait AsBulk
{
    /**
     * Reference to the bulk decorator (injected by decorator).
     */
    protected ?BulkDecorator $_bulkDecorator = null;

    /**
     * Set the bulk decorator reference.
     *
     * Called by BulkDecorator to inject itself.
     */
    public function setBulkDecorator(BulkDecorator $decorator): void
    {
        $this->_bulkDecorator = $decorator;
    }

    /**
     * Get the bulk decorator.
     */
    protected function getBulkDecorator(): ?BulkDecorator
    {
        return $this->_bulkDecorator;
    }

    /**
     * Process items in bulk with automatic batching.
     *
     * @param  Collection  $items  Collection of items to process
     * @param  callable|null  $mapper  Optional mapper function to transform items
     */
    public static function bulk(Collection $items, ?callable $mapper = null): void
    {
        $instance = static::make();
        $decorator = $instance->getBulkDecorator();

        if ($decorator) {
            $decorator->bulk($items, $mapper);
        } else {
            // Fallback: create temporary decorator
            $tempDecorator = app(BulkDecorator::class, ['action' => $instance]);
            $tempDecorator->bulk($items, $mapper);
        }
    }
}
