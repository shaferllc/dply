<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

/**
 * Provides progress tracking for long-running actions.
 *
 * Provides progress tracking capabilities for actions, allowing them to report
 * progress during long-running operations. Progress is stored in cache and
 * can be broadcast for real-time updates.
 *
 * How it works:
 * - ProgressiveDesignPattern recognizes actions using AsProgressive
 * - ActionManager wraps the action with ProgressiveDecorator
 * - When handle() is called, the decorator:
 *    - Generates a unique progress ID
 *    - Initializes progress tracking
 *    - Executes the action
 *    - Actions call setProgress() to update progress
 *    - Completes or fails progress tracking
 *    - Adds progress metadata to result
 *
 * Benefits:
 * - Track progress of long-running operations
 * - Real-time progress updates via broadcasts
 * - Cache-based progress storage
 * - Progress retrieval by ID
 * - Failure tracking
 * - Custom progress channels
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ProgressiveDecorator, which automatically wraps actions and tracks progress.
 * This follows the same pattern as AsTimeout, AsThrottle, and other
 * decorator-based concerns.
 *
 * Progress Metadata:
 * The result will include a `_progress` property with:
 * - `progress_id`: Unique identifier for this execution
 * - `percentage`: Current progress percentage (0-100)
 * - `status`: Current status (running, completed, failed)
 * - `current`: Current progress value
 * - `total`: Total progress value
 *
 * @example
 * // Basic usage - track progress during processing:
 * class ProcessLargeDataset extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(array $items): void
 *     {
 *         $total = count($items);
 *
 *         foreach ($items as $index => $item) {
 *             $this->setProgress($index + 1, $total);
 *             // Process item
 *         }
 *     }
 *
 *     public function getProgressChannel(): string
 *     {
 *         return 'progress.'.auth()->id();
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessLargeDataset::run($items);
 * // $result->_progress = ['progress_id' => '...', 'percentage' => 100, 'status' => 'completed']
 *
 * // Get progress by ID:
 * $progress = ProcessLargeDataset::getProgress($result->_progress['progress_id']);
 * @example
 * // Progress with custom channel per user:
 * class ImportData extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(string $filePath): void
 *     {
 *         $data = $this->readFile($filePath);
 *         $total = count($data);
 *
 *         foreach ($data as $index => $row) {
 *             $this->setProgress($index + 1, $total);
 *             $this->processRow($row);
 *         }
 *     }
 *
 *     public function getProgressChannel(): string
 *     {
 *         return 'import.progress.'.auth()->id();
 *     }
 *
 *     protected function readFile(string $filePath): array
 *     {
 *         // Read file logic
 *         return [];
 *     }
 *
 *     protected function processRow(array $row): void
 *     {
 *         // Process row logic
 *     }
 * }
 *
 * // Usage:
 * $result = ImportData::run($filePath);
 * // Progress is broadcast to user-specific channel
 * @example
 * // Progress tracking in loops:
 * class GenerateReports extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(array $reportTypes): array
 *     {
 *         $total = count($reportTypes);
 *         $generated = [];
 *
 *         foreach ($reportTypes as $index => $type) {
 *             $this->setProgress($index + 1, $total);
 *             $generated[] = $this->generateReport($type);
 *         }
 *
 *         return $generated;
 *     }
 *
 *     protected function generateReport(string $type): Report
 *     {
 *         // Generate report
 *         return new Report($type);
 *     }
 * }
 *
 * // Usage:
 * $result = GenerateReports::run(['sales', 'inventory', 'revenue']);
 * // Progress: 33%, 66%, 100%
 * @example
 * // Progress with metadata:
 * class ProcessPayments extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(array $payments): void
 *     {
 *         $total = count($payments);
 *         $processed = 0;
 *         $failed = 0;
 *
 *         foreach ($payments as $index => $payment) {
 *             $this->setProgress($index + 1, $total);
 *
 *             try {
 *                 $this->processPayment($payment);
 *                 $processed++;
 *             } catch (\Exception $e) {
 *                 $failed++;
 *             }
 *         }
 *     }
 *
 *     protected function processPayment($payment): void
 *     {
 *         // Process payment
 *     }
 * }
 * @example
 * // Progress tracking with chunked processing:
 * class ProcessChunks extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(array $data, int $chunkSize = 100): void
 *     {
 *         $chunks = array_chunk($data, $chunkSize);
 *         $totalChunks = count($chunks);
 *
 *         foreach ($chunks as $index => $chunk) {
 *             $this->setProgress($index + 1, $totalChunks);
 *             $this->processChunk($chunk);
 *         }
 *     }
 *
 *     protected function processChunk(array $chunk): void
 *     {
 *         // Process chunk
 *     }
 * }
 *
 * // Usage:
 * ProcessChunks::run($largeDataset, chunkSize: 50);
 * // Progress tracked per chunk
 * @example
 * // Progress retrieval and monitoring:
 * class LongRunningTask extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(): void
 *     {
 *         // Long-running operation
 *         for ($i = 1; $i <= 100; $i++) {
 *             $this->setProgress($i, 100);
 *             sleep(1); // Simulate work
 *         }
 *     }
 * }
 *
 * // Usage:
 * $result = LongRunningTask::run();
 * $progressId = $result->_progress['progress_id'];
 *
 * // Monitor progress in another process/request:
 * while (true) {
 *     $progress = LongRunningTask::getProgress($progressId);
 *
 *     if (! $progress) {
 *         break; // Progress expired or not found
 *     }
 *
 *     echo "Progress: {$progress['percentage']}% ({$progress['status']})\n";
 *
 *     if ($progress['status'] === 'completed' || $progress['status'] === 'failed') {
 *         break;
 *     }
 *
 *     sleep(1);
 * }
 * @example
 * // Progress with Livewire integration:
 * class ProcessImport extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(string $filePath): void
 *     {
 *         $data = $this->readFile($filePath);
 *         $total = count($data);
 *
 *         foreach ($data as $index => $row) {
 *             $this->setProgress($index + 1, $total);
 *             $this->processRow($row);
 *         }
 *     }
 *
 *     public function getProgressChannel(): string
 *     {
 *         return 'import.'.auth()->id();
 *     }
 *
 *     protected function readFile(string $filePath): array
 *     {
 *         return [];
 *     }
 *
 *     protected function processRow(array $row): void
 *     {
 *         // Process row
 *     }
 * }
 *
 * // Livewire Component:
 * class ImportProgress extends Component
 * {
 *     public string $progressId;
 *     public array $progress = [];
 *
 *     public function mount(string $progressId): void
 *     {
 *         $this->progressId = $progressId;
 *     }
 *
 *     public function pollProgress(): void
 *     {
 *         $this->progress = ProcessImport::getProgress($this->progressId) ?? [];
 *
 *         if (($this->progress['status'] ?? '') === 'completed') {
 *             $this->dispatch('import-completed');
 *         }
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.import-progress', [
 *             'percentage' => $this->progress['percentage'] ?? 0,
 *             'status' => $this->progress['status'] ?? 'running',
 *         ]);
 *     }
 * }
 *
 * // In Blade template, use wire:poll to auto-refresh:
 * // <div wire:poll.2s="pollProgress">
 * //     Progress: {{ $percentage }}%
 * // </div>
 * @example
 * // Progress with error handling:
 * class ProcessWithErrors extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(array $items): void
 *     {
 *         $total = count($items);
 *
 *         foreach ($items as $index => $item) {
 *             $this->setProgress($index + 1, $total);
 *
 *             try {
 *                 $this->processItem($item);
 *             } catch (\Exception $e) {
 *                 // Progress will be marked as failed by decorator
 *                 throw $e;
 *             }
 *         }
 *     }
 *
 *     protected function processItem($item): void
 *     {
 *         // Process item - may throw exception
 *     }
 * }
 *
 * // Usage:
 * try {
 *     $result = ProcessWithErrors::run($items);
 * } catch (\Exception $e) {
 *     // Progress is automatically marked as failed
 *     $progress = ProcessWithErrors::getProgress($result->_progress['progress_id']);
 *     // $progress['status'] === 'failed'
 *     // $progress['metadata']['error'] === $e->getMessage()
 * }
 * @example
 * // Progress with custom cache TTL:
 * class ExtendedProgress extends Actions
 * {
 *     use AsProgressive;
 *
 *     public function handle(): void
 *     {
 *         // Long operation that might take hours
 *         for ($i = 1; $i <= 1000; $i++) {
 *             $this->setProgress($i, 1000);
 *             sleep(10); // 10 seconds per step
 *         }
 *     }
 *
 *     // Override cache TTL if needed (default is 1 hour)
 *     // Note: This would require modifying the decorator or trait
 *     // For now, progress is cached for 1 hour by default
 * }
 * @example
 * // Progress combining with other decorators:
 * class ComprehensiveOperation extends Actions
 * {
 *     use AsProgressive;
 *     use AsRetry;
 *     use AsTimeout;
 *
 *     public function handle(array $items): void
 *     {
 *         $total = count($items);
 *
 *         foreach ($items as $index => $item) {
 *             $this->setProgress($index + 1, $total);
 *             $this->processItem($item);
 *         }
 *     }
 *
 *     protected function processItem($item): void
 *     {
 *         // Process item
 *     }
 * }
 *
 * // Usage:
 * $result = ComprehensiveOperation::run($items);
 * // Combines progress tracking, retry, and timeout
 */
trait AsProgressive
{
    // This trait is now just a marker trait with utility methods.
    // The actual progress tracking logic is handled by ProgressiveDecorator
    // which is automatically applied via ProgressiveDesignPattern.

    protected ?string $progressId = null;

    protected int $currentProgress = 0;

    protected int $totalProgress = 100;

    /**
     * Set progress for the current operation.
     * Call this method during action execution to update progress.
     *
     * @param  int  $current  Current progress value
     * @param  int  $total  Total progress value
     */
    public function setProgress(int $current, int $total): void
    {
        $this->currentProgress = $current;
        $this->totalProgress = $total;

        $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;

        $this->updateProgressCache($percentage);
        $this->broadcastProgress($percentage);
    }

    /**
     * Get progress by progress ID.
     * Static method to retrieve progress from cache.
     */
    public static function getProgress(string $progressId): ?array
    {
        $instance = static::make();
        $key = $instance->getProgressCacheKeyForId($progressId);

        return Cache::get($key);
    }

    /**
     * Get the progress ID for this execution.
     * Set by the decorator during execution.
     */
    public function getProgressId(): ?string
    {
        return $this->progressId;
    }

    /**
     * Get the progress channel for broadcasting.
     * Override this method to customize the channel.
     */
    protected function getProgressChannel(): string
    {
        return 'progress';
    }

    /**
     * Update progress in cache.
     * Called by setProgress() to store progress.
     */
    protected function updateProgressCache(float $percentage, string $status = 'running', array $metadata = []): void
    {
        $key = $this->getProgressCacheKey();

        Cache::put($key, [
            'percentage' => $percentage,
            'status' => $status,
            'current' => $this->currentProgress,
            'total' => $this->totalProgress,
            'updated_at' => now()->toIso8601String(),
            'metadata' => $metadata,
        ], 3600); // 1 hour TTL
    }

    /**
     * Broadcast progress update.
     * Called by setProgress() to broadcast progress.
     */
    protected function broadcastProgress(float $percentage, string $status = 'running', array $metadata = []): void
    {
        if ($this->shouldBroadcastProgress()) {
            Broadcast::channel($this->getProgressChannel(), [
                'progress_id' => $this->progressId,
                'percentage' => $percentage,
                'status' => $status,
                'current' => $this->currentProgress,
                'total' => $this->totalProgress,
                'metadata' => $metadata,
            ]);
        }
    }

    /**
     * Get the cache key for progress tracking.
     */
    protected function getProgressCacheKey(): string
    {
        return $this->getProgressCacheKeyForId($this->progressId ?? '');
    }

    /**
     * Get the cache key for a specific progress ID.
     */
    protected function getProgressCacheKeyForId(string $progressId): string
    {
        return 'progress:'.get_class($this).':'.$progressId;
    }

    /**
     * Determine if progress should be broadcast.
     */
    protected function shouldBroadcastProgress(): bool
    {
        return config('actions.progress.broadcast', false);
    }
}
