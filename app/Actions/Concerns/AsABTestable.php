<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\ABTestableDecorator;

/**
 * Enables A/B testing for actions with variant selection and tracking.
 *
 * This trait is a marker that enables automatic A/B testing via ABTestableDecorator.
 * When an action uses AsABTestable, ABTestableDesignPattern recognizes it and
 * ActionManager wraps the action with ABTestableDecorator.
 *
 * How it works:
 * 1. Action uses AsABTestable trait (marker)
 * 2. ABTestableDesignPattern recognizes the trait
 * 3. ActionManager wraps action with ABTestableDecorator
 * 4. When handle() is called, the decorator:
 *    - Detects if variant is explicitly provided
 *    - If not, selects variant automatically based on user and distribution
 *    - Prepends variant to arguments before calling action's handle()
 *    - Tracks variant assignment for consistency
 *
 * Features:
 * - Automatic variant selection
 * - User-based variant consistency (same user gets same variant)
 * - Configurable variant distribution
 * - Variant tracking in cache and optionally database
 * - Multiple variants support (A/B/C testing)
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait with delegation)
 *
 * Benefits:
 * - A/B testing support
 * - Consistent variant assignment per user
 * - Statistical distribution control
 * - Variant tracking and analytics
 * - Configurable per action
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - Email campaign testing
 * - UI/UX experiments
 * - Feature rollouts
 * - Pricing experiments
 * - Conversion optimization
 * - User experience testing
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ABTestableDecorator, which automatically wraps actions and adds A/B testing.
 * Unlike pure marker traits like AsDebounced, this trait includes delegation
 * methods (selectVariant, getUserVariant, trackVariant) because actions need
 * to call these methods during execution to determine which variant logic to use.
 *
 * Configuration:
 * - Set `getVariants()` method to customize available variants (default: ['A', 'B'])
 * - Set `getVariantDistribution()` method to customize probability distribution
 * - Set `getVariantTrackingTtl()` method to customize cache TTL (default: 30 days)
 * - Set `shouldTrackInDatabase()` method to enable database tracking
 * - Implement `recordVariantInDatabase($user, $variant)` for custom database tracking
 *
 * @example
 * // ============================================
 * // Example 1: Basic A/B Testing
 * // ============================================
 * class SendEmailCampaign extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): void
 *     {
 *         match($variant) {
 *             'A' => $this->sendVariantA($user),
 *             'B' => $this->sendVariantB($user),
 *             default => $this->sendDefault($user),
 *         };
 *     }
 *
 *     protected function sendVariantA(User $user): void
 *     {
 *         Mail::to($user)->send(new CampaignEmailA());
 *     }
 *
 *     protected function sendVariantB(User $user): void
 *     {
 *         Mail::to($user)->send(new CampaignEmailB());
 *     }
 *
 *     protected function sendDefault(User $user): void
 *     {
 *         Mail::to($user)->send(new CampaignEmailA());
 *     }
 * }
 *
 * // Usage
 * SendEmailCampaign::run($user);
 * // Automatically selects variant (A or B) and tracks assignment
 * @example
 * // ============================================
 * // Example 2: Custom Variants
 * // ============================================
 * class TestPricingPage extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): array
 *     {
 *         return match($variant) {
 *             'control' => $this->showControlPricing($user),
 *             'discount' => $this->showDiscountPricing($user),
 *             'premium' => $this->showPremiumPricing($user),
 *             default => $this->showControlPricing($user),
 *         };
 *     }
 *
 *     protected function getVariants(): array
 *     {
 *         return ['control', 'discount', 'premium'];
 *     }
 *
 *     protected function showControlPricing(User $user): array
 *     {
 *         // Control pricing logic
 *         return [];
 *     }
 *
 *     protected function showDiscountPricing(User $user): array
 *     {
 *         // Discount pricing logic
 *         return [];
 *     }
 *
 *     protected function showPremiumPricing(User $user): array
 *     {
 *         // Premium pricing logic
 *         return [];
 *     }
 * }
 *
 * // Usage
 * TestPricingPage::run($user);
 * // Selects one of three variants and tracks assignment
 * @example
 * // ============================================
 * // Example 3: Custom Distribution
 * // ============================================
 * class TestFeatureRollout extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): void
 *     {
 *         if ($variant === 'enabled') {
 *             $this->enableFeature($user);
 *         } else {
 *             $this->disableFeature($user);
 *         }
 *     }
 *
 *     protected function getVariants(): array
 *     {
 *         return ['enabled', 'disabled'];
 *     }
 *
 *     protected function getVariantDistribution(): array
 *     {
 *         // 10% get enabled, 90% get disabled
 *         return [
 *             'enabled' => 0.1,
 *             'disabled' => 0.9,
 *         ];
 *     }
 *
 *     protected function enableFeature(User $user): void
 *     {
 *         // Enable feature logic
 *     }
 *
 *     protected function disableFeature(User $user): void
 *     {
 *         // Disable feature logic
 *     }
 * }
 *
 * // Usage
 * TestFeatureRollout::run($user);
 * // 10% chance of getting 'enabled', 90% chance of 'disabled'
 * @example
 * // ============================================
 * // Example 4: Manual Variant Selection
 * // ============================================
 * class TestUIChanges extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): array
 *     {
 *         return match($variant) {
 *             'A' => ['layout' => 'old', 'theme' => 'light'],
 *             'B' => ['layout' => 'new', 'theme' => 'dark'],
 *             default => ['layout' => 'old', 'theme' => 'light'],
 *         };
 *     }
 * }
 *
 * // Usage - explicitly provide variant
 * TestUIChanges::run('A', $user); // Force variant A
 * TestUIChanges::run('B', $user); // Force variant B
 *
 * // Usage - automatic selection
 * TestUIChanges::run($user); // Automatically selects and tracks
 * @example
 * // ============================================
 * // Example 5: Getting User's Assigned Variant
 * // ============================================
 * class CheckUserVariant extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(User $user): string
 *     {
 *         // Get the variant assigned to this user
 *         $variant = $this->getUserVariant($user);
 *
 *         if (! $variant) {
 *             // User hasn't been assigned yet, select one
 *             $variant = $this->selectVariant($user);
 *             $this->trackVariant($user, $variant);
 *         }
 *
 *         return $variant;
 *     }
 * }
 *
 * // Usage
 * $variant = CheckUserVariant::run($user);
 * // Returns the variant assigned to this user (consistent across calls)
 * @example
 * // ============================================
 * // Example 6: Database Tracking
 * // ============================================
 * class TrackExperiment extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): void
 *     {
 *         // Process based on variant
 *         $this->processVariant($variant, $user);
 *     }
 *
 *     protected function shouldTrackInDatabase(): bool
 *     {
 *         return true; // Enable database tracking
 *     }
 *
 *     protected function recordVariantInDatabase($user, string $variant): void
 *     {
 *         DB::table('ab_test_assignments')->updateOrInsert(
 *             [
 *                 'user_id' => $user->id,
 *                 'action' => get_class($this),
 *             ],
 *             [
 *                 'variant' => $variant,
 *                 'assigned_at' => now(),
 *                 'updated_at' => now(),
 *             ]
 *         );
 *     }
 *
 *     protected function processVariant(string $variant, User $user): void
 *     {
 *         // Process variant logic
 *     }
 * }
 *
 * // Usage
 * TrackExperiment::run($user);
 * // Tracks variant in both cache and database
 * @example
 * // ============================================
 * // Example 7: Conversion Tracking
 * // ============================================
 * class TestCheckoutFlow extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, Order $order): void
 *     {
 *         match($variant) {
 *             'A' => $this->showOnePageCheckout($order),
 *             'B' => $this->showMultiStepCheckout($order),
 *             default => $this->showOnePageCheckout($order),
 *         };
 *
 *         // Track conversion
 *         $this->trackConversion($order, $variant);
 *     }
 *
 *     protected function getVariants(): array
 *     {
 *         return ['A', 'B'];
 *     }
 *
 *     protected function getVariantDistribution(): array
 *     {
 *         // 50/50 split
 *         return ['A' => 0.5, 'B' => 0.5];
 *     }
 *
 *     protected function showOnePageCheckout(Order $order): void
 *     {
 *         // One-page checkout logic
 *     }
 *
 *     protected function showMultiStepCheckout(Order $order): void
 *     {
 *         // Multi-step checkout logic
 *     }
 *
 *     protected function trackConversion(Order $order, string $variant): void
 *     {
 *         // Track conversion logic
 *     }
 * }
 *
 * // Usage
 * TestCheckoutFlow::run($order);
 * // Tests two checkout flows with 50/50 distribution
 * @example
 * // ============================================
 * // Example 8: Email Subject Line Testing
 * // ============================================
 * class TestEmailSubject extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user, string $content): void
 *     {
 *         $subject = match($variant) {
 *             'A' => 'Special Offer Just For You!',
 *             'B' => 'Limited Time: 50% Off',
 *             'C' => 'Don\'t Miss Out - Act Now!',
 *             default => 'Special Offer Just For You!',
 *         };
 *
 *         Mail::to($user)->send(new MarketingEmail($subject, $content));
 *     }
 *
 *     protected function getVariants(): array
 *     {
 *         return ['A', 'B', 'C'];
 *     }
 *
 *     protected function getVariantDistribution(): array
 *     {
 *         // Equal distribution across three variants
 *         return ['A' => 0.33, 'B' => 0.33, 'C' => 0.34];
 *     }
 * }
 *
 * // Usage
 * TestEmailSubject::run($user, 'Email content here');
 * // Tests three subject lines with equal distribution
 * @example
 * // ============================================
 * // Example 9: UI Component Testing
 * // ============================================
 * class TestButtonStyle extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): array
 *     {
 *         return match($variant) {
 *             'red' => ['color' => 'red', 'size' => 'large', 'text' => 'Buy Now'],
 *             'blue' => ['color' => 'blue', 'size' => 'medium', 'text' => 'Purchase'],
 *             'green' => ['color' => 'green', 'size' => 'small', 'text' => 'Add to Cart'],
 *             default => ['color' => 'blue', 'size' => 'medium', 'text' => 'Purchase'],
 *         };
 *     }
 *
 *     protected function getVariants(): array
 *     {
 *         return ['red', 'blue', 'green'];
 *     }
 * }
 *
 * // Usage
 * TestButtonStyle::run($user);
 * // Returns button configuration based on assigned variant
 * @example
 * // ============================================
 * // Example 10: Pricing Experiment
 * // ============================================
 * class TestPricingStrategy extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, Product $product): float
 *     {
 *         return match($variant) {
 *             'standard' => $product->price,
 *             'discount' => $product->price * 0.9, // 10% off
 *             'premium' => $product->price * 1.1, // 10% premium
 *             default => $product->price,
 *         };
 *     }
 *
 *     protected function getVariants(): array
 *     {
 *         return ['standard', 'discount', 'premium'];
 *     }
 *
 *     protected function getVariantDistribution(): array
 *     {
 *         // Most users get standard, smaller groups get discount/premium
 *         return [
 *             'standard' => 0.7,
 *             'discount' => 0.2,
 *             'premium' => 0.1,
 *         ];
 *     }
 * }
 *
 * // Usage
 * $price = TestPricingStrategy::run($product);
 * // Returns price based on user's assigned variant
 * @example
 * // ============================================
 * // Example 11: Feature Flag A/B Testing
 * // ============================================
 * class TestNewFeature extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): array
 *     {
 *         $enabled = $variant === 'enabled';
 *
 *         return [
 *             'feature_enabled' => $enabled,
 *             'variant' => $variant,
 *             'user_id' => $user->id,
 *         ];
 *     }
 *
 *     protected function getVariants(): array
 *     {
 *         return ['enabled', 'disabled'];
 *     }
 *
 *     protected function getVariantDistribution(): array
 *     {
 *         // Gradual rollout: 5% enabled
 *         return [
 *             'enabled' => 0.05,
 *             'disabled' => 0.95,
 *         ];
 *     }
 * }
 *
 * // Usage
 * TestNewFeature::run($user);
 * // 5% of users get 'enabled', 95% get 'disabled'
 * @example
 * // ============================================
 * // Example 12: Custom Tracking TTL
 * // ============================================
 * class ShortTermTest extends Actions
 * {
 *     use AsABTestable;
 *
 *     public function handle(string $variant, User $user): void
 *     {
 *         // Process variant
 *     }
 *
 *     protected function getVariantTrackingTtl(): int
 *     {
 *         return 86400; // 1 day instead of default 30 days
 *     }
 * }
 *
 * // Usage
 * ShortTermTest::run($user);
 * // Variant assignment expires after 1 day
 */
trait AsABTestable
{
    /**
     * Reference to the A/B testable decorator (injected by decorator).
     */
    protected ?ABTestableDecorator $_abTestableDecorator = null;

    /**
     * Set the A/B testable decorator reference.
     *
     * Called by ABTestableDecorator to inject itself.
     */
    public function setABTestableDecorator(ABTestableDecorator $decorator): void
    {
        $this->_abTestableDecorator = $decorator;
    }

    /**
     * Get the A/B testable decorator.
     */
    protected function getABTestableDecorator(): ?ABTestableDecorator
    {
        return $this->_abTestableDecorator;
    }

    /**
     * Select a variant for the given user.
     *
     * @param  mixed  $user  The user to select variant for
     * @return string The selected variant
     */
    public function selectVariant($user): string
    {
        $decorator = $this->getABTestableDecorator();
        if ($decorator) {
            return $decorator->selectVariant($user);
        }

        // Fallback if decorator not available
        return 'A';
    }

    /**
     * Get the variant assigned to a user.
     *
     * @param  mixed  $user  The user to get variant for
     * @return string|null The assigned variant or null if not assigned
     */
    public function getUserVariant($user): ?string
    {
        $decorator = $this->getABTestableDecorator();
        if ($decorator) {
            return $decorator->getUserVariant($user);
        }

        return null;
    }

    /**
     * Track a variant assignment for a user.
     *
     * @param  mixed  $user  The user to track
     * @param  string  $variant  The variant to track
     */
    public function trackVariant($user, string $variant): void
    {
        $decorator = $this->getABTestableDecorator();
        if ($decorator) {
            $decorator->trackVariant($user, $variant);
        }
    }
}
