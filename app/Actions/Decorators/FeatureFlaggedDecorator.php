<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Laravel\Pennant\Feature;

/**
 * Feature Flagged Decorator
 *
 * Integrates with Laravel Pennant for feature flag support.
 * This decorator intercepts handle() calls and checks feature flags before execution.
 *
 * Features:
 * - Automatic feature flag checking
 * - Fallback action support
 * - Custom feature name generation
 * - Configurable error handling
 * - Works with Laravel Pennant
 *
 * How it works:
 * 1. When an action uses AsFeatureFlagged, FeatureFlaggedDesignPattern recognizes it
 * 2. ActionManager wraps the action with FeatureFlaggedDecorator
 * 3. When handle() is called, the decorator:
 *    - Gets feature name from action
 *    - Checks if feature is active via Pennant
 *    - Executes action if feature is enabled
 *    - Falls back to fallback action or throws/returns null if disabled
 *
 * Benefits:
 * - Feature flag integration
 * - Gradual rollouts
 * - A/B testing support
 * - Fallback actions
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 */
class FeatureFlaggedDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with feature flag check.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public function handle(...$arguments)
    {
        $featureName = $this->getFeatureName();

        if (! Feature::active($featureName)) {
            return $this->handleFeatureDisabled($arguments);
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
     * Handle when feature is disabled.
     */
    protected function handleFeatureDisabled(array $arguments): mixed
    {
        $fallbackAction = $this->getFallbackAction();

        if ($fallbackAction) {
            return app($fallbackAction)->handle(...$arguments);
        }

        // Default: throw exception or return null
        if ($this->shouldThrowOnDisabled()) {
            throw new \RuntimeException("Feature '{$this->getFeatureName()}' is not enabled.");
        }

        return null;
    }

    /**
     * Get the feature name.
     */
    protected function getFeatureName(): string
    {
        return $this->fromActionMethod('getFeatureName', [], $this->generateFeatureNameFromClass());
    }

    /**
     * Generate feature name from class name.
     */
    protected function generateFeatureNameFromClass(): string
    {
        $className = class_basename(get_class($this->action));

        return str($className)
            ->kebab()
            ->lower()
            ->toString();
    }

    /**
     * Get fallback action class name.
     */
    protected function getFallbackAction(): ?string
    {
        return $this->fromActionMethodOrProperty('getFallbackAction', 'fallbackAction', null);
    }

    /**
     * Check if should throw exception when feature is disabled.
     */
    protected function shouldThrowOnDisabled(): bool
    {
        return $this->fromActionMethod('shouldThrowOnDisabled', [], false);
    }
}
