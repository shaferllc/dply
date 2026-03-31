<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * Permission Decorator
 *
 * Automatically checks user permissions before allowing action execution.
 * This decorator intercepts handle() calls and verifies the user has
 * required permissions before executing the action.
 *
 * Features:
 * - Automatic permission checking
 * - Support for multiple permissions (AND/OR logic)
 * - Custom permission retrieval
 * - Unauthorized access handling
 * - Works with various permission systems
 *
 * How it works:
 * 1. When an action uses AsPermission, PermissionDesignPattern recognizes it
 * 2. ActionManager wraps the action with PermissionDecorator
 * 3. When handle() is called, the decorator:
 *    - Gets required permissions from action
 *    - Gets current user permissions
 *    - Checks if user has required permissions
 *    - Throws 403 exception if unauthorized
 *    - Executes the action if authorized
 *    - Adds permission metadata to result
 *
 * Permission Metadata:
 * The result will include a `_permission` property with:
 * - `checked`: Whether permission check was performed
 * - `required`: Required permissions array
 * - `user_permissions`: User's permissions (if available)
 * - `authorized`: Whether user was authorized
 */
class PermissionDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with permission checking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws HttpResponseException If unauthorized
     */
    public function handle(...$arguments)
    {
        $requiredPermissions = $this->getRequiredPermissions();
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        if (! empty($requiredPermissions) && ! $this->hasRequiredPermissions($user, $requiredPermissions)) {
            $this->handleUnauthorizedPermission();
        }

        // Execute the action
        $result = $this->action->handle(...$arguments);

        // Add permission metadata to result
        if (is_object($result)) {
            $result->_permission = [
                'checked' => true,
                'required' => $requiredPermissions,
                'authorized' => true,
            ];
        }

        return $result;
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Check if user has required permissions.
     *
     * @param  mixed  $user
     */
    protected function hasRequiredPermissions($user, array $requiredPermissions): bool
    {
        $userPermissions = $this->getUserPermissions($user);

        if ($this->requiresAllPermissions()) {
            // User must have ALL required permissions
            return count(array_intersect($requiredPermissions, $userPermissions)) === count($requiredPermissions);
        }

        // User must have ANY required permission
        return ! empty(array_intersect($requiredPermissions, $userPermissions));
    }

    /**
     * Get user's permissions.
     *
     * @param  mixed  $user
     */
    protected function getUserPermissions($user): array
    {
        // Try common permission methods
        if (method_exists($user, 'getAllPermissions')) {
            return $user->getAllPermissions()->pluck('name')->toArray();
        }

        if (method_exists($user, 'getPermissionNames')) {
            return $user->getPermissionNames()->toArray();
        }

        if (method_exists($user, 'permissions')) {
            return $user->permissions()->pluck('name')->toArray();
        }

        // Try via roles
        if (method_exists($user, 'getPermissionsViaRoles')) {
            return $user->getPermissionsViaRoles()->pluck('name')->toArray();
        }

        // Try hasPermissionTo or can methods
        if (method_exists($user, 'hasPermissionTo')) {
            // For Spatie Permission package
            return $user->getAllPermissions()->pluck('name')->toArray();
        }

        return [];
    }

    /**
     * Handle unauthorized permission access.
     *
     *
     * @throws HttpResponseException
     */
    protected function handleUnauthorizedPermission(): void
    {
        if ($this->hasMethod('handleUnauthorizedPermission')) {
            $this->callMethod('handleUnauthorizedPermission');

            return;
        }

        if (request()->expectsJson()) {
            abort(403, 'You do not have the required permission(s) to perform this action.');
        }

        abort(403, 'You do not have the required permission(s) to perform this action.');
    }

    /**
     * Get required permissions from the action.
     */
    protected function getRequiredPermissions(): array
    {
        if ($this->hasMethod('getRequiredPermissions')) {
            return (array) $this->callMethod('getRequiredPermissions');
        }

        if ($this->hasProperty('requiredPermissions')) {
            return (array) $this->getProperty('requiredPermissions');
        }

        return [];
    }

    /**
     * Check if action requires all permissions (AND) or any permission (OR).
     */
    protected function requiresAllPermissions(): bool
    {
        if ($this->hasMethod('requiresAllPermissions')) {
            return (bool) $this->callMethod('requiresAllPermissions');
        }

        if ($this->hasProperty('requiresAllPermissions')) {
            return (bool) $this->getProperty('requiresAllPermissions');
        }

        return false; // Default: OR (any permission)
    }
}
