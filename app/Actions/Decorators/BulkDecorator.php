<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decorator that optimizes actions for bulk operations with batching and chunking.
 *
 * This decorator automatically handles bulk processing by batching items
 * and optionally wrapping batches in transactions for safety.
 */
class BulkDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setBulkDecorator')) {
            $action->setBulkDecorator($this);
        } elseif (property_exists($action, '_bulkDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_bulkDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        return $this->callMethod('handle', $arguments);
    }

    /**
     * Process items in bulk with automatic batching.
     *
     * @param  Collection  $items  Collection of items to process
     * @param  callable|null  $mapper  Optional mapper function to transform items
     */
    public function bulk(Collection $items, ?callable $mapper = null): void
    {
        // Apply mapper if provided
        if ($mapper) {
            $items = $items->map($mapper);
        }

        // If custom bulk handler exists, use it
        if ($this->hasMethod('handleBulk')) {
            $this->callMethod('handleBulk', [$items]);

            return;
        }

        // Otherwise, process in batches
        $batchSize = $this->getBatchSize();
        $items->chunk($batchSize)->each(function (Collection $chunk) {
            $this->processBatch($chunk);
        });
    }

    /**
     * Process a batch of items, optionally in a transaction.
     */
    protected function processBatch(Collection $batch): void
    {
        if ($this->shouldUseTransaction()) {
            DB::transaction(function () use ($batch) {
                $this->executeBatch($batch);
            });
        } else {
            $this->executeBatch($batch);
        }
    }

    /**
     * Execute a batch by calling handle() for each item.
     */
    protected function executeBatch(Collection $batch): void
    {
        $batch->each(function ($item) {
            if (is_array($item)) {
                $this->callMethod('handle', $item);
            } else {
                $this->callMethod('handle', [$item]);
            }
        });
    }

    /**
     * Get the batch size for processing.
     */
    protected function getBatchSize(): int
    {
        return $this->fromActionMethodOrProperty(
            'getBatchSize',
            'batchSize',
            100
        );
    }

    /**
     * Determine if batches should be wrapped in transactions.
     */
    protected function shouldUseTransaction(): bool
    {
        return $this->fromActionMethod(
            'shouldUseTransaction',
            [],
            true
        );
    }
}
