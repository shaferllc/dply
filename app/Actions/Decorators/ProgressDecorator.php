<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

/**
 * Decorator that provides progress tracking for long-running actions.
 */
class ProgressDecorator
{
    use DecorateActions;

    protected ?string $progressId = null;

    protected int $currentProgress = 0;

    protected int $totalProgress = 100;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $this->progressId = $this->generateProgressId();
        $this->initializeProgress();

        try {
            $result = $this->callMethod('handle', $arguments);
            $this->completeProgress();

            return $result;
        } catch (\Throwable $e) {
            $this->failProgress($e);

            throw $e;
        }
    }

    public function setProgress(int $current, int $total): void
    {
        $this->currentProgress = $current;
        $this->totalProgress = $total;

        $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;

        $this->updateProgressCache($percentage);
        $this->broadcastProgress($percentage);
    }

    protected function initializeProgress(): void
    {
        $this->updateProgressCache(0, 'running');
    }

    protected function completeProgress(): void
    {
        $this->updateProgressCache(100, 'completed');
        $this->broadcastProgress(100, 'completed');
    }

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
        ], 3600);
    }

    protected function broadcastProgress(float $percentage, string $status = 'running', array $metadata = []): void
    {
        if ($this->shouldBroadcast()) {
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

    protected function generateProgressId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function getProgressCacheKey(): string
    {
        return 'progress:'.get_class($this->action).':'.$this->progressId;
    }

    protected function getProgressChannel(): string
    {
        return $this->fromActionMethod('getProgressChannel', [], 'progress');
    }

    protected function shouldBroadcast(): bool
    {
        return config('actions.progress.broadcast', false);
    }

    protected function getCurrentPercentage(): float
    {
        return $this->totalProgress > 0
            ? round(($this->currentProgress / $this->totalProgress) * 100, 2)
            : 0;
    }

    public function getProgressId(): ?string
    {
        return $this->progressId;
    }
}
