<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorator that executes actions only if conditions are met.
 *
 * This decorator automatically checks conditions before executing actions
 * and provides a way to handle skipped executions.
 */
class ConditionalDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setConditionalDecorator')) {
            $action->setConditionalDecorator($this);
        } elseif (property_exists($action, '_conditionalDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_conditionalDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        if (! $this->shouldExecute(...$arguments)) {
            return $this->onSkipped(...$arguments);
        }

        return $this->callMethod('handle', $arguments);
    }

    /**
     * Determine if the action should execute.
     *
     * @param  mixed  ...$arguments  The arguments passed to handle()
     */
    protected function shouldExecute(...$arguments): bool
    {
        if ($this->hasMethod('shouldExecute')) {
            return $this->callMethod('shouldExecute', $arguments);
        }

        return true; // Default: always execute
    }

    /**
     * Handle when the action is skipped.
     *
     * @param  mixed  ...$arguments  The arguments passed to handle()
     * @return mixed
     */
    protected function onSkipped(...$arguments)
    {
        if ($this->hasMethod('onSkipped')) {
            return $this->callMethod('onSkipped', $arguments);
        }

        return null;
    }
}
