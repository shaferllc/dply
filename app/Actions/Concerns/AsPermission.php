<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Requires specific permission(s) before action execution.
 *
 * Provides permission checking capabilities for actions, preventing unauthorized
 * access by verifying user permissions before action execution. Throws 403
 * exceptions when permissions are not met.
 *
 * How it works:
 * - PermissionDesignPattern recognizes actions using AsPermission
 * - ActionManager wraps the action with PermissionDecorator
 * - When handle() is called, the decorator:
 *    - Gets required permissions from action
 *    - Gets current user's permissions
 *    - Checks if user has required permissions (AND/OR logic)
 *    - Throws 403 exception if unauthorized
 *    - Executes the action if authorized
 *    - Adds permission metadata to result
 *
 * Benefits:
 * - Automatic permission checking
 * - Support for multiple permissions
 * - AND/OR permission logic
 * - Works with various permission systems
 * - Custom unauthorized handling
 * - Permission metadata in results
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * PermissionDecorator, which automatically wraps actions and checks permissions.
 * This follows the same pattern as AsTimeout, AsThrottle, and other
 * decorator-based concerns.
 *
 * Permission Metadata:
 * The result will include a `_permission` property with:
 * - `checked`: Whether permission check was performed
 * - `required`: Required permissions array
 * - `authorized`: Whether user was authorized
 *
 * Permission Systems Supported:
 * - Spatie Laravel Permission (getAllPermissions, hasPermissionTo)
 * - Custom permission methods (getPermissionNames, permissions)
 * - Role-based permissions (getPermissionsViaRoles)
 *
 * @example
 * // Basic usage - single permission:
 * class DeleteUser extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(User $user): void
 *     {
 *         $user->delete();
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['users.delete'];
 *     }
 * }
 *
 * // Usage:
 * DeleteUser::run($user);
 * // Automatically checks if user has 'users.delete' permission
 * // Throws 403 if user doesn't have permission
 * @example
 * // Multiple permissions with OR logic (default):
 * class ManageTeam extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(Team $team): void
 *     {
 *         // Manage team
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['teams.manage', 'teams.admin', 'teams.owner'];
 *     }
 *
 *     // Default: requiresAllPermissions() returns false
 *     // User needs ANY of these permissions
 * }
 *
 * // Usage:
 * ManageTeam::run($team);
 * // User needs 'teams.manage' OR 'teams.admin' OR 'teams.owner'
 * @example
 * // Multiple permissions with AND logic:
 * class CriticalOperation extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(): void
 *     {
 *         // Critical operation
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['operations.execute', 'operations.critical'];
 *     }
 *
 *     public function requiresAllPermissions(): bool
 *     {
 *         return true; // User must have ALL permissions
 *     }
 * }
 *
 * // Usage:
 * CriticalOperation::run();
 * // User needs BOTH 'operations.execute' AND 'operations.critical'
 * @example
 * // Using properties for configuration:
 * class ProcessOrder extends Actions
 * {
 *     use AsPermission;
 *
 *     // Configure via properties
 *     public array $requiredPermissions = ['orders.process'];
 *     public bool $requiresAllPermissions = false;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessOrder::make();
 * $action->requiredPermissions = ['orders.process', 'orders.manage'];
 * $action->handle($order);
 * @example
 * // Custom unauthorized handling:
 * class RestrictedAction extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(): void
 *     {
 *         // Restricted operation
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['restricted.access'];
 *     }
 *
 *     public function handleUnauthorizedPermission(): void
 *     {
 *         // Custom handling instead of default 403
 *         \Log::warning('Unauthorized access attempt', [
 *             'user_id' => auth()->id(),
 *             'permission' => 'restricted.access',
 *         ]);
 *
 *         // Redirect to upgrade page
 *         redirect()->route('upgrade')->send();
 *     }
 * }
 *
 * // Usage:
 * RestrictedAction::run();
 * // Custom unauthorized handling is called instead of default 403
 * @example
 * // Permission checking with Spatie Laravel Permission:
 * class AdminAction extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(): void
 *     {
 *         // Admin-only operation
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['admin.access'];
 *     }
 * }
 *
 * // Usage (works with Spatie Permission):
 * AdminAction::run();
 * // Automatically uses Spatie's getAllPermissions() method
 * @example
 * // Permission checking with roles:
 * class ManagerAction extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(): void
 *     {
 *         // Manager operation
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['manager.access', 'admin.access'];
 *     }
 * }
 *
 * // Usage:
 * ManagerAction::run();
 * // Checks permissions via roles if user has getPermissionsViaRoles() method
 * @example
 * // Dynamic permissions based on context:
 * class ContextualAction extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(Team $team): void
 *     {
 *         // Team-specific operation
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         // Dynamic permissions based on team
 *         $team = $this->getTeamFromArguments();
 *
 *         return match ($team->tier) {
 *             'enterprise' => ['teams.enterprise.manage'],
 *             'professional' => ['teams.professional.manage'],
 *             default => ['teams.basic.manage'],
 *         };
 *     }
 *
 *     protected function getTeamFromArguments(): ?Team
 *     {
 *         // Extract team from arguments
 *         return null; // Implementation
 *     }
 * }
 * @example
 * // Permission checking in API endpoints:
 * class ApiEndpoint extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(Request $request): array
 *     {
 *         return ['data' => 'processed'];
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['api.access'];
 *     }
 *
 *     public function handleUnauthorizedPermission(): void
 *     {
 *         // API-specific error response
 *         abort(403, [
 *             'error' => 'Forbidden',
 *             'message' => 'You do not have the required permission to access this endpoint.',
 *             'required_permissions' => $this->getRequiredPermissions(),
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * ApiEndpoint::run($request);
 * // Returns JSON error response for API requests
 * @example
 * // Permission metadata in results:
 * class TrackedAction extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(): array
 *     {
 *         return ['success' => true];
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['tracked.action'];
 *     }
 * }
 *
 * // Usage:
 * $result = TrackedAction::run();
 *
 * // Access permission metadata:
 * if (isset($result->_permission)) {
 *     $checked = $result->_permission['checked'];
 *     $required = $result->_permission['required'];
 *     $authorized = $result->_permission['authorized'];
 * }
 * // $result->_permission = ['checked' => true, 'required' => ['tracked.action'], 'authorized' => true]
 * @example
 * // Combining with other decorators:
 * class ComprehensiveAction extends Actions
 * {
 *     use AsPermission;
 *     use AsRetry;
 *     use AsTimeout;
 *
 *     public function handle(): void
 *     {
 *         // Operation that needs permission, retry, and timeout
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['comprehensive.action'];
 *     }
 * }
 *
 * // Usage:
 * ComprehensiveAction::run();
 * // Combines permission checking, retry, and timeout decorators
 * @example
 * // Permission checking with custom user retrieval:
 * class CustomPermissionAction extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(): void
 *     {
 *         // Custom operation
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['custom.permission'];
 *     }
 *
 *     // Override getUserPermissions if needed (handled by decorator)
 *     // The decorator automatically detects common permission methods
 * }
 * @example
 * // Permission checking in Livewire components:
 * class LivewireAction extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(): void
 *     {
 *         // Livewire operation
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['livewire.action'];
 *     }
 * }
 *
 * // Livewire Component:
 * class LivewireComponent extends Component
 * {
 *     public function performAction(): void
 *     {
 *         LivewireAction::run();
 *         // Permission is automatically checked
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.component');
 *     }
 * }
 *
 * // Permission checking works seamlessly with Livewire
 */
trait AsPermission
{
    // This trait is now just a marker trait.
    // The actual permission checking logic is handled by PermissionDecorator
    // which is automatically applied via PermissionDesignPattern.

    /**
     * Get required permissions for this action.
     * Override this method to define required permissions.
     */
    protected function getRequiredPermissions(): array
    {
        if ($this->hasProperty('requiredPermissions')) {
            return (array) $this->getProperty('requiredPermissions');
        }

        return [];
    }

    /**
     * Check if action requires all permissions (AND) or any permission (OR).
     * Override this method to change the logic.
     */
    protected function requiresAllPermissions(): bool
    {
        if ($this->hasProperty('requiresAllPermissions')) {
            return (bool) $this->getProperty('requiresAllPermissions');
        }

        return false; // Default: OR (any permission)
    }

    /**
     * Handle unauthorized permission access.
     * Override this method for custom unauthorized handling.
     */
    protected function handleUnauthorizedPermission(): void
    {
        if (request()->expectsJson()) {
            abort(403, 'You do not have the required permission(s) to perform this action.');
        }

        abort(403, 'You do not have the required permission(s) to perform this action.');
    }
}
