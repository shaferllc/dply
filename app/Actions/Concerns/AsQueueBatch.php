<?php

namespace App\Actions\Concerns;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * Groups related jobs in batches for monitoring.
 *
 * Provides batch queue job capabilities for actions, allowing them to group
 * multiple jobs together for monitoring, progress tracking, and batch-level
 * callbacks. Uses Laravel's job batching feature.
 *
 * How it works:
 * - Provides `batch()` method for creating job batches
 * - Automatically configures batch name, failure handling, and callbacks
 * - Supports then/catch/finally callbacks for batch lifecycle
 * - Allows custom batch configuration via method overrides
 * - Returns Laravel Batch instance for tracking
 *
 * Benefits:
 * - Group related jobs together
 * - Monitor batch progress
 * - Handle batch-level success/failure
 * - Track batch completion
 * - Allow failures or fail fast
 * - Batch lifecycle callbacks
 *
 * Note: This is NOT a decorator - it provides utility methods that
 * actions can call explicitly. Batch creation is opt-in and explicit,
 * giving you full control over when and how to create batches.
 *
 * Does it need to be a decorator?
 * No. The current trait-based approach works well because:
 * - It provides utility methods (batch(), getBatchName(), etc.)
 * - It doesn't need to intercept execution
 * - Batch creation is explicit and opt-in
 * - Actions control when to create batches
 * - The trait pattern is simpler for this use case
 *
 * A decorator would only be needed if you wanted to automatically
 * batch all action executions, but the current approach gives you
 * explicit control over batch creation.
 *
 * @example
 * // Basic usage - create a batch of jobs:
 * class ProcessOrders extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $orders): Batch
 *     {
 *         $jobs = array_map(fn ($order) => ProcessOrder::makeJob($order), $orders);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Process Orders';
 *     }
 * }
 *
 * // Usage:
 * $batch = ProcessOrders::run($orders);
 * // $batch->id - Batch ID for tracking
 * // $batch->progress() - Check progress (0-100)
 * // $batch->cancelled() - Check if cancelled
 * // $batch->finished() - Check if finished
 * @example
 * // Batch with progress tracking:
 * class ImportUsers extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $users): Batch
 *     {
 *         $jobs = array_map(fn ($user) => ImportUser::makeJob($user), $users);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Import Users';
 *     }
 * }
 *
 * // Usage:
 * $batch = ImportUsers::run($users);
 *
 * // Check progress
 * $progress = $batch->progress(); // 0-100
 * $processed = $batch->processedJobs(); // Number of completed jobs
 * $total = $batch->totalJobs(); // Total jobs in batch
 *
 * // Monitor in real-time
 * while (! $batch->finished()) {
 *     sleep(1);
 *     $progress = $batch->progress();
 *     echo "Progress: {$progress}%\n";
 * }
 * @example
 * // Batch with success callback (then):
 * class SendNotifications extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $notifications): Batch
 *     {
 *         $jobs = array_map(fn ($notification) => SendNotification::makeJob($notification), $notifications);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Send Notifications';
 *     }
 *
 *     public function onBatchThen(Batch $batch): void
 *     {
 *         // Called when all jobs in batch complete successfully
 *         \Log::info("Batch {$batch->id} completed successfully");
 *         Notification::send(new BatchCompletedNotification($batch));
 *     }
 * }
 *
 * // Usage:
 * $batch = SendNotifications::run($notifications);
 * // onBatchThen() is called automatically when all jobs complete
 * @example
 * // Batch with error handling (catch):
 * class ProcessPayments extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $payments): Batch
 *     {
 *         $jobs = array_map(fn ($payment) => ProcessPayment::makeJob($payment), $payments);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Process Payments';
 *     }
 *
 *     public function onBatchCatch(Batch $batch, \Throwable $exception): void
 *     {
 *         // Called when a job in the batch fails
 *         \Log::error("Batch {$batch->id} failed", [
 *             'exception' => $exception->getMessage(),
 *             'failed_jobs' => $batch->failedJobs,
 *         ]);
 *
 *         // Notify administrators
 *         Admin::notify(new BatchFailedNotification($batch, $exception));
 *     }
 * }
 *
 * // Usage:
 * $batch = ProcessPayments::run($payments);
 * // onBatchCatch() is called automatically if any job fails
 * @example
 * // Batch with cleanup (finally):
 * class GenerateReports extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $reports): Batch
 *     {
 *         $jobs = array_map(fn ($report) => GenerateReport::makeJob($report), $reports);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Generate Reports';
 *     }
 *
 *     public function onBatchFinally(Batch $batch): void
 *     {
 *         // Called after batch completes (success or failure)
 *         \Log::info("Batch {$batch->id} finished", [
 *             'progress' => $batch->progress(),
 *             'processed' => $batch->processedJobs(),
 *             'failed' => $batch->failedJobs->count(),
 *         ]);
 *
 *         // Cleanup temporary files
 *         $this->cleanupTemporaryFiles($batch);
 *     }
 *
 *     protected function cleanupTemporaryFiles(Batch $batch): void
 *     {
 *         // Cleanup logic
 *     }
 * }
 *
 * // Usage:
 * $batch = GenerateReports::run($reports);
 * // onBatchFinally() is called automatically after batch completes
 * @example
 * // Batch allowing failures:
 * class ProcessData extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $data): Batch
 *     {
 *         $jobs = array_map(fn ($item) => ProcessDataItem::makeJob($item), $data);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Process Data';
 *     }
 *
 *     public function allowsBatchFailures(): bool
 *     {
 *         return true; // Continue processing even if some jobs fail
 *     }
 *
 *     public function onBatchCatch(Batch $batch, \Throwable $exception): void
 *     {
 *         // Log failures but continue processing
 *         \Log::warning("Job failed in batch {$batch->id}", [
 *             'exception' => $exception->getMessage(),
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * $batch = ProcessData::run($data);
 * // Batch continues even if some jobs fail
 * @example
 * // Batch with all callbacks:
 * class ComprehensiveBatch extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $items): Batch
 *     {
 *         $jobs = array_map(fn ($item) => ProcessItem::makeJob($item), $items);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Comprehensive Processing';
 *     }
 *
 *     public function allowsBatchFailures(): bool
 *     {
 *         return true;
 *     }
 *
 *     public function onBatchThen(Batch $batch): void
 *     {
 *         // Success callback
 *         \Log::info("Batch {$batch->id} succeeded");
 *     }
 *
 *     public function onBatchCatch(Batch $batch, \Throwable $exception): void
 *     {
 *         // Error callback
 *         \Log::error("Batch {$batch->id} had failures", [
 *             'exception' => $exception->getMessage(),
 *         ]);
 *     }
 *
 *     public function onBatchFinally(Batch $batch): void
 *     {
 *         // Cleanup callback
 *         \Log::info("Batch {$batch->id} finished");
 *     }
 * }
 *
 * // Usage:
 * $batch = ComprehensiveBatch::run($items);
 * // All callbacks are called at appropriate times
 * @example
 * // Batch with dynamic job creation:
 * class ProcessChunks extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $data, int $chunkSize = 100): Batch
 *     {
 *         $chunks = array_chunk($data, $chunkSize);
 *         $jobs = [];
 *
 *         foreach ($chunks as $chunk) {
 *             $jobs[] = ProcessChunk::makeJob($chunk);
 *         }
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Process Chunks';
 *     }
 * }
 *
 * // Usage:
 * $batch = ProcessChunks::run($largeDataset, chunkSize: 50);
 * // Creates batch with multiple chunk processing jobs
 * @example
 * // Batch with conditional job creation:
 * class ConditionalBatch extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $items): Batch
 *     {
 *         $jobs = [];
 *
 *         foreach ($items as $item) {
 *             // Only create job if item meets criteria
 *             if ($this->shouldProcess($item)) {
 *                 $jobs[] = ProcessItem::makeJob($item);
 *             }
 *         }
 *
 *         if (empty($jobs)) {
 *             throw new \RuntimeException('No items to process');
 *         }
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Conditional Processing';
 *     }
 *
 *     protected function shouldProcess($item): bool
 *     {
 *         // Conditional logic
 *         return $item['status'] === 'pending';
 *     }
 * }
 * @example
 * // Batch tracking and monitoring:
 * class TrackableBatch extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $items): Batch
 *     {
 *         $jobs = array_map(fn ($item) => ProcessItem::makeJob($item), $items);
 *
 *         $batch = $this->batch($jobs);
 *
 *         // Store batch ID for later tracking
 *         $this->storeBatchId($batch->id);
 *
 *         return $batch;
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Trackable Processing';
 *     }
 *
 *     protected function storeBatchId(string $batchId): void
 *     {
 *         // Store in database, cache, or session
 *         cache()->put("user_batch:".auth()->id(), $batchId, 3600);
 *     }
 *
 *     public function getBatchProgress(string $batchId): array
 *     {
 *         $batch = Bus::findBatch($batchId);
 *
 *         if (! $batch) {
 *             return ['error' => 'Batch not found'];
 *         }
 *
 *         return [
 *             'id' => $batch->id,
 *             'progress' => $batch->progress(),
 *             'processed' => $batch->processedJobs(),
 *             'total' => $batch->totalJobs(),
 *             'failed' => $batch->failedJobs->count(),
 *             'cancelled' => $batch->cancelled(),
 *             'finished' => $batch->finished(),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $batch = TrackableBatch::run($items);
 * $batchId = $batch->id;
 *
 * // Later, check progress
 * $progress = TrackableBatch::make()->getBatchProgress($batchId);
 * @example
 * // Batch with cancellation support:
 * class CancellableBatch extends Actions
 * {
 *     use AsQueueBatch;
 *
 *     public function handle(array $items): Batch
 *     {
 *         $jobs = array_map(fn ($item) => ProcessItem::makeJob($item), $items);
 *
 *         $batch = $this->batch($jobs);
 *
 *         // Store batch ID for cancellation
 *         $this->storeBatchId($batch->id);
 *
 *         return $batch;
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Cancellable Processing';
 *     }
 *
 *     protected function storeBatchId(string $batchId): void
 *     {
 *         cache()->put("cancellable_batch:".auth()->id(), $batchId, 3600);
 *     }
 *
 *     public function cancelBatch(string $batchId): void
 *     {
 *         $batch = Bus::findBatch($batchId);
 *
 *         if ($batch && ! $batch->finished()) {
 *             $batch->cancel();
 *             \Log::info("Batch {$batchId} cancelled");
 *         }
 *     }
 * }
 *
 * // Usage:
 * $batch = CancellableBatch::run($items);
 *
 * // Later, cancel if needed
 * CancellableBatch::make()->cancelBatch($batch->id);
 * @example
 * // Batch combining with other concerns:
 * class BatchedOperation extends Actions
 * {
 *     use AsQueueBatch;
 *     use AsRetry;
 *     use AsTimeout;
 *
 *     public function handle(array $items): Batch
 *     {
 *         $jobs = array_map(fn ($item) => ProcessItem::makeJob($item), $items);
 *
 *         return $this->batch($jobs);
 *     }
 *
 *     public function getBatchName(): string
 *     {
 *         return 'Batched Operation';
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 3; // Retry failed jobs
 *     }
 * }
 *
 * // Usage:
 * $batch = BatchedOperation::run($items);
 * // Combines batch processing with retry and timeout
 */
trait AsQueueBatch
{
    protected function batch(array $jobs): Batch
    {
        $name = $this->getBatchName();

        return Bus::batch($jobs)
            ->name($name)
            ->allowFailures($this->allowsBatchFailures())
            ->then($this->getBatchThenCallback())
            ->catch($this->getBatchCatchCallback())
            ->finally($this->getBatchFinallyCallback())
            ->dispatch();
    }

    protected function getBatchName(): string
    {
        return $this->hasMethod('getBatchName')
            ? $this->callMethod('getBatchName')
            : class_basename($this);
    }

    protected function allowsBatchFailures(): bool
    {
        return $this->hasMethod('allowsBatchFailures')
            ? $this->callMethod('allowsBatchFailures')
            : false;
    }

    protected function getBatchThenCallback(): ?callable
    {
        return $this->hasMethod('onBatchThen')
            ? fn ($batch) => $this->callMethod('onBatchThen', [$batch])
            : null;
    }

    protected function getBatchCatchCallback(): ?callable
    {
        return $this->hasMethod('onBatchCatch')
            ? fn ($batch, $exception) => $this->callMethod('onBatchCatch', [$batch, $exception])
            : null;
    }

    protected function getBatchFinallyCallback(): ?callable
    {
        return $this->hasMethod('onBatchFinally')
            ? fn ($batch) => $this->callMethod('onBatchFinally', [$batch])
            : null;
    }
}
