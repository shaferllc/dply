<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

/**
 * Progressive Decorator
 *
 * Automatically tracks progress for long-running actions.
 * This decorator intercepts handle() calls and tracks progress
 * through cache and broadcasts, allowing real-time progress monitoring.
 *
 * Features:
 * - Automatic progress tracking
 * - Cache-based progress storage
 * - Broadcast support for real-time updates
 * - Progress initialization and completion
 * - Failure tracking
 * - Custom progress channels
 * - Progress metadata support
 *
 * How it works:
 * 1. When an action uses AsProgressive, ProgressiveDesignPattern recognizes it
 * 2. ActionManager wraps the action with ProgressiveDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates a unique progress ID
 *    - Initializes progress tracking
 *    - Executes the action
 *    - Updates progress as action calls setProgress()
 *    - Completes or fails progress tracking
 *    - Adds progress metadata to result
 *
 * Progress Metadata:
 * The result will include a `_progress` property with:
 * - `progress_id`: Unique identifier for this execution
 * - `percentage`: Current progress percentage (0-100)
 * - `status`: Current status (running, completed, failed)
 * - `current`: Current progress value
 * - `total`: Total progress value
 */
class ProgressiveDecorator
{
    use DecorateActions;

    protected ?string $progressId = null;

    protected int $currentProgress = 0;

    protected int $totalProgress = 100;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with progress tracking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $this->progressId = $this->generateProgressId();

        // Set progress ID on action so it can be accessed
        if ($this->hasProperty('progressId')) {
            $this->setProperty('progressId', $this->progressId);
        }

        $this->initializeProgress();

        try {
            $result = $this->action->handle(...$arguments);
            $this->completeProgress();

            // Add progress metadata to result
            if (is_object($result)) {
                $result->_progress = [
                    'progress_id' => $this->progressId,
                    'percentage' => 100,
                    'status' => 'completed',
                    'current' => $this->currentProgress,
                    'total' => $this->totalProgress,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->failProgress($e);

            throw $e;
        }
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Set progress for the action.
     * This method is called by the action to update progress.
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
     * Get the progress ID for this execution.
     */
    public function getProgressId(): ?string
    {
        return $this->progressId;
    }

    /**
     * Initialize progress tracking.
     */
    protected function initializeProgress(): void
    {
        $this->updateProgressCache(0, 'running');
    }

    /**
     * Mark progress as completed.
     */
    protected function completeProgress(): void
    {
        $this->updateProgressCache(100, 'completed');
        $this->broadcastProgress(100, 'completed');
    }

    /**
     * Mark progress as failed.
     */
    protected function failProgress(\Throwable $exception): void
    {
        $percentage = $this->getCurrentPercentage();

        $this->updateProgressCache($percentage, 'failed', [
            'error' => $exception->getMessage(),
        ]);
        $this->broadcastProgress($percentage, 'failed', [
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Update progress in cache.
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
     * Generate a unique progress ID.
     */
    protected function generateProgressId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get the cache key for progress tracking.
     */
    protected function getProgressCacheKey(): string
    {
        return $this->getProgressCacheKeyForId($this->progressId);
    }

    /**
     * Get the cache key for a specific progress ID.
     */
    protected function getProgressCacheKeyForId(string $progressId): string
    {
        $class = get_class($this->action);

        return "progress:{$class}:{$progressId}";
    }

    /**
     * Get the progress channel for broadcasting.
     */
    protected function getProgressChannel(): string
    {
        if ($this->hasMethod('getProgressChannel')) {
            return $this->callMethod('getProgressChannel');
        }

        return 'progress';
    }

    /**
     * Determine if progress should be broadcast.
     */
    protected function shouldBroadcastProgress(): bool
    {
        return config('actions.progress.broadcast', false);
    }

    /**
     * Get current progress percentage.
     */
    protected function getCurrentPercentage(): float
    {
        return $this->totalProgress > 0
            ? round(($this->currentProgress / $this->totalProgress) * 100, 2)
            : 0;
    }
}
