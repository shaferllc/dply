<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * Requires Subscription Decorator
 *
 * Ensures actions require an active subscription before execution.
 * This decorator checks if the user's organization has an active subscription.
 *
 * Features:
 * - Subscription checking
 * - Organization context support
 * - Custom error handling
 * - Grace period handling
 */
class RequiresSubscriptionDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with subscription checking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws HttpResponseException
     */
    public function handle(...$arguments)
    {
        $user = $this->getUser();

        // Skip subscription check if no user is authenticated
        // This allows registration and other public actions to proceed
        if (! $user) {
            return $this->action->handle(...$arguments);
        }

        $organization = $this->getOrganization($user);

        if (! $organization) {
            $this->handleUnauthorized('Organization not found');
        }

        if (! $this->hasActiveSubscription($organization)) {
            $this->handleUnauthorized('Active subscription required');
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
     * Check if organization has active subscription.
     */
    protected function hasActiveSubscription(mixed $organization): bool
    {
        if (method_exists($organization, 'subscribed')) {
            return $organization->subscribed();
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
        $customHandler = $this->fromActionMethod('handleUnauthorizedSubscription', [$message]);

        if ($customHandler !== null) {
            return;
        }

        if (request()->expectsJson()) {
            abort(403, $message);
        }

        abort(403, $message);
    }
}
