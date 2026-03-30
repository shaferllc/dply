<?php

namespace App\Actions\Concerns;

use Laravel\Pennant\Feature;

/**
 * Integrates with Laravel Pennant for feature flag support.
 *
 * This trait is a marker that enables automatic feature flag checking via FeatureFlaggedDecorator.
 * When an action uses AsFeatureFlagged, FeatureFlaggedDesignPattern recognizes it and
 * ActionManager wraps the action with FeatureFlaggedDecorator.
 *
 * How it works:
 * 1. Action uses AsFeatureFlagged trait (marker)
 * 2. FeatureFlaggedDesignPattern recognizes the trait
 * 3. ActionManager wraps action with FeatureFlaggedDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets feature name from action
 *    - Checks if feature is active via Laravel Pennant
 *    - Executes action if feature is enabled
 *    - Falls back to fallback action or throws/returns null if disabled
 *
 * Benefits:
 * - Feature flag integration with Laravel Pennant
 * - Gradual feature rollouts
 * - A/B testing support
 * - Fallback action support
 * - Custom feature name generation
 * - Configurable error handling
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * FeatureFlaggedDecorator, which automatically wraps actions and adds feature flag checking.
 * This follows the same pattern as AsLogger, AsLock, AsIdempotent, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getFeatureName()` method to customize feature name
 * - Set `$fallbackAction` property or implement `getFallbackAction()` for fallback
 * - Implement `shouldThrowOnDisabled()` to throw exception instead of returning null
 *
 * Feature Name:
 * - Default: Generated from class name (e.g., "NewPaymentProcessor" -> "new-payment-processor")
 * - Custom: Implement `getFeatureName()` method
 *
 * Fallback Action:
 * - If feature is disabled and fallback is set, fallback action is executed
 * - If no fallback and `shouldThrowOnDisabled()` is true, exception is thrown
 * - If no fallback and `shouldThrowOnDisabled()` is false, null is returned
 *
 * @example
 * // ============================================
 * // Example 1: Basic Feature Flag
 * // ============================================
 * class NewPaymentProcessor extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(Order $order): void
 *     {
 *         // New payment logic
 *     }
 * }
 *
 * // Feature name: "new-payment-processor" (auto-generated)
 * // Only executes if feature is enabled
 * @example
 * // ============================================
 * // Example 2: Custom Feature Name
 * // ============================================
 * class AdvancedSearch extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(string $query): array
 *     {
 *         // Advanced search logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'advanced-search-v2';
 *     }
 * }
 *
 * // Uses custom feature name
 * @example
 * // ============================================
 * // Example 3: With Fallback Action
 * // ============================================
 * class NewPaymentProcessor extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(Order $order): void
 *     {
 *         // New payment logic
 *     }
 *
 *     protected function getFallbackAction(): ?string
 *     {
 *         return OldPaymentProcessor::class;
 *     }
 * }
 *
 * // If feature disabled, falls back to OldPaymentProcessor
 * @example
 * // ============================================
 * // Example 4: Property-Based Fallback
 * // ============================================
 * class NewFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     protected ?string $fallbackAction = OldFeature::class;
 *
 *     public function handle(): void
 *     {
 *         // New feature logic
 *     }
 * }
 *
 * // Fallback defined via property
 * @example
 * // ============================================
 * // Example 5: Throw Exception on Disabled
 * // ============================================
 * class CriticalFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): void
 *     {
 *         // Critical logic
 *     }
 *
 *     protected function shouldThrowOnDisabled(): bool
 *     {
 *         return true; // Throw exception if disabled
 *     }
 * }
 *
 * // Throws RuntimeException if feature is disabled
 * @example
 * // ============================================
 * // Example 6: Gradual Rollout
 * // ============================================
 * class NewDashboard extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): array
 *     {
 *         // New dashboard data
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'new-dashboard';
 *     }
 *
 *     protected function getFallbackAction(): ?string
 *     {
 *         return OldDashboard::class;
 *     }
 * }
 *
 * // Enable for 10% of users via Pennant:
 * // Feature::for($user)->active('new-dashboard')
 * @example
 * // ============================================
 * // Example 7: A/B Testing
 * // ============================================
 * class VariantA extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): string
 *     {
 *         return 'Variant A content';
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'ab-test-variant-a';
 *     }
 * }
 *
 * class VariantB extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): string
 *     {
 *         return 'Variant B content';
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'ab-test-variant-b';
 *     }
 * }
 *
 * // Use Pennant to assign users to variants
 * @example
 * // ============================================
 * // Example 8: User-Specific Features
 * // ============================================
 * class PremiumFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(User $user): void
 *     {
 *         // Premium feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'premium-features';
 *     }
 * }
 *
 * // Enable for specific users via Pennant:
 * // Feature::for($user)->activate('premium-features')
 * @example
 * // ============================================
 * // Example 9: Team-Based Features
 * // ============================================
 * class TeamFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(Team $team): void
 *     {
 *         // Team feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'team-features';
 *     }
 * }
 *
 * // Enable for specific teams via Pennant
 * @example
 * // ============================================
 * // Example 10: Environment-Based Features
 * // ============================================
 * class DevelopmentFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): void
 *     {
 *         // Development-only logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'dev-features';
 *     }
 * }
 *
 * // Enable only in development via Pennant configuration
 * @example
 * // ============================================
 * // Example 11: Percentage Rollout
 * // ============================================
 * class PercentageRolloutFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'percentage-rollout';
 *     }
 * }
 *
 * // Configure in Pennant to enable for X% of users
 * @example
 * // ============================================
 * // Example 12: Combining with Other Decorators
 * // ============================================
 * class ComprehensiveFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsLogger;
 *     use AsLifecycle;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'comprehensive-feature';
 *     }
 * }
 *
 * // All decorators work together:
 * // - FeatureFlaggedDecorator checks feature flag
 * // - LoggerDecorator tracks execution
 * // - LifecycleDecorator provides hooks
 * @example
 * // ============================================
 * // Example 13: Feature with Conditional Logic
 * // ============================================
 * class ConditionalFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(User $user): void
 *     {
 *         // Feature logic that depends on user
 *         if ($user->isPremium()) {
 *             // Premium logic
 *         }
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'conditional-feature';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Feature with Multiple Fallbacks
 * // ============================================
 * class MultiFallbackFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): void
 *     {
 *         // New feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'multi-fallback';
 *     }
 *
 *     protected function getFallbackAction(): ?string
 *     {
 *         // Check another feature flag for fallback
 *         if (Feature::active('intermediate-feature')) {
 *             return IntermediateFeature::class;
 *         }
 *
 *         return OldFeature::class;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Feature with Logging
 * // ============================================
 * class LoggedFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsLogger;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'logged-feature';
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'features';
 *     }
 * }
 *
 * // Feature flag check + execution logging
 * @example
 * // ============================================
 * // Example 16: Feature with Metrics
 * // ============================================
 * class MetricsFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'metrics-feature';
 *     }
 * }
 *
 * // Feature flag check + metrics tracking
 * @example
 * // ============================================
 * // Example 17: Feature with Idempotency
 * // ============================================
 * class IdempotentFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsIdempotent;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'idempotent-feature';
 *     }
 * }
 *
 * // Feature flag check + idempotency protection
 * @example
 * // ============================================
 * // Example 18: Feature with Authorization
 * // ============================================
 * class AuthorizedFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsPermission;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'authorized-feature';
 *     }
 *
 *     protected function getRequiredPermissions(): array
 *     {
 *         return ['use-feature'];
 *     }
 * }
 *
 * // Feature flag check + permission check
 * @example
 * // ============================================
 * // Example 19: Feature with Lifecycle Hooks
 * // ============================================
 * class LifecycleFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsLifecycle;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'lifecycle-feature';
 *     }
 *
 *     protected function beforeHandle(): void
 *     {
 *         // Called before execution (if feature enabled)
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Feature with Job Dispatch
 * // ============================================
 * class JobFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsJob;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'job-feature';
 *     }
 * }
 *
 * // Feature flag check + job dispatch
 * @example
 * // ============================================
 * // Example 21: Feature with Retry Logic
 * // ============================================
 * class RetryFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsRetry;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'retry-feature';
 *     }
 * }
 *
 * // Feature flag check + retry logic
 * @example
 * // ============================================
 * // Example 22: Feature with Timeout
 * // ============================================
 * class TimeoutFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsTimeout;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'timeout-feature';
 *     }
 *
 *     protected function getTimeout(): int
 *     {
 *         return 30; // 30 seconds
 *     }
 * }
 *
 * // Feature flag check + timeout protection
 * @example
 * // ============================================
 * // Example 23: Feature with Throttling
 * // ============================================
 * class ThrottledFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsThrottle;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'throttled-feature';
 *     }
 *
 *     protected function getThrottleKey(): string
 *     {
 *         return 'feature:'.auth()->id();
 *     }
 * }
 *
 * // Feature flag check + rate limiting
 * @example
 * // ============================================
 * // Example 24: Feature with Locking
 * // ============================================
 * class LockedFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsLock;
 *
 *     public function handle(): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'locked-feature';
 *     }
 *
 *     protected function getLockKey(): string
 *     {
 *         return 'feature-lock';
 *     }
 * }
 *
 * // Feature flag check + distributed locking
 * @example
 * // ============================================
 * // Example 25: Feature with Filtering
 * // ============================================
 * class FilteredFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Model::query();
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'filtered-feature';
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'status'];
 *     }
 * }
 *
 * // Feature flag check + query filtering
 * @example
 * // ============================================
 * // Example 26: Feature with API Integration
 * // ============================================
 * class ApiFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         // API logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'api-feature';
 *     }
 * }
 *
 * // Feature flag check + JWT authentication
 * @example
 * // ============================================
 * // Example 27: Feature with Lazy Evaluation
 * // ============================================
 * class LazyFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsLazy;
 *
 *     public function handle(): mixed
 *     {
 *         // Expensive feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'lazy-feature';
 *     }
 * }
 *
 * // Feature flag check + lazy evaluation
 * @example
 * // ============================================
 * // Example 28: Feature with Multiple Checks
 * // ============================================
 * class MultiCheckFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'multi-check';
 *     }
 *
 *     // Additional checks in handle() if needed
 *     public function handle(): void
 *     {
 *         // Check additional conditions
 *         if (! Feature::active('prerequisite-feature')) {
 *             throw new \Exception('Prerequisite feature not enabled');
 *         }
 *
 *         // Feature logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 29: Feature with Testing
 * // ============================================
 * class TestableFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(): string
 *     {
 *         return 'Feature result';
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'testable-feature';
 *     }
 * }
 *
 * // In tests:
 * Feature::activate('testable-feature');
 * $result = TestableFeature::run();
 * expect($result)->toBe('Feature result');
 *
 * Feature::deactivate('testable-feature');
 * $result = TestableFeature::run();
 * expect($result)->toBeNull();
 * @example
 * // ============================================
 * // Example 30: Complex Feature Configuration
 * // ============================================
 * class ComplexFeature extends Actions
 * {
 *     use AsFeatureFlagged;
 *     use AsLogger;
 *     use AsLifecycle;
 *     use AsPermission;
 *
 *     protected ?string $fallbackAction = OldFeature::class;
 *
 *     public function handle(User $user, Data $data): Result
 *     {
 *         // Complex feature logic
 *     }
 *
 *     protected function getFeatureName(): string
 *     {
 *         return 'complex-feature';
 *     }
 *
 *     protected function shouldThrowOnDisabled(): bool
 *     {
 *         return false; // Use fallback instead
 *     }
 *
 *     protected function getRequiredPermissions(): array
 *     {
 *         return ['use-complex-feature'];
 *     }
 *
 *     protected function beforeHandle(User $user, Data $data): void
 *     {
 *         // Lifecycle hook
 *     }
 * }
 *
 * // All decorators work together:
 * // - FeatureFlaggedDecorator checks feature flag
 * // - PermissionDecorator checks permissions
 * // - LoggerDecorator tracks execution
 * // - LifecycleDecorator provides hooks
 */
trait AsFeatureFlagged
{
    // This is a marker trait - the actual feature flag checking is handled by FeatureFlaggedDecorator
    // via the FeatureFlaggedDesignPattern and ActionManager
}
