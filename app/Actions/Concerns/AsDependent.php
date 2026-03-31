<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\ActionRegistry;

/**
 * Marks an action as having dependencies on other actions.
 *
 * This trait allows actions to declare their dependencies, which can be
 * used for validation, documentation, and dependency graph generation.
 *
 * Dependencies are automatically registered when the action is instantiated,
 * and can be queried using ActionRegistry::getDependencies().
 *
 * @example
 * // Basic usage - declare dependencies
 * class ProcessOrder extends Actions
 * {
 *     use AsDependent;
 *
 *     protected function getDependencies(): array
 *     {
 *         return [
 *             ValidateOrder::class,
 *             CheckInventory::class,
 *             VerifyPayment::class,
 *         ];
 *     }
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Process order
 *         return $order;
 *     }
 * }
 *
 * // Dependencies are automatically registered
 * $deps = ActionRegistry::getDependencies(ProcessOrder::class);
 * @example
 * // Complex dependency chain
 * class GenerateReport extends Actions
 * {
 *     use AsDependent;
 *
 *     protected function getDependencies(): array
 *     {
 *         return [
 *             FetchData::class,
 *             ProcessData::class,
 *             FormatReport::class,
 *         ];
 *     }
 *
 *     public function handle(array $params): Report
 *     {
 *         return new Report();
 *     }
 * }
 * @example
 * // No dependencies (empty array)
 * class SimpleAction extends Actions
 * {
 *     use AsDependent;
 *
 *     protected function getDependencies(): array
 *     {
 *         return []; // No dependencies
 *     }
 *
 *     public function handle(string $data): string
 *     {
 *         return strtoupper($data);
 *     }
 * }
 * @example
 * // Query dependencies after registration
 * $processOrder = new ProcessOrder();
 * // Dependencies are now registered
 *
 * $dependencies = ActionRegistry::getDependencies(ProcessOrder::class);
 * // Returns: [ValidateOrder::class, CheckInventory::class, VerifyPayment::class]
 *
 * $dependents = ActionRegistry::getDependents(ValidateOrder::class);
 * // Returns: [ProcessOrder::class] (if ProcessOrder depends on ValidateOrder)
 */
trait AsDependent
{
    /**
     * Get the dependencies for this action.
     *
     * @return array<string> Array of action class names this action depends on
     */
    protected function getDependencies(): array
    {
        return [];
    }

    /**
     * Register dependencies when action is instantiated.
     *
     * Note: This trait does not call parent::__construct() to avoid conflicts
     * when used in classes without a parent. If your class extends another
     * class with a constructor, you should call parent::__construct() in
     * your own constructor after using this trait.
     */
    public function __construct(...$arguments)
    {
        $dependencies = $this->getDependencies();
        if (! empty($dependencies)) {
            ActionRegistry::registerDependencies(static::class, $dependencies);
        }
    }
}
