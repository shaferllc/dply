<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use App\Models\User;
use App\Modules\Roles\Services\RolesService;
use App\Modules\Teams\Models\Team;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * Requires Capability Decorator
 *
 * Ensures actions require specific capability(ies) before execution.
 * This decorator checks if the authenticated user has the required capability(ies)
 * in their current team context.
 *
 * Features:
 * - Capability checking via RolesService
 * - Multiple capability support (AND/OR logic)
 * - Custom error handling
 * - Works with team context
 */
class RequiresCapabilityDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with capability checking.
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

        $requiredCapabilities = $this->getRequiredCapabilities($arguments);
        $requireAll = $this->getRequireAllCapabilities();

        if (empty($requiredCapabilities)) {
            return $this->action->handle(...$arguments);
        }

        if (! $this->userHasRequiredCapabilities($user, $team, $requiredCapabilities, $requireAll)) {
            $logic = $requireAll ? 'all' : 'any';
            $this->handleUnauthorized('User does not have required capability(ies): '.implode(', ', $requiredCapabilities)." (requires {$logic})");
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
        foreach ($arguments as $arg) {
            if ($arg instanceof Team) {
                return $arg;
            }
        }

        $user = $this->getUser();
        if ($user && method_exists($user, 'currentTeam')) {
            return $user->currentTeam();
        }

        return null;
    }

    /**
     * Get required capabilities from action.
     */
    protected function getRequiredCapabilities(array $arguments): array
    {
        $capabilities = $this->fromActionMethod('getRequiredCapabilities', $arguments, []);

        if (empty($capabilities)) {
            $capabilities = $this->fromActionProperty('requiredCapabilities', []);
        }

        return is_array($capabilities) ? $capabilities : [$capabilities];
    }

    /**
     * Check if all capabilities are required (AND logic) or any (OR logic).
     */
    protected function getRequireAllCapabilities(): bool
    {
        return $this->fromActionMethodOrProperty('getRequireAllCapabilities', 'requireAllCapabilities', false);
    }

    /**
     * Check if user has required capability(ies).
     */
    protected function userHasRequiredCapabilities(User $user, Team $team, array $requiredCapabilities, bool $requireAll): bool
    {
        $service = app(RolesService::class);

        if ($requireAll) {
            // User must have ALL capabilities
            foreach ($requiredCapabilities as $capability) {
                if (! $service->hasCapability($user, $capability, $team)) {
                    return false;
                }
            }

            return true;
        } else {
            // User must have ANY capability
            foreach ($requiredCapabilities as $capability) {
                if ($service->hasCapability($user, $capability, $team)) {
                    return true;
                }
            }

            return false;
        }
    }

    /**
     * Handle unauthorized access.
     *
     * @throws HttpResponseException
     */
    protected function handleUnauthorized(string $message): void
    {
        $customHandler = $this->fromActionMethod('handleUnauthorizedCapability', [$message]);

        if ($customHandler !== null) {
            return;
        }

        if (request()->expectsJson()) {
            abort(403, $message);
        }

        abort(403, $message);
    }
}
