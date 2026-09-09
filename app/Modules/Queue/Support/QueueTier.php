<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * A dply Queue capacity tier — what a namespace reserves and what it costs.
 *
 * Capacity is tier-scoped rather than plan-scoped because a namespace is
 * priced per resource, the way a Realtime app is: the price is the limiter, so
 * there is nothing for the subscription plan to cap. `QueueEntitlement` still
 * decides how many namespaces an org may hold; this decides what one is.
 *
 * Mirrors the shape of `config('realtime.tiers')` on purpose — both managed
 * services bill through one mechanism. See docs/adr/managed-services-tier.md,
 * decision 6.
 */
final readonly class QueueTier
{
    public function __construct(
        public string $slug,
        public string $label,
        /** Push rejection threshold for a namespace on this tier. 0 = unlimited. */
        public int $maxQueueDepth,
        /** Per-namespace API rate limit. */
        public int $requestsPerMinute,
        public int $priceCents,
    ) {}

    /**
     * Resolve a tier by slug, falling back to the configured default and then
     * to the first tier defined. Never returns null: an unknown slug on a row
     * is a data problem, but rejecting the request over it would take a
     * customer's queue down for a config typo.
     */
    public static function resolve(?string $slug): self
    {
        $tiers = (array) config('queue_service.tiers', []);

        $key = (string) ($slug ?? '');

        if (! is_array($tiers[$key] ?? null)) {
            $key = (string) config('queue_service.default_tier', 'standard');
        }

        if (! is_array($tiers[$key] ?? null)) {
            $key = (string) array_key_first($tiers);
        }

        /** @var array<string, mixed> $config */
        $config = is_array($tiers[$key] ?? null) ? $tiers[$key] : [];

        return new self(
            slug: $key,
            label: (string) ($config['label'] ?? ucfirst($key)),
            maxQueueDepth: max(0, (int) ($config['max_queue_depth'] ?? 0)),
            requestsPerMinute: max(1, (int) ($config['requests_per_minute'] ?? 600)),
            priceCents: max(0, (int) ($config['price_cents'] ?? 0)),
        );
    }

    /** @return array<string, self> Every configured tier, keyed by slug. */
    public static function all(): array
    {
        $out = [];

        foreach (array_keys((array) config('queue_service.tiers', [])) as $slug) {
            $out[(string) $slug] = self::resolve((string) $slug);
        }

        return $out;
    }

    /** Fail-open, matching QueueEntitlement: 0 means no ceiling. */
    public function hasQueueDepthLimit(): bool
    {
        return $this->maxQueueDepth > 0;
    }
}
