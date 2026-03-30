<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorator that processes items in batches to optimize memory and performance.
 *
 * This decorator automatically detects if the first argument is an array/iterable
 * and processes it in batches. If not, it executes normally.
 */
class BatchDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $batchSize = $this->getBatchSize();

        // If first argument is an array/collection, process in batches
        if (isset($arguments[0]) && (is_array($arguments[0]) || is_iterable($arguments[0]))) {
            return $this->processBatched($arguments[0], array_slice($arguments, 1), $batchSize);
        }

        // Otherwise, execute normally
        return $this->callMethod('handle', $arguments);
    }

    protected function processBatched(iterable $items, array $otherArgs, int $batchSize): array
    {
        $results = [];
        $batch = [];

        foreach ($items as $item) {
            $batch[] = $item;

            if (count($batch) >= $batchSize) {
                $batchResults = $this->processBatch($batch, $otherArgs);
                $results = array_merge($results, is_array($batchResults) ? $batchResults : [$batchResults]);

                $this->onBatchComplete($batch);

                $batch = [];
            }
        }

        // Process remaining items
        if (! empty($batch)) {
            $batchResults = $this->processBatch($batch, $otherArgs);
            $results = array_merge($results, is_array($batchResults) ? $batchResults : [$batchResults]);

            $this->onBatchComplete($batch);
        }

        return $results;
    }

    protected function processBatch(array $batch, array $otherArgs): array
    {
        $results = [];

        foreach ($batch as $item) {
            $results[] = $this->callMethod('handle', array_merge([$item], $otherArgs));
        }

        return $results;
    }

    protected function onBatchComplete(array $batch): void
    {
        if ($this->hasMethod('onBatchComplete')) {
            $this->callMethod('onBatchComplete', [$batch]);
        }
    }

    protected function getBatchSize(): int
    {
        return $this->fromActionMethodOrProperty('getBatchSize', 'batchSize', 100);
    }
}
