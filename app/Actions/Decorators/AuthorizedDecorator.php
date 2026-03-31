<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Gate;

/**
 * Decorator that requires authorization before action execution.
 *
 * This decorator automatically checks authorization using Laravel's Gate
 * before executing the action. If authorization fails, it calls handleUnauthorized().
 */
class AuthorizedDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $ability = $this->getAuthorizationAbility();
        $authArguments = $this->getAuthorizationArguments(...$arguments);

        if (! Gate::allows($ability, $authArguments)) {
            $this->handleUnauthorized();
        }

        return $this->callMethod('handle', $arguments);
    }

    protected function handleUnauthorized(): void
    {
        if ($this->hasMethod('handleUnauthorized')) {
            $this->callMethod('handleUnauthorized');

            return;
        }

        if (request()->expectsJson()) {
            abort(403, 'This action is unauthorized.');
        }

        abort(403, 'This action is unauthorized.');
    }

    protected function getAuthorizationAbility(): string
    {
        if ($this->hasMethod('getAuthorizationAbility')) {
            return $this->callMethod('getAuthorizationAbility');
        }

        // Default: use action name as ability
        return strtolower(class_basename($this->action));
    }

    protected function getAuthorizationArguments(...$arguments): array
    {
        if ($this->hasMethod('getAuthorizationArguments')) {
            return $this->callMethod('getAuthorizationArguments', $arguments);
        }

        // Default: pass all arguments
        return $arguments;
    }
}
