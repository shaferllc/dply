<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Aggregated dply-managed serverless usage for an organization over a billing
 * window. The FaaS counterpart to {@see EdgeUsageTotals}.
 */
readonly class ServerlessUsageTotals
{
    public function __construct(
        public int $invocations = 0,
        public int $gibSeconds = 0,
    ) {}

    public function add(self $other): self
    {
        return new self(
            invocations: $this->invocations + $other->invocations,
            gibSeconds: $this->gibSeconds + $other->gibSeconds,
        );
    }

    public function isEmpty(): bool
    {
        return $this->invocations === 0 && $this->gibSeconds === 0;
    }

    public static function empty(): self
    {
        return new self;
    }
}
