<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use App\Models\User;
use App\Modules\Teams\Models\Team;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * Requires Role Decorator
 *
 * Ensures actions require specific team role(s) before execution.
 * This decorator checks if the authenticated user has the required role(s)
 * in their current team context.
 *
 * Features:
 * - Team role checking
 * - Multiple role support (OR logic)
 * - Custom error handling
 * - Works with team context
 *
 * How it works:
 * 1. When an action uses AsRequiresRole, RequiresRoleDesignPattern recognizes it
 * 2. ActionManager wraps the action with RequiresRoleDecorator
 * 3. When handle() is called, the decorator:
 *    - Gets required roles from action
 *    - Gets current user and team
 *    - Checks if user has required role(s) in team
 *    - Throws 403 if unauthorized
 *    - Executes action if authorized
 */
class RequiresRoleDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with role checking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws HttpResponseException
     */
    public function handle(...$arguments)
    {
        $user = $this->getUser();
        $team = $this->getTeam($arguments);

        if (! $user || ! $team) {
            $this->handleUnauthorized('User or team not found');
        }

        $requiredRoles = $this->getRequiredRoles($arguments);

        if (empty($requiredRoles)) {
            // No roles required, allow execution
            return $this->action->handle(...$arguments);
        }

        if (! $this->userHasRequiredRole($user, $team, $requiredRoles)) {
            $this->handleUnauthorized('User does not have required role(s): '.implode(', ', $requiredRoles));
        }

        return $this->action->handle(...$arguments);
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
     * Get the authenticated user.
     */
    protected function getUser(): ?User
    {
        return Auth::user();
    }

    /**
     * Get the team from arguments or current team.
     */
    protected function getTeam(array $arguments): ?Team
    {
        // Try to get team from arguments
        foreach ($arguments as $arg) {
            if ($arg instanceof Team) {
                return $arg;
            }
        }

        // Fall back to user's current team
        $user = $this->getUser();
        if ($user && method_exists($user, 'currentTeam')) {
            return $user->currentTeam();
        }

        return null;
    }

    /**
     * Get required roles from action.
     */
    protected function getRequiredRoles(array $arguments): array
    {
        $roles = $this->fromActionMethod('getRequiredRoles', $arguments, []);

        if (empty($roles)) {
            $roles = $this->fromActionProperty('requiredRoles', []);
        }

        return is_array($roles) ? $roles : [$roles];
    }

    /**
     * Check if user has required role(s).
     */
    protected function userHasRequiredRole(User $user, Team $team, array $requiredRoles): bool
    {
        if (! method_exists($user, 'hasTeamRole')) {
            return false;
        }

        // Check if user has any of the required roles (OR logic)
        foreach ($requiredRoles as $role) {
            if ($user->hasTeamRole($team, $role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle unauthorized access.
     *
     * @throws HttpResponseException
     */
    protected function handleUnauthorized(string $message): void
    {
        $customHandler = $this->fromActionMethod('handleUnauthorizedRole', [$message]);

        if ($customHandler !== null) {
            return;
        }

        if (request()->expectsJson()) {
            abort(403, $message);
        }

        abort(403, $message);
    }
}
