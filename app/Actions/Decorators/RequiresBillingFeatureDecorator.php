<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use App\Models\User;
use App\Modules\Billing\Services\FeatureGate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * Requires Billing Feature Decorator
 *
 * Ensures actions require access to specific billing feature(s) before execution.
 * This decorator checks if the user can access the required feature(s) via FeatureGate.
 *
 * Features:
 * - Billing feature checking via FeatureGate
 * - Multiple feature support (AND/OR logic)
 * - Custom error handling
 * - Works with billing plans
 */
class RequiresBillingFeatureDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with billing feature checking.
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

        $requiredFeatures = $this->getRequiredFeatures($arguments);
        $requireAll = $this->getRequireAllFeatures();

        if (empty($requiredFeatures)) {
            return $this->action->handle(...$arguments);
        }

        if (! $this->userCanAccessFeatures($user, $requiredFeatures, $requireAll)) {
            $logic = $requireAll ? 'all' : 'any';
            $this->handleUnauthorized('User cannot access required feature(s): '.implode(', ', $requiredFeatures)." (requires {$logic})");
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
     * Get required features from action.
     */
    protected function getRequiredFeatures(array $arguments): array
    {
        $features = $this->fromActionMethod('getRequiredFeatures', $arguments, []);

        if (empty($features)) {
            $features = $this->fromActionProperty('requiredFeatures', []);
        }

        return is_array($features) ? $features : [$features];
    }

    /**
     * Check if all features are required (AND logic) or any (OR logic).
     */
    protected function getRequireAllFeatures(): bool
    {
        return $this->fromActionMethodOrProperty('getRequireAllFeatures', 'requireAllFeatures', false);
    }

    /**
     * Check if user can access required feature(s).
     */
    protected function userCanAccessFeatures(User $user, array $requiredFeatures, bool $requireAll): bool
    {
        $featureGate = app(FeatureGate::class);

        if ($requireAll) {
            // User must have access to ALL features
            foreach ($requiredFeatures as $feature) {
                if (! $featureGate->canAccessFeature($user, $feature)) {
                    return false;
                }
            }

            return true;
        } else {
            // User must have access to ANY feature
            foreach ($requiredFeatures as $feature) {
                if ($featureGate->canAccessFeature($user, $feature)) {
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
        $customHandler = $this->fromActionMethod('handleUnauthorizedFeature', [$message]);

        if ($customHandler !== null) {
            return;
        }

        if (request()->expectsJson()) {
            abort(403, $message);
        }

        abort(403, $message);
    }
}
