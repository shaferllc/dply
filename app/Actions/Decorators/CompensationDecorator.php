<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorator that provides compensation/rollback support for actions (Saga pattern).
 *
 * This decorator automatically tracks compensation data during action execution
 * and provides methods to rollback operations when needed.
 */
class CompensationDecorator
{
    use DecorateActions;

    protected array $compensationStack = [];

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setCompensationDecorator')) {
            $action->setCompensationDecorator($this);
        } elseif (property_exists($action, '_compensationDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_compensationDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        $result = $this->callMethod('handle', $arguments);

        // Store compensation data for potential rollback
        if ($this->hasMethod('getCompensationData')) {
            $compensationData = $this->callMethod('getCompensationData', $arguments);
            $this->compensationStack[] = [
                'action' => get_class($this->action),
                'data' => $compensationData,
                'arguments' => $arguments,
            ];
        }

        return $result;
    }

    /**
     * Execute compensation for this action with given arguments.
     *
     * @param  mixed  ...$arguments  The arguments to pass to the compensate method
     */
    public function compensate(...$arguments): void
    {
        if ($this->hasMethod('compensate')) {
            $this->callMethod('compensate', $arguments);
        }
    }

    /**
     * Compensate all actions in the stack in reverse order (LIFO).
     */
    public function compensateAll(): void
    {
        foreach (array_reverse($this->compensationStack) as $entry) {
            $actionClass = $entry['action'];
            $action = app($actionClass);

            if (method_exists($action, 'compensate')) {
                $action->compensate(...$entry['arguments']);
            }
        }
    }

    /**
     * Get the compensation stack.
     *
     * @return array<int, array{action: string, data: mixed, arguments: array}>
     */
    public function getCompensationStack(): array
    {
        return $this->compensationStack;
    }

    /**
     * Clear the compensation stack.
     */
    public function clearCompensationStack(): void
    {
        $this->compensationStack = [];
    }

    /**
     * Compensate all actions from a given compensation stack.
     *
     * @param  array<int, array{action: string, data: mixed, arguments: array}>  $compensationStack
     */
    public static function compensateAllFromStack(array $compensationStack): void
    {
        // Execute compensations in reverse order (LIFO)
        foreach (array_reverse($compensationStack) as $entry) {
            $actionClass = $entry['action'];
            $action = app($actionClass);

            if (method_exists($action, 'compensate')) {
                $action->compensate(...$entry['arguments']);
            }
        }
    }
}
