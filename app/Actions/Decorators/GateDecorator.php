<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorates actions when used as authorization gates.
 *
 * @example
 * // When an action with AsGate is called via Gate:
 * Gate::allows('view-reports');
 * // Calls the action's handle() method with the current user
 *
 * // This decorator makes the action invokable and routes
 * // the call to handle() method, returning a boolean result.
 */
class GateDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function __invoke(...$arguments): bool
    {
        // Gates typically receive the user as first argument
        // If no user provided, try to inject it
        if (empty($arguments) && auth()->check()) {
            $arguments = [auth()->user()];
        }

        // Try handle() method
        if ($this->hasMethod('handle')) {
            $result = $this->callMethod('handle', $arguments);

            return is_bool($result) ? $result : (bool) $result;
        }

        // Try authorize() method
        if ($this->hasMethod('authorize')) {
            $result = $this->callMethod('authorize', $arguments);

            return is_bool($result) ? $result : (bool) $result;
        }

        // Default: deny access
        return false;
    }
}
