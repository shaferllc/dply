<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Decorator that enables A/B testing for actions with variant selection and tracking.
 *
 * This decorator automatically selects variants, tracks assignments, and prepends
 * the variant to action arguments before execution.
 */
class ABTestableDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setABTestableDecorator')) {
            $action->setABTestableDecorator($this);
        } elseif (property_exists($action, '_abTestableDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_abTestableDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        // If variant is explicitly provided, use it
        if (count($arguments) > 0 && $this->isVariantArgument($arguments[0])) {
            return $this->callMethod('handle', $arguments);
        }

        // Otherwise, select variant automatically
        $user = $this->getUserFromArguments($arguments);
        $variant = $this->selectVariant($user);

        // Prepend variant to arguments
        array_unshift($arguments, $variant);

        $result = $this->callMethod('handle', $arguments);

        $this->trackVariant($user, $variant);

        return $result;
    }

    public function selectVariant($user): string
    {
        $variants = $this->getVariants();
        $distribution = $this->getVariantDistribution();

        // Check if user already has a variant assigned
        $existingVariant = $this->getUserVariant($user);
        if ($existingVariant) {
            return $existingVariant;
        }

        // Select variant based on distribution
        if (! empty($distribution)) {
            return $this->selectVariantByDistribution($distribution);
        }

        // Default: random selection
        return $variants[array_rand($variants)];
    }

    public function getUserVariant($user): ?string
    {
        if (! $user) {
            return null;
        }

        $key = $this->getVariantTrackingKey($user);

        return Cache::get($key);
    }

    public function trackVariant($user, string $variant): void
    {
        $key = $this->getVariantTrackingKey($user);
        Cache::put($key, $variant, $this->getVariantTrackingTtl());

        // Track in database if needed
        if ($this->shouldTrackInDatabase()) {
            $this->recordVariantInDatabase($user, $variant);
        }
    }

    protected function selectVariantByDistribution(array $distribution): string
    {
        $random = mt_rand() / mt_getrandmax();
        $cumulative = 0;

        foreach ($distribution as $variant => $probability) {
            $cumulative += $probability;
            if ($random <= $cumulative) {
                return $variant;
            }
        }

        // Fallback to first variant
        return array_key_first($distribution);
    }

    protected function getVariants(): array
    {
        if ($this->hasMethod('getVariants')) {
            return $this->callMethod('getVariants');
        }

        return ['A', 'B']; // Default: A and B
    }

    protected function getVariantDistribution(): array
    {
        if ($this->hasMethod('getVariantDistribution')) {
            return $this->callMethod('getVariantDistribution');
        }

        // Default: equal distribution
        $variants = $this->getVariants();
        $probability = 1 / count($variants);

        return array_fill_keys($variants, $probability);
    }

    protected function getUserFromArguments(array $arguments): mixed
    {
        // Try to find user in arguments
        foreach ($arguments as $arg) {
            if (is_object($arg) && (method_exists($arg, 'getAuthIdentifier') || $arg instanceof Authenticatable)) {
                return $arg;
            }
        }

        // Fallback to authenticated user
        return Auth::user();
    }

    protected function isVariantArgument($arg): bool
    {
        $variants = $this->getVariants();

        return in_array($arg, $variants, true);
    }

    protected function getVariantTrackingKey($user): string
    {
        $userId = is_object($user) && method_exists($user, 'getAuthIdentifier')
            ? $user->getAuthIdentifier()
            : (string) $user;

        return 'ab_test:'.get_class($this->action).':'.$userId;
    }

    protected function getVariantTrackingTtl(): int
    {
        if ($this->hasMethod('getVariantTrackingTtl')) {
            return $this->callMethod('getVariantTrackingTtl');
        }

        return 86400 * 30; // 30 days
    }

    protected function shouldTrackInDatabase(): bool
    {
        if ($this->hasMethod('shouldTrackInDatabase')) {
            return $this->callMethod('shouldTrackInDatabase');
        }

        return config('actions.ab_testing.track_in_database', false);
    }

    protected function recordVariantInDatabase($user, string $variant): void
    {
        if ($this->hasMethod('recordVariantInDatabase')) {
            $this->callMethod('recordVariantInDatabase', [$user, $variant]);

            return;
        }

        // Default implementation would depend on your database structure
        // Example:
        // DB::table('ab_test_assignments')->updateOrInsert(
        //     ['user_id' => $user->id, 'action' => get_class($this->action)],
        //     ['variant' => $variant, 'assigned_at' => now()]
        // );
    }
}
