<?php

namespace App\Actions\Concerns;

/**
 * Requires specific plan(s) before action execution.
 *
 * This trait is a marker that enables automatic plan checking via RequiresPlanDecorator.
 * When an action uses AsRequiresPlan, RequiresPlanDesignPattern recognizes it and
 * ActionManager wraps the action with RequiresPlanDecorator.
 *
 * How it works:
 * 1. Action uses AsRequiresPlan trait (marker)
 * 2. RequiresPlanDesignPattern recognizes the trait
 * 3. ActionManager wraps action with RequiresPlanDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets required plans from action
 *    - Gets current user and organization
 *    - Checks if organization has required plan(s) (OR logic)
 *    - Throws 403 if unauthorized
 *    - Executes action if authorized
 *
 * Benefits:
 * - Automatic plan checking
 * - Organization context awareness
 * - Multiple plan support (OR logic)
 * - Custom error handling
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * RequiresPlanDecorator, which automatically wraps actions and checks plans.
 * This follows the same pattern as AsRequiresRole, AsRequiresSubscription, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getRequiredPlans(...$arguments)` to specify required plans
 * - Set `$requiredPlans` property as array of plan IDs
 * - Implement `handleUnauthorizedPlan(string $message)` for custom error handling
 *
 * Plan Checking:
 * - Uses OR logic: organization needs ANY of the specified plans
 * - Checks organization->plan() method
 * - Plan IDs: 'free', 'starter', 'professional', 'enterprise', etc.
 *
 * @example
 * // ============================================
 * // Example 1: Basic Plan Requirement
 * // ============================================
 * class ProfessionalFeatureAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     public function handle(): void
 *     {
 *         // Professional plan feature
 *     }
 *
 *     protected function getRequiredPlans(): array
 *     {
 *         return ['professional', 'enterprise'];
 *     }
 * }
 *
 * // Organization must have professional or enterprise plan
 * @example
 * // ============================================
 * // Example 2: Property-Based Plans
 * // ============================================
 * class EnterpriseOnlyAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['enterprise'];
 *
 *     public function handle(): void
 *     {
 *         // Enterprise-only logic
 *     }
 * }
 *
 * // Only enterprise plan
 * @example
 * // ============================================
 * // Example 3: Multiple Plans (OR Logic)
 * // ============================================
 * class PaidPlansAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['starter', 'professional', 'enterprise'];
 *
 *     public function handle(): void
 *     {
 *         // Any paid plan
 *     }
 * }
 *
 * // Organization needs ANY paid plan
 * @example
 * // ============================================
 * // Example 4: Dynamic Plan Based on Arguments
 * // ============================================
 * class DynamicPlanAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     public function handle(Feature $feature): void
 *     {
 *         // Feature logic
 *     }
 *
 *     protected function getRequiredPlans(...$arguments): array
 *     {
 *         $feature = $arguments[0] ?? null;
 *
 *         // Different plans for different features
 *         if ($feature && $feature->is_premium) {
 *             return ['professional', 'enterprise'];
 *         }
 *
 *         return ['starter', 'professional', 'enterprise'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Custom Error Handling
 * // ============================================
 * class CustomErrorAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['enterprise'];
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function handleUnauthorizedPlan(string $message): void
 *     {
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'error' => 'Plan upgrade required',
 *                 'message' => $message,
 *                 'required_plans' => $this->requiredPlans,
 *                 'upgrade_url' => route('billing.upgrade'),
 *             ], 403)->send();
 *             exit;
 *         }
 *
 *         redirect()->route('billing.plans')
 *             ->with('error', 'Please upgrade your plan to access this feature.')
 *             ->send();
 *         exit;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Combining with Subscription
 * // ============================================
 * class PlanAndSubscriptionAction extends Actions
 * {
 *     use AsRequiresPlan;
 *     use AsRequiresSubscription;
 *
 *     protected array $requiredPlans = ['professional'];
 *
 *     public function handle(): void
 *     {
 *         // Requires plan AND active subscription
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Combining with Role
 * // ============================================
 * class PlanAndRoleAction extends Actions
 * {
 *     use AsRequiresPlan;
 *     use AsRequiresRole;
 *
 *     protected array $requiredPlans = ['enterprise'];
 *     protected array $requiredRoles = ['Admin'];
 *
 *     public function handle(): void
 *     {
 *         // Requires plan AND role
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Combining with Capability
 * // ============================================
 * class PlanAndCapabilityAction extends Actions
 * {
 *     use AsRequiresPlan;
 *     use AsRequiresCapability;
 *
 *     protected array $requiredPlans = ['professional'];
 *     protected array $requiredCapabilities = ['advanced:access'];
 *
 *     public function handle(): void
 *     {
 *         // Requires plan AND capability
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Combining with Feature
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
 * // Example 10: Comprehensive Protection
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsRequiresPlan;
 *     use AsRequiresSubscription;
 *     use AsRequiresRole;
 *     use AsLogger;
 *
 *     protected array $requiredPlans = ['enterprise'];
 *     protected array $requiredRoles = ['Admin'];
 *
 *     public function handle(): void
 *     {
 *         // All checks must pass
 *     }
 * }
 *
 * // Plan + Subscription + Role + Logging
 * @example
 * // ============================================
 * // Example 11: Enterprise-Only Features
 * // ============================================
 * class EnterpriseFeatureAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['enterprise'];
 *
 *     public function handle(): void
 *     {
 *         // Enterprise-only feature
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Professional Plan Features
 * // ============================================
 * class ProfessionalFeatureAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['professional', 'enterprise'];
 *
 *     public function handle(): void
 *     {
 *         // Professional+ features
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Paid Plan Features
 * // ============================================
 * class PaidPlanFeatureAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['starter', 'professional', 'enterprise'];
 *
 *     public function handle(): void
 *     {
 *         // Any paid plan feature
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Plan-Based API Limits
 * // ============================================
 * class ApiLimitAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['professional', 'enterprise'];
 *
 *     public function handle(): array
 *     {
 *         // Higher API limits for paid plans
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Plan-Based Storage
 * // ============================================
 * class StorageAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['professional', 'enterprise'];
 *
 *     public function handle(int $size): void
 *     {
 *         // Increased storage for paid plans
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Plan-Based Team Size
 * // ============================================
 * class TeamSizeAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['enterprise'];
 *
 *     public function handle(Team $team, int $maxMembers): void
 *     {
 *         // Enterprise allows unlimited team members
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Plan-Based Customization
 * // ============================================
 * class CustomizationAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['professional', 'enterprise'];
 *
 *     public function handle(array $customizations): void
 *     {
 *         // Customization features for paid plans
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Plan-Based Integrations
 * // ============================================
 * class IntegrationAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['enterprise'];
 *
 *     public function handle(string $integration): void
 *     {
 *         // Enterprise integrations
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Plan-Based Support
 * // ============================================
 * class SupportAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['professional', 'enterprise'];
 *
 *     public function handle(string $ticket): void
 *     {
 *         // Priority support for paid plans
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Plan-Based Analytics
 * // ============================================
 * class AnalyticsAction extends Actions
 * {
 *     use AsRequiresPlan;
 *
 *     protected array $requiredPlans = ['professional', 'enterprise'];
 *
 *     public function handle(): array
 *     {
 *         return ['analytics' => 'advanced'];
 *     }
 * }
 */
trait AsRequiresPlan
{
    // This is a marker trait - the actual plan checking is handled by RequiresPlanDecorator
    // via the RequiresPlanDesignPattern and ActionManager
}
