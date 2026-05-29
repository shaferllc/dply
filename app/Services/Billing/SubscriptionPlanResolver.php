<?php

namespace App\Services\Billing;

use InvalidArgumentException;

/**
 * Resolves the flat subscription plan for an organization from its billable
 * BYO server count, and maps plans to their Stripe price IDs.
 *
 * The plan model (config `subscription.standard.plans`) meters by server
 * *count*, not size: customers pay their provider for size, and dply's fee
 * scales with how many servers it manages. The resolver picks the cheapest
 * plan whose `max_servers` ceiling covers the count (null ceiling = unlimited).
 *
 * Managed products (serverless, Cloud, Edge) are billed a la carte on top of
 * the plan and are intentionally NOT part of plan resolution.
 */
class SubscriptionPlanResolver
{
    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    /**
     * Resolve the plan that covers the given billable server count.
     *
     * @return array{key: string, label: string, price_cents: int, max_servers: ?int, max_sites: ?int}
     */
    public function resolveForServerCount(int $serverCount): array
    {
        $serverCount = max(0, $serverCount);
        $plans = $this->plans();

        foreach ($plans as $key => $plan) {
            $max = $plan['max_servers'] ?? null;
            if ($max === null || $serverCount <= (int) $max) {
                return $this->normalize($key, $plan);
            }
        }

        // No ceiling matched (config has no unlimited plan) — fall back to the
        // most expensive plan so an oversized fleet is never under-billed.
        $lastKey = array_key_last($plans);

        return $this->normalize((string) $lastKey, $plans[$lastKey]);
    }

    /**
     * Resolve a plan by its key (e.g. 'free', 'pro').
     *
     * @return array{key: string, label: string, price_cents: int, max_servers: ?int, max_sites: ?int}
     */
    public function resolveByKey(string $key): array
    {
        $plans = $this->plans();
        if (! array_key_exists($key, $plans)) {
            throw new InvalidArgumentException("Unknown subscription plan: {$key}");
        }

        return $this->normalize($key, $plans[$key]);
    }

    /**
     * The Stripe price ID for a paid plan at the given interval. Returns '' for
     * the free plan (no price) or when the price is not configured.
     */
    public function stripePriceId(string $planKey, string $interval): string
    {
        $bucket = match ($interval) {
            self::INTERVAL_MONTH => 'plans',
            self::INTERVAL_YEAR => 'plans_yearly',
            default => throw new InvalidArgumentException("Unknown billing interval: {$interval}"),
        };

        return (string) (config("subscription.standard.stripe.{$bucket}.{$planKey}") ?? '');
    }

    /**
     * True when a plan carries a recurring charge (everything but free).
     */
    public function isPaidPlan(string $planKey): bool
    {
        return $this->resolveByKey($planKey)['price_cents'] > 0;
    }

    /**
     * All configured plans, normalized, cheapest first.
     *
     * @return list<array{key: string, label: string, price_cents: int, max_servers: ?int, max_sites: ?int}>
     */
    public function all(): array
    {
        return array_values(array_map(
            fn (string $key, array $plan) => $this->normalize($key, $plan),
            array_keys($this->plans()),
            array_values($this->plans()),
        ));
    }

    /**
     * @return array<string, array{label?: string, price_cents?: int, max_servers?: ?int, max_sites?: ?int}>
     */
    private function plans(): array
    {
        return (array) config('subscription.standard.plans', []);
    }

    /**
     * @param  array{label?: string, price_cents?: int, max_servers?: ?int, max_sites?: ?int}  $plan
     * @return array{key: string, label: string, price_cents: int, max_servers: ?int, max_sites: ?int}
     */
    private function normalize(string $key, array $plan): array
    {
        $maxServers = $plan['max_servers'] ?? null;
        $maxSites = $plan['max_sites'] ?? null;

        return [
            'key' => $key,
            'label' => (string) ($plan['label'] ?? ucfirst($key)),
            'price_cents' => (int) ($plan['price_cents'] ?? 0),
            'max_servers' => $maxServers === null ? null : (int) $maxServers,
            'max_sites' => $maxSites === null ? null : (int) $maxSites,
        ];
    }
}
