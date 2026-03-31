<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Action Composition - Chain actions together with data passing.
 *
 * Allows actions to be composed together in a fluent chain,
 * where the result of one action is passed to the next.
 *
 * @example
 * // Basic chaining - chain actions together
 * $order = new Order();
 * $result = ValidateOrder::start($order)
 *     ->then(CheckInventory::class)
 *     ->then(ProcessPayment::class)
 *     ->then(SendConfirmation::class)
 *     ->execute();
 * @example
 * // Using pipe() method (alias for then)
 * $result = ValidateOrder::start($order)
 *     ->pipe(CheckInventory::class)
 *     ->pipe(ProcessPayment::class)
 *     ->execute();
 * @example
 * // Continue on failure - execute remaining actions even if one fails
 * $result = ActionComposition::start($order)
 *     ->then(ValidateOrder::class)
 *     ->then(CheckInventory::class)
 *     ->continueOnFailure()
 *     ->then(ProcessPayment::class) // Runs even if previous step fails
 *     ->then(SendConfirmation::class)
 *     ->execute();
 * @example
 * // Passing additional arguments to actions
 * class ApplyDiscount extends Actions
 * {
 *     public function handle(Order $order, float $discountPercent): Order
 *     {
 *         $order->applyDiscount($discountPercent);
 *         return $order;
 *     }
 * }
 *
 * $result = ValidateOrder::start($order)
 *     ->then(ApplyDiscount::class, 10.0) // Pass 10.0 as 2nd argument
 *     ->then(ProcessPayment::class)
 *     ->execute();
 * @example
 * // Using callables in composition
 * $result = ActionComposition::start($order)
 *     ->then(function ($order) {
 *         $order->status = 'validated';
 *         return $order;
 *     })
 *     ->then(ProcessPayment::class)
 *     ->execute();
 * @example
 * // Stop on failure (default behavior, can be explicitly set)
 * $result = ActionComposition::start($order)
 *     ->then(ValidateOrder::class)
 *     ->stopOnFailure() // Explicitly stop on failure
 *     ->then(ProcessPayment::class) // Won't run if ValidateOrder fails
 *     ->execute();
 */
class ActionComposition
{
    protected mixed $value;

    protected array $actions = [];

    protected bool $stopOnFailure = true;

    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    /**
     * Create a new composition starting with a value.
     */
    public static function start(mixed $value): self
    {
        return new self($value);
    }

    /**
     * Chain another action to execute after the current one.
     *
     * @param  string|callable  $action  Action class name or callable
     * @param  mixed  ...$additionalArgs  Additional arguments to pass
     */
    public function then(string|callable $action, ...$additionalArgs): self
    {
        $this->actions[] = [
            'action' => $action,
            'additionalArgs' => $additionalArgs,
        ];

        return $this;
    }

    /**
     * Pipe the value through an action (alias for then).
     */
    public function pipe(string|callable $action, ...$additionalArgs): self
    {
        return $this->then($action, ...$additionalArgs);
    }

    /**
     * Continue execution even if an action fails.
     */
    public function continueOnFailure(): self
    {
        $this->stopOnFailure = false;

        return $this;
    }

    /**
     * Stop execution on failure (default behavior).
     */
    public function stopOnFailure(): self
    {
        $this->stopOnFailure = true;

        return $this;
    }

    /**
     * Execute the composition chain.
     *
     * @return mixed The final result after all actions have executed
     */
    public function execute(): mixed
    {
        $value = $this->value;

        foreach ($this->actions as $item) {
            try {
                $value = $this->executeAction($item['action'], $value, $item['additionalArgs']);
            } catch (\Throwable $e) {
                if ($this->stopOnFailure) {
                    throw $e;
                }

                // Continue with previous value on failure
            }
        }

        return $value;
    }

    /**
     * Execute a single action in the chain.
     */
    protected function executeAction(string|callable $action, mixed $value, array $additionalArgs): mixed
    {
        if (is_callable($action)) {
            return $action($value, ...$additionalArgs);
        }

        // If it's an action class, instantiate and call handle
        if (class_exists($action) && is_subclass_of($action, Actions::class)) {
            $instance = app($action);

            return $instance->handle($value, ...$additionalArgs);
        }

        throw new \InvalidArgumentException("Action '{$action}' is not a valid action class or callable.");
    }
}
