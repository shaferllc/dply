<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * Requires Plan Decorator
 *
 * Ensures actions require a specific plan before execution.
 * This decorator checks if the user's organization has the required plan.
 *
 * Features:
 * - Plan checking
 * - Multiple plan support (OR logic)
 * - Organization context support
 * - Custom error handling
 */
class RequiresPlanDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with plan checking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws HttpResponseException
     */
    public function handle(...$arguments)
    {
        $user = $this->getUser();

        if (! $user) {
            $this->handleUnauthorized('User not authenticated');
        }

        $organization = $this->getOrganization($user);

        if (! $organization) {
            $this->handleUnauthorized('Organization not found');
        }

        $requiredPlans = $this->getRequiredPlans($arguments);

        if (empty($requiredPlans)) {
            return $this->action->handle(...$arguments);
        }

        if (! $this->hasRequiredPlan($organization, $requiredPlans)) {
            $this->handleUnauthorized('Required plan(s): '.implode(', ', $requiredPlans));
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
     * Get the organization from user.
     */
    protected function getOrganization(User $user): mixed
    {
        if (method_exists($user, 'getCurrentOrganization')) {
            return $user->getCurrentOrganization();
        }

        if (method_exists($user, 'currentTeam')) {
            return $user->currentTeam();
        }

        return null;
    }

    /**
     * Get required plans from action.
     */
    protected function getRequiredPlans(array $arguments): array
    {
        $plans = $this->fromActionMethod('getRequiredPlans', $arguments, []);

        if (empty($plans)) {
            $plans = $this->fromActionProperty('requiredPlans', []);
        }

        return is_array($plans) ? $plans : [$plans];
    }

    /**
     * Check if organization has required plan(s).
     */
    protected function hasRequiredPlan(mixed $organization, array $requiredPlans): bool
    {
        if (method_exists($organization, 'plan')) {
            $currentPlan = $organization->plan();

            if (! $currentPlan) {
                return false;
            }

            $currentPlanId = is_string($currentPlan) ? $currentPlan : $currentPlan->getId();

            return in_array($currentPlanId, $requiredPlans);
        }

        // Fallback: check if subscribed to price
        if (method_exists($organization, 'subscribedToPrice')) {
            foreach ($requiredPlans as $plan) {
                if ($organization->subscribedToPrice($plan)) {
                    return true;
                }
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
        $customHandler = $this->fromActionMethod('handleUnauthorizedPlan', [$message]);

        if ($customHandler !== null) {
            return;
        }

        if (request()->expectsJson()) {
            abort(403, $message);
        }

        abort(403, $message);
    }
}
