<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\ConditionalDecorator;

/**
 * Executes action only if conditions are met.
 *
 * This trait is a marker that enables automatic conditional execution via ConditionalDecorator.
 * When an action uses AsConditional, ConditionalDesignPattern recognizes it and
 * ActionManager wraps the action with ConditionalDecorator.
 *
 * How it works:
 * 1. Action uses AsConditional trait (marker)
 * 2. ConditionalDesignPattern recognizes the trait
 * 3. ActionManager wraps action with ConditionalDecorator
 * 4. When handle() is called, the decorator:
 *    - Checks shouldExecute() method
 *    - If true, executes the action
 *    - If false, calls onSkipped() or returns null
 *
 * Features:
 * - Conditional execution based on custom logic
 * - Custom skip handling via onSkipped()
 * - Access to handle() arguments in shouldExecute()
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Prevents unnecessary execution
 * - Enables feature flags and gating
 * - Supports environment-based logic
 * - Composable with other decorators
 * - No trait method conflicts
 *
 * Use Cases:
 * - Feature flags
 * - Environment-based execution
 * - User role/permission checks
 * - Time-based conditions
 * - Subscription/plan checks
 * - Maintenance mode checks
 * - Rate limiting
 * - Business hours restrictions
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ConditionalDecorator, which automatically wraps actions and adds conditional execution.
 * This follows the same pattern as AsDebounced, AsCostTracked, AsCompensatable, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `shouldExecute(...$arguments)` method to define conditions
 * - Optionally implement `onSkipped(...$arguments)` to customize skip behavior
 * - Arguments from handle() are passed to both methods
 *
 * @example
 * // ============================================
 * // Example 1: Basic Conditional Check
 * // ============================================
 * class ConditionalAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return config('feature.enabled') && auth()->user()->isAdmin();
 *     }
 * }
 *
 * // Only executes if shouldExecute() returns true, otherwise returns null
 * @example
 * // ============================================
 * // Example 2: Custom Return Value When Skipped
 * // ============================================
 * class ConditionalActionWithReturn extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): string
 *     {
 *         return 'Action executed';
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return auth()->check();
 *     }
 *
 *     protected function onSkipped(): string
 *     {
 *         return 'Action skipped - user not authenticated';
 *     }
 * }
 *
 * // Returns custom message when skipped
 * @example
 * // ============================================
 * // Example 3: Feature Flag Check
 * // ============================================
 * class FeatureGatedAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // New feature logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return config('features.new_payment_processor', false);
 *     }
 * }
 *
 * // Only executes if feature flag is enabled
 * @example
 * // ============================================
 * // Example 4: User Role Check
 * // ============================================
 * class AdminOnlyAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Admin-only logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return auth()->check() && auth()->user()->hasRole('admin');
 *     }
 *
 *     protected function onSkipped(): void
 *     {
 *         abort(403, 'Admin access required');
 *     }
 * }
 *
 * // Aborts with 403 if not admin
 * @example
 * // ============================================
 * // Example 5: Environment-Based Conditional
 * // ============================================
 * class ProductionOnlyAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Production-only logic (e.g., sending emails, payments)
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return app()->environment('production');
 *     }
 *
 *     protected function onSkipped(): void
 *     {
 *         \Log::info('ProductionOnlyAction skipped in non-production environment');
 *     }
 * }
 *
 * // Only executes in production environment
 * @example
 * // ============================================
 * // Example 6: Time-Based Conditional
 * // ============================================
 * class BusinessHoursOnlyAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Business hours logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         $now = now();
 *         $businessHoursStart = $now->copy()->setTime(9, 0);
 *         $businessHoursEnd = $now->copy()->setTime(17, 0);
 *
 *         return $now->between($businessHoursStart, $businessHoursEnd)
 *             && ! $now->isWeekend();
 *     }
 *
 *     protected function onSkipped(): mixed
 *     {
 *         return ['message' => 'Action only available during business hours'];
 *     }
 * }
 *
 * // Only executes during business hours (9 AM - 5 PM, weekdays)
 * @example
 * // ============================================
 * // Example 7: Subscription/Plan Check
 * // ============================================
 * class PremiumFeatureAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(User $user): void
 *     {
 *         // Premium feature logic
 *     }
 *
 *     protected function shouldExecute(User $user): bool
 *     {
 *         return $user->subscription && $user->subscription->isActive();
 *     }
 *
 *     protected function onSkipped(User $user): array
 *     {
 *         return [
 *             'error' => 'subscription_required',
 *             'message' => 'Active subscription required for this action',
 *         ];
 *     }
 * }
 *
 * // Only executes for users with active subscriptions
 * // Note: Arguments from handle() are passed to shouldExecute() and onSkipped()
 * @example
 * // ============================================
 * // Example 8: Rate Limit Check (Simple)
 * // ============================================
 * class RateLimitedAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         $key = 'action_rate_limit:'.auth()->id();
 *         $count = \Cache::get($key, 0);
 *
 *         if ($count >= 10) {
 *             return false;
 *         }
 *
 *         \Cache::put($key, $count + 1, 60);
 *
 *         return true;
 *     }
 *
 *     protected function onSkipped(): mixed
 *     {
 *         return ['error' => 'rate_limit_exceeded'];
 *     }
 * }
 *
 * // Simple rate limiting (10 requests per minute)
 * // Note: For complex rate limiting, use AsThrottle trait instead
 * @example
 * // ============================================
 * // Example 9: Maintenance Mode Check
 * // ============================================
 * class MaintenanceModeAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return ! app()->isDownForMaintenance();
 *     }
 *
 *     protected function onSkipped(): mixed
 *     {
 *         return ['message' => 'Service temporarily unavailable'];
 *     }
 * }
 *
 * // Skips during maintenance mode
 * @example
 * // ============================================
 * // Example 10: Multi-Condition Check
 * // ============================================
 * class ComplexConditionalAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Complex action logic
 *     }
 *
 *     protected function shouldExecute(Order $order): bool
 *     {
 *         // Check multiple conditions
 *         if (! auth()->check()) {
 *             return false;
 *         }
 *
 *         $user = auth()->user();
 *
 *         return $user->isAdmin()
 *             || (config('features.allow_modifications', false) && $order->canBeModified());
 *     }
 *
 *     protected function onSkipped(Order $order): mixed
 *     {
 *         return ['error' => 'conditions_not_met'];
 *     }
 * }
 *
 * // Executes only if complex conditions are met
 * // Arguments from handle() are available in shouldExecute()
 * @example
 * // ============================================
 * // Example 11: Conditional with Arguments
 * // ============================================
 * class ConditionalWithArgumentsAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(User $user, array $data): void
 *     {
 *         // Action logic using arguments
 *     }
 *
 *     protected function shouldExecute(User $user, array $data): bool
 *     {
 *         // Access arguments directly - they're passed from handle()
 *         return $user->isActive() && ! empty($data);
 *     }
 *
 *     protected function onSkipped(User $user, array $data): mixed
 *     {
 *         return ['error' => 'invalid_user_or_data'];
 *     }
 * }
 *
 * // Arguments from handle() are passed to shouldExecute() and onSkipped()
 * @example
 * // ============================================
 * // Example 12: Combining with Other Concerns
 * // ============================================
 * class ConditionalWithAuthorization extends Actions
 * {
 *     use AsConditional;
 *     use AsAuthorized;
 *
 *     public function handle(Post $post): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function shouldExecute(Post $post): bool
 *     {
 *         // AsConditional runs first
 *         return config('features.post_editing', true);
 *     }
 *
 *     protected function getAuthorizationAbility(): string
 *     {
 *         return 'update';
 *     }
 *
 *     protected function getAuthorizationArguments(...$arguments): array
 *     {
 *         return [$arguments[0]]; // Post model
 *     }
 * }
 *
 * // Order of execution:
 * // 1. AsConditional checks shouldExecute()
 * // 2. If true, AsAuthorized checks permissions
 * // 3. If authorized, handle() executes
 * @example
 * // ============================================
 * // Example 13: Conditional with Logger
 * // ============================================
 * class ConditionalLoggedAction extends Actions
 * {
 *     use AsConditional;
 *     use AsLogger;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return app()->environment('production');
 *     }
 *
 *     protected function onSkipped(): void
 *     {
 *         \Log::info('ConditionalLoggedAction skipped', [
 *             'environment' => app()->environment(),
 *         ]);
 *     }
 * }
 *
 * // Logs when skipped, logs execution when enabled
 * @example
 * // ============================================
 * // Example 14: Testing Conditional Actions
 * // ============================================
 * class TestableConditionalAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): string
 *     {
 *         return 'Executed';
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return config('test.feature_enabled', false);
 *     }
 *
 *     protected function onSkipped(): string
 *     {
 *         return 'Skipped';
 *     }
 * }
 *
 * // In tests:
 * // Config::set('test.feature_enabled', true);
 * // expect(TestableConditionalAction::run())->toBe('Executed');
 * //
 * // Config::set('test.feature_enabled', false);
 * // expect(TestableConditionalAction::run())->toBe('Skipped');
 * @example
 * // ============================================
 * // Example 15: Conditional that Returns Different Types
 * // ============================================
 * class FlexibleReturnAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): array
 *     {
 *         return ['status' => 'success', 'data' => []];
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return auth()->check();
 *     }
 *
 *     protected function onSkipped(): array
 *     {
 *         return ['status' => 'skipped', 'reason' => 'authentication_required'];
 *     }
 * }
 *
 * // Always returns an array with status, maintaining consistent return type
 * @example
 * // ============================================
 * // Example 16: Database State Check
 * // ============================================
 * class DatabaseStateConditional extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 *
 *     protected function shouldExecute(Order $order): bool
 *     {
 *         // Check if order is in a valid state for processing
 *         return $order->status === 'pending' && $order->payment_status === 'paid';
 *     }
 *
 *     protected function onSkipped(Order $order): array
 *     {
 *         return [
 *             'error' => 'invalid_order_state',
 *             'order_id' => $order->id,
 *             'current_status' => $order->status,
 *         ];
 *     }
 * }
 *
 * // Only processes orders in valid state
 * @example
 * // ============================================
 * // Example 17: External Service Availability
 * // ============================================
 * class ExternalServiceAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Call external service
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         // Check if external service is available
 *         try {
 *             $response = Http::timeout(2)->get('https://api.example.com/health');
 *             return $response->successful();
 *         } catch (\Exception $e) {
 *             return false;
 *         }
 *     }
 *
 *     protected function onSkipped(): array
 *     {
 *         return ['error' => 'external_service_unavailable'];
 *     }
 * }
 *
 * // Only executes if external service is available
 * @example
 * // ============================================
 * // Example 18: Quota/Limit Check
 * // ============================================
 * class QuotaLimitedAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(User $user): void
 *     {
 *         // Action that consumes quota
 *     }
 *
 *     protected function shouldExecute(User $user): bool
 *     {
 *         $quota = $user->quota;
 *         $used = $user->quota_used;
 *
 *         return $used < $quota;
 *     }
 *
 *     protected function onSkipped(User $user): array
 *     {
 *         return [
 *             'error' => 'quota_exceeded',
 *             'quota' => $user->quota,
 *             'used' => $user->quota_used,
 *         ];
 *     }
 * }
 *
 * // Only executes if user has remaining quota
 */
trait AsConditional
{
    /**
     * Reference to the conditional decorator (injected by decorator).
     */
    protected ?ConditionalDecorator $_conditionalDecorator = null;

    /**
     * Set the conditional decorator reference.
     *
     * Called by ConditionalDecorator to inject itself.
     */
    public function setConditionalDecorator(ConditionalDecorator $decorator): void
    {
        $this->_conditionalDecorator = $decorator;
    }

    /**
     * Get the conditional decorator.
     */
    protected function getConditionalDecorator(): ?ConditionalDecorator
    {
        return $this->_conditionalDecorator;
    }
}
