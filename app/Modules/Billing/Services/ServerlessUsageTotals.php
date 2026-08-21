<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Aggregated dply-managed serverless usage for an organization over a billing
 * window. The FaaS counterpart to {@see EdgeUsageTotals}.
 *
 * `assetStorageBytes` is a LEVEL, not a flow: it is the organization's average
 * stored bytes over the window, which is what a per-GiB-month rate prices.
 * {@see ServerlessOrganizationUsageReader} does that time-averaging in SQL.
 * {@see self::add()} therefore sums it, because adding two totals means
 * combining two sites at the same instant — not two days of the same site.
 */
readonly class ServerlessUsageTotals
{
    public function __construct(
        public int $invocations = 0,
        public int $gibSeconds = 0,
        public int $assetStorageBytes = 0,
        public int $assetBytesEgress = 0,
        public int $assetRequests = 0,
    ) {}

    public function add(self $other): self
    {
        return new self(
            invocations: $this->invocations + $other->invocations,
            gibSeconds: $this->gibSeconds + $other->gibSeconds,
            assetStorageBytes: $this->assetStorageBytes + $other->assetStorageBytes,
            assetBytesEgress: $this->assetBytesEgress + $other->assetBytesEgress,
            assetRequests: $this->assetRequests + $other->assetRequests,
        );
    }

    public function isEmpty(): bool
    {
        return $this->invocations === 0
            && $this->gibSeconds === 0
            && $this->assetStorageBytes === 0
            && $this->assetBytesEgress === 0
            && $this->assetRequests === 0;
    }

    public static function empty(): self
    {
        return new self;
    }
}
