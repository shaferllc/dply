<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

/**
 * Resolved dply Logs entitlements for one org — the merge of the free-MVP
 * defaults with that org's subscription-plan overrides
 * ({@see ServerLogEntitlements}). Read-only; the numbers come from
 * config('server_logs.entitlements'). See docs/SERVER_LOGS_BILLING.md §1.2.
 */
final class ServerLogEntitlement
{
    private const BYTES_PER_GB = 1073741824; // 1024^3

    public function __construct(
        public readonly string $planKey,
        public readonly bool $available,
        public readonly int $retentionDays,
        public readonly int $monthlyIncludedGb,
        public readonly int $overagePerGbCents,
        public readonly ?int $maxServers,
        public readonly bool $alertingEnabled,
        public readonly bool $drainsEnabled,
        // Customer-protecting ingest ceiling. 0 = disabled (fail open — never
        // drop), so quota dropping does nothing until a cap is explicitly set.
        public readonly int $hardCapGb = 0,
    ) {}

    /**
     * Build from the defaults array overlaid with a plan override.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $override
     */
    public static function fromConfig(string $planKey, array $defaults, array $override = []): self
    {
        $merged = array_merge($defaults, $override);

        return new self(
            planKey: $planKey,
            available: (bool) ($merged['available'] ?? true),
            retentionDays: (int) ($merged['retention_days'] ?? 7),
            monthlyIncludedGb: (int) ($merged['monthly_included_gb'] ?? 0),
            overagePerGbCents: (int) ($merged['overage_per_gb_cents'] ?? 0),
            maxServers: isset($merged['max_servers']) ? (int) $merged['max_servers'] : null,
            alertingEnabled: (bool) ($merged['alerting_enabled'] ?? false),
            drainsEnabled: (bool) ($merged['drains_enabled'] ?? false),
            hardCapGb: max(0, (int) ($merged['hard_cap_gb'] ?? 0)),
        );
    }

    /** Included monthly volume expressed in bytes (what metering compares against). */
    public function includedBytes(): int
    {
        return $this->monthlyIncludedGb * self::BYTES_PER_GB;
    }

    /** True once metered usage exceeds the included allowance (billing/quota decisions). */
    public function isOverIncluded(int $usedBytes): bool
    {
        return $usedBytes > $this->includedBytes();
    }

    /** The hard ingest ceiling in bytes, or 0 when no cap is set (fail open). */
    public function hardCapBytes(): int
    {
        return $this->hardCapGb * self::BYTES_PER_GB;
    }

    /** True only when a cap is set AND usage has crossed it — the aggregator drops past this. */
    public function isHardCapped(int $usedBytes): bool
    {
        return $this->hardCapGb > 0 && $usedBytes > $this->hardCapBytes();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_key' => $this->planKey,
            'available' => $this->available,
            'retention_days' => $this->retentionDays,
            'monthly_included_gb' => $this->monthlyIncludedGb,
            'overage_per_gb_cents' => $this->overagePerGbCents,
            'max_servers' => $this->maxServers,
            'alerting_enabled' => $this->alertingEnabled,
            'drains_enabled' => $this->drainsEnabled,
            'hard_cap_gb' => $this->hardCapGb,
        ];
    }
}
