<?php

namespace App\Actions\Concerns;

/**
 * Requires access to specific billing feature(s) before action execution.
 *
 * This trait is a marker that enables automatic billing feature checking via RequiresBillingFeatureDecorator.
 * When an action uses AsRequiresBillingFeature, RequiresBillingFeatureDesignPattern recognizes it and
 * ActionManager wraps the action with RequiresBillingFeatureDecorator.
 *
 * How it works:
 * 1. Action uses AsRequiresBillingFeature trait (marker)
 * 2. RequiresBillingFeatureDesignPattern recognizes the trait
 * 3. ActionManager wraps action with RequiresBillingFeatureDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets required features from action
 *    - Gets current user
 *    - Checks if user can access feature(s) via FeatureGate
 *    - Supports AND/OR logic
 *    - Throws 403 if unauthorized
 *    - Executes action if authorized
 *
 * Benefits:
 * - Automatic billing feature checking
 * - FeatureGate integration
 * - Multiple feature support (AND/OR logic)
 * - Custom error handling
 * - Works with billing plans
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * RequiresBillingFeatureDecorator, which automatically wraps actions and checks feature access.
 * This follows the same pattern as AsRequiresRole, AsRequiresPlan, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getRequiredFeatures(...$arguments)` to specify required features
 * - Set `$requiredFeatures` property as array of feature IDs
 * - Implement `getRequireAllFeatures()` or set `$requireAllFeatures` for AND logic
 * - Implement `handleUnauthorizedFeature(string $message)` for custom error handling
 *
 * Feature Checking:
 * - Default: OR logic (user needs access to ANY feature)
 * - With requireAll: AND logic (user needs access to ALL features)
 * - Uses FeatureGate::canAccessFeature() for checking
 * - Checks plan availability, quotas, and permissions
 *
 * @example
 * // ============================================
 * // Example 1: Basic Feature Requirement
 * // ============================================
 * class AdvancedAnalyticsAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     public function handle(): array
 *     {
 *         return ['analytics' => 'data'];
 *     }
 *
 *     protected function getRequiredFeatures(): array
 *     {
 *         return ['advanced-analytics'];
 *     }
 * }
 *
 * // User must have access to 'advanced-analytics' feature
 * @example
 * // ============================================
 * // Example 2: Property-Based Features
 * // ============================================
 * class ApiAccessAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['api-access', 'api-premium'];
 *
 *     public function handle(): array
 *     {
 *         return ['api' => 'data'];
 *     }
 * }
 *
 * // User needs access to ANY of these features (OR logic)
 * @example
 * // ============================================
 * // Example 3: AND Logic (Require All)
 * // ============================================
 * class ComplexFeatureAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['api-access', 'advanced-analytics'];
 *     protected bool $requireAllFeatures = true;
 *
 *     public function handle(): void
 *     {
 *         // User must have access to BOTH features
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Method-Based AND Logic
 * // ============================================
 * class MethodBasedAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['api-access', 'advanced-analytics'];
 *
 *     protected function getRequireAllFeatures(): bool
 *     {
 *         return true; // Require ALL features
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Dynamic Features
 * // ============================================
 * class DynamicFeatureAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     public function handle(string $operation): void
 *     {
 *         // Operation logic
 *     }
 *
 *     protected function getRequiredFeatures(...$arguments): array
 *     {
 *         $operation = $arguments[0] ?? null;
 *
 *         // Different features for different operations
 *         if ($operation === 'export') {
 *             return ['data-export', 'advanced-features'];
 *         }
 *
 *         return ['basic-features'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Custom Error Handling
 * // ============================================
 * class CustomErrorAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['premium-feature'];
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function handleUnauthorizedFeature(string $message): void
 *     {
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'error' => 'Feature access required',
 *                 'message' => $message,
 *                 'required_features' => $this->requiredFeatures,
 *                 'upgrade_url' => route('billing.upgrade'),
 *             ], 403)->send();
 *             exit;
 *         }
 *
 *         redirect()->route('billing.features')
 *             ->with('error', 'Please upgrade to access this feature.')
 *             ->send();
 *         exit;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Combining with Plan Check
 * // ============================================
 * class PlanAndFeatureAction extends Actions
 * {
 *     use AsRequiresPlan;
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredPlans = ['professional'];
 *
 *     protected function getRequiredFeatures(): array
 *     {
 *         return ['advanced-analytics'];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Requires plan AND feature access
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Combining with Subscription
 * // ============================================
 * class SubscriptionAndFeatureAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsRequiresBillingFeature;
 *
 *     protected function getRequiredFeatures(): array
 *     {
 *         return ['premium-features'];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Requires subscription AND feature access
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Multiple Features (OR)
 * // ============================================
 * class FlexibleFeatureAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = [
 *         'api-access',
 *         'api-premium',
 *         'api-enterprise',
 *     ];
 *
 *     public function handle(): void
 *     {
 *         // User needs access to ANY of these features
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: Comprehensive Protection
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *     use AsRequiresPlan;
 *     use AsRequiresRole;
 *     use AsLogger;
 *
 *     protected array $requiredFeatures = ['enterprise-features'];
 *     protected array $requiredPlans = ['enterprise'];
 *     protected array $requiredRoles = ['Admin'];
 *
 *     public function handle(): void
 *     {
 *         // All checks must pass
 *     }
 * }
 *
 * // Feature + Plan + Role + Logging
 * @example
 * // ============================================
 * // Example 11: API Access Feature
 * // ============================================
 * class ApiAccessAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['api-access'];
 *
 *     public function handle(): array
 *     {
 *         return ['api' => 'data'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Custom Domain Feature
 * // ============================================
 * class CustomDomainAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['custom-domain'];
 *
 *     public function handle(string $domain): void
 *     {
 *         // Custom domain setup
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Advanced Analytics Feature
 * // ============================================
 * class AdvancedAnalyticsAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['advanced-analytics'];
 *
 *     public function handle(): array
 *     {
 *         return ['analytics' => 'advanced'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: White Label Feature
 * // ============================================
 * class WhiteLabelAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['white-label'];
 *
 *     public function handle(array $branding): void
 *     {
 *         // White label customization
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: SSO Feature
 * // ============================================
 * class SsoAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['sso'];
 *
 *     public function handle(array $ssoConfig): void
 *     {
 *         // SSO setup
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Data Export Feature
 * // ============================================
 * class DataExportAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['data-export'];
 *
 *     public function handle(Team $team): void
 *     {
 *         // Data export
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Priority Support Feature
 * // ============================================
 * class PrioritySupportAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['priority-support'];
 *
 *     public function handle(string $ticket): void
 *     {
 *         // Priority support
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Multiple Features (AND)
 * // ============================================
 * class MultiFeatureAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['api-access', 'advanced-analytics'];
 *     protected bool $requireAllFeatures = true;
 *
 *     public function handle(): void
 *     {
 *         // User must have BOTH features
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Feature with Quota Check
 * // ============================================
 * class QuotaFeatureAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *
 *     protected array $requiredFeatures = ['api-calls'];
 *
 *     public function handle(): void
 *     {
 *         // FeatureGate checks quota automatically
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Feature with Usage Tracking
 * // ============================================
 * class UsageTrackedFeatureAction extends Actions
 * {
 *     use AsRequiresBillingFeature;
 *     use AsLogger;
 *
 *     protected array $requiredFeatures = ['metered-feature'];
 *
 *     public function handle(): void
 *     {
 *         // Feature access + usage tracking
 *     }
 * }
 */
trait AsRequiresBillingFeature
{
    // This is a marker trait - the actual feature checking is handled by RequiresBillingFeatureDecorator
    // via the RequiresBillingFeatureDesignPattern and ActionManager
}
