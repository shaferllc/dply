<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsProgressive;
use App\Actions\Decorators\ProgressiveDecorator;

/**
 * Recognizes when actions use progress tracking capabilities.
 *
 * @example
 * // Action class:
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
 * // Get progress:
 * $progress = ProcessLargeDataset::getProgress($result->_progress['progress_id']);
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsProgressive and decorates it to track progress.
 */
class ProgressiveDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsProgressive::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsProgressive trait
        // The decorator will handle progress tracking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ProgressiveDecorator::class, ['action' => $instance]);
    }
}
