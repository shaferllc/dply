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
 */
final class QueueEntitlement
{
    public function __construct(
        public readonly string $planKey,
        public readonly bool $available,
        public readonly string $tier,
        /** Jobs included per month before overage. 0 = unlimited. */
        public readonly int $monthlyIncludedJobs,
        public readonly int $overagePerMillionJobsCents,
        /** 0 = unlimited. */
        public readonly int $maxNamespaces,
        /** Push rejection threshold, guarding the shared table. 0 = unlimited. */
        public readonly int $maxQueueDepth,
        public readonly int $maxPayloadBytes,
        /** Per-namespace API rate limit. */
        public readonly int $requestsPerMinute,
        /**
         * Customer-protecting ceiling on jobs pushed per month. 0 = disabled
         * (fail open — never reject), so this does nothing until set.
         */
        public readonly int $hardCapJobs = 0,
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
            tier: (string) ($merged['tier'] ?? 'standard'),
            monthlyIncludedJobs: max(0, (int) ($merged['monthly_included_jobs'] ?? 0)),
            overagePerMillionJobsCents: max(0, (int) ($merged['overage_per_million_jobs_cents'] ?? 0)),
            maxNamespaces: max(0, (int) ($merged['max_namespaces'] ?? 0)),
            maxQueueDepth: max(0, (int) ($merged['max_queue_depth'] ?? 0)),
            maxPayloadBytes: max(0, (int) ($merged['max_payload_bytes'] ?? 262144)),
            requestsPerMinute: max(1, (int) ($merged['requests_per_minute'] ?? 600)),
            hardCapJobs: max(0, (int) ($merged['hard_cap_jobs'] ?? 0)),
        );
    }

    public function hasQueueDepthLimit(): bool
    {
        return $this->maxQueueDepth > 0;
    }

    public function hasHardCap(): bool
    {
        return $this->hardCapJobs > 0;
    }
}
