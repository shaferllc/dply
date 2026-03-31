<?php

namespace App\Actions\Concerns;

/**
 * Requires active subscription before action execution.
 *
 * This trait is a marker that enables automatic subscription checking via RequiresSubscriptionDecorator.
 * When an action uses AsRequiresSubscription, RequiresSubscriptionDesignPattern recognizes it and
 * ActionManager wraps the action with RequiresSubscriptionDecorator.
 *
 * How it works:
 * 1. Action uses AsRequiresSubscription trait (marker)
 * 2. RequiresSubscriptionDesignPattern recognizes the trait
 * 3. ActionManager wraps action with RequiresSubscriptionDecorator
 * 4. When handle() is called, the decorator:
 *    - Gets current user and organization
 *    - Checks if organization has active subscription
 *    - Throws 403 if no subscription
 *    - Executes action if subscribed
 *
 * Benefits:
 * - Automatic subscription checking
 * - Organization context awareness
 * - Custom error handling
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * RequiresSubscriptionDecorator, which automatically wraps actions and checks subscriptions.
 * This follows the same pattern as AsRequiresRole, AsRequiresPlan, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `handleUnauthorizedSubscription(string $message)` for custom error handling
 *
 * Subscription Checking:
 * - Checks organization's subscription status
 * - Uses organization->subscribed() method
 * - Works with current organization context
 *
 * @example
 * // ============================================
 * // Example 1: Basic Subscription Requirement
 * // ============================================
 * class PremiumFeatureAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(): void
 *     {
 *         // Premium feature logic
 *     }
 * }
 *
 * // Only executes if organization has active subscription
 * @example
 * // ============================================
 * // Example 2: Custom Error Handling
 * // ============================================
 * class CustomErrorAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function handleUnauthorizedSubscription(string $message): void
 *     {
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'error' => 'Subscription required',
 *                 'message' => $message,
 *                 'upgrade_url' => route('billing.upgrade'),
 *             ], 403)->send();
 *             exit;
 *         }
 *
 *         redirect()->route('billing.subscription')
 *             ->with('error', 'Please subscribe to access this feature.')
 *             ->send();
 *         exit;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Combining with Role Check
 * // ============================================
 * class RoleAndSubscriptionAction extends Actions
 * {
 *     use AsRequiresRole;
 *     use AsRequiresSubscription;
 *
 *     protected array $requiredRoles = ['Admin'];
 *
 *     public function handle(): void
 *     {
 *         // Requires role AND subscription
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Combining with Plan Check
 * // ============================================
 * class SubscriptionAndPlanAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsRequiresPlan;
 *
 *     protected function getRequiredPlans(): array
 *     {
 *         return ['professional', 'enterprise'];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Requires subscription AND specific plan
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Combining with Feature Check
 * // ============================================
 * class SubscriptionAndFeatureAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsRequiresBillingFeature;
 *
 *     protected function getRequiredFeatures(): array
 *     {
 *         return ['advanced-analytics'];
 *     }
 *
 *     public function handle(): void
 *     {
 *         // Requires subscription AND feature access
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: With Logging
 * // ============================================
 * class LoggedSubscriptionAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsLogger;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'subscription';
 *     }
 * }
 *
 * // Subscription check + execution logging
 * @example
 * // ============================================
 * // Example 7: With Lifecycle Hooks
 * // ============================================
 * class LifecycleSubscriptionAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsLifecycle;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function beforeHandle(): void
 *     {
 *         // Called after subscription check passes
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: API Endpoint Protection
 * // ============================================
 * class ApiSubscriptionAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'premium content'];
 *     }
 * }
 *
 * // API endpoint requires subscription
 * @example
 * // ============================================
 * // Example 9: Job with Subscription
 * // ============================================
 * class SubscriptionJobAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsJob;
 *
 *     public function handle(): void
 *     {
 *         // Premium job logic
 *     }
 * }
 *
 * // Subscription check + job dispatch
 * @example
 * // ============================================
 * // Example 10: Comprehensive Protection
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsRequiresRole;
 *     use AsRequiresCapability;
 *     use AsLogger;
 *
 *     protected array $requiredRoles = ['Admin'];
 *     protected array $requiredCapabilities = ['premium:access'];
 *
 *     public function handle(): void
 *     {
 *         // All checks must pass
 *     }
 * }
 *
 * // Subscription + Role + Capability + Logging
 * @example
 * // ============================================
 * // Example 11: Premium API Access
 * // ============================================
 * class PremiumApiAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(): array
 *     {
 *         return ['premium' => 'data'];
 *     }
 * }
 *
 * // Only subscribed organizations can access
 * @example
 * // ============================================
 * // Example 12: Advanced Features
 * // ============================================
 * class AdvancedFeatureAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(): void
 *     {
 *         // Advanced feature logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Export Data
 * // ============================================
 * class ExportDataAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *     use AsJob;
 *
 *     public function handle(Team $team): void
 *     {
 *         // Data export (requires subscription)
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Custom Integrations
 * // ============================================
 * class CustomIntegrationAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(string $integration): void
 *     {
 *         // Custom integration setup
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Priority Support
 * // ============================================
 * class PrioritySupportAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(string $message): void
 *     {
 *         // Priority support ticket
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: White Label Features
 * // ============================================
 * class WhiteLabelAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(array $branding): void
 *     {
 *         // White label customization
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Advanced Analytics
 * // ============================================
 * class AdvancedAnalyticsAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(): array
 *     {
 *         return ['analytics' => 'advanced data'];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Team Collaboration Features
 * // ============================================
 * class CollaborationAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(Team $team): void
 *     {
 *         // Collaboration features
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Custom Domain
 * // ============================================
 * class CustomDomainAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(string $domain): void
 *     {
 *         // Custom domain setup
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: SSO Integration
 * // ============================================
 * class SsoIntegrationAction extends Actions
 * {
 *     use AsRequiresSubscription;
 *
 *     public function handle(array $ssoConfig): void
 *     {
 *         // SSO integration setup
 *     }
 * }
 */
trait AsRequiresSubscription
{
    // This is a marker trait - the actual subscription checking is handled by RequiresSubscriptionDecorator
    // via the RequiresSubscriptionDesignPattern and ActionManager
}
