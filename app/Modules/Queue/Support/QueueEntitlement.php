<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

use App\Modules\Logs\Services\ServerLogEntitlement;

/**
 * Resolved dply Queue entitlements for one org — the launch defaults merged
 * with that org's subscription-plan overrides ({@see QueueEntitlements}).
 * Read-only; the numbers come from config('queue_service.entitlements').
 *
 * Mirrors {@see ServerLogEntitlement}, including
 * its fail-open convention: a limit of 0 means "no limit", so nothing is
 * enforced until a number is deliberately set. A queue that silently starts
 * rejecting pushes is worse than one that costs us a little.
 *
 * Scope note: this answers "what may this ORG do" — may it use the product,
 * how many namespaces may it hold, how large may one message be. Capacity
 * (depth, request rate) is not here: it belongs to the namespace's
 * {@see QueueTier}, because a namespace is priced per resource and the price
 * is the limiter. See docs/adr/managed-services-tier.md, decision 6.
 */
final class QueueEntitlement
{
    public function __construct(
        public readonly string $planKey,
        public readonly bool $available,
        /** Tier a newly created namespace starts on. */
        public readonly string $tier,
        /** 0 = unlimited. */
        public readonly int $maxNamespaces,
        public readonly int $maxPayloadBytes,
    ) {}

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $override
     */
    public static function fromConfig(string $planKey, array $defaults, array $override = []): self
    {
        $merged = array_merge($defaults, $override);

        return new self(
            planKey: $planKey,
            available: (bool) ($merged['available'] ?? true),
            tier: (string) ($merged['tier'] ?? config('queue_service.default_tier', 'standard')),
            maxNamespaces: max(0, (int) ($merged['max_namespaces'] ?? 0)),
            maxPayloadBytes: max(0, (int) ($merged['max_payload_bytes'] ?? 262144)),
        );
    }

    public function hasNamespaceLimit(): bool
    {
        return $this->maxNamespaces > 0;
    }

    /** The capacity a namespace created under this entitlement starts with. */
    public function defaultTier(): QueueTier
    {
        return QueueTier::resolve($this->tier);
    }
}
