<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Decorates actions when used as authorization policies.
 *
 * @example
 * // When an action with AsPolicy is called via Gate:
 * Gate::authorize('update', $post);
 * // Calls $policy->update($user, $post)
 *
 * // This decorator handles dynamic method calls (update, delete, etc.)
 * // and routes them to the corresponding methods on the action.
 * // Also supports before() method for global policy checks.
 */
class PolicyDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function __call($method, $arguments)
    {
        // Policy methods are called dynamically (e.g., $policy->update($user, $post))
        if ($this->hasMethod($method)) {
            $result = $this->callMethod($method, $arguments);

            // Policies should return boolean, but handle other return types
            if (is_bool($result)) {
                return $result;
            }

            // Convert truthy/falsy to boolean
            return (bool) $result;
        }

        // Fallback to handle method with method name as first argument
        if ($this->hasMethod('handle')) {
            $result = $this->callMethod('handle', array_merge([$method], $arguments));

            return is_bool($result) ? $result : (bool) $result;
        }

        // Default: deny access if method doesn't exist
        return false;
    }

    public function before(?Authorizable $user, string $ability, ...$arguments): ?bool
    {
        if ($this->hasMethod('before')) {
            $result = $this->callMethod('before', array_merge([$user, $ability], $arguments));

            return is_bool($result) ? $result : null;
        }

        return null;
    }

    public function after(?Authorizable $user, string $ability, $result, ...$arguments): ?bool
    {
        if ($this->hasMethod('after')) {
            $result = $this->callMethod('after', array_merge([$user, $ability, $result], $arguments));

            return is_bool($result) ? $result : null;
        }

        return null;
    }
}
