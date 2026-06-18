<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Converts measured dply-managed serverless usage into customer-facing cents,
 * applying a per-function included allowance and configurable unit rates
 * (rates embed platform margin). Mirrors {@see EdgeUsageCostCalculator}.
 *
 * v1 meters invocations (DigitalOcean Functions has no usable per-function
 * compute API); `gib_seconds` is summed and billed only when a backing provider
 * reports it (Cloudflare/AWS), and stays 0 — hence free — for DO functions.
 */
class ServerlessUsageCostCalculator
{
    public function isEnabled(): bool
    {
        return (bool) config('dply.serverless.usage_billing.enabled', false);
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     billable_invocations: int,
     *     billable_gib_seconds: int,
     *     included_invocations: int,
     *     included_gib_seconds: int,
     * }
     */
    /** @return array<string, mixed> */
    public function estimate(ServerlessUsageTotals $usage, int $functionCount): array
    {
        if (! $this->isEnabled() || $functionCount <= 0) {
            return $this->emptyEstimate();
        }

        $includedInvocations = $functionCount * max(0, (int) config('dply.serverless.usage_billing.included_invocations_per_function', 0));
        $includedGibSeconds = $functionCount * max(0, (int) config('dply.serverless.usage_billing.included_gib_seconds_per_function', 0));

        $billableInvocations = max(0, $usage->invocations - $includedInvocations);
        $billableGibSeconds = max(0, $usage->gibSeconds - $includedGibSeconds);

        $subtotal = $this->invocationsCents($billableInvocations) + $this->gibSecondsCents($billableGibSeconds);
        $subtotal = $this->applyMarkup($subtotal);

        return [
            'subtotal_cents' => max(0, $subtotal),
            'billable_invocations' => $billableInvocations,
            'billable_gib_seconds' => $billableGibSeconds,
            'included_invocations' => $includedInvocations,
            'included_gib_seconds' => $includedGibSeconds,
        ];
    }

    private function invocationsCents(int $billableInvocations): int
    {
        if ($billableInvocations <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.serverless.usage_billing.invocations_cents_per_million', 0));

        return (int) ceil($billableInvocations / 1_000_000 * $rate);
    }

    private function gibSecondsCents(int $billableGibSeconds): int
    {
        if ($billableGibSeconds <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.serverless.usage_billing.gib_seconds_cents_per_100k', 0));

        return (int) ceil($billableGibSeconds / 100_000 * $rate);
    }

    private function applyMarkup(int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }

        $markup = max(0, (int) config('dply.serverless.usage_billing.markup_percent', 0));

        return (int) ceil($subtotalCents * (100 + $markup) / 100);
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     billable_invocations: int,
     *     billable_gib_seconds: int,
     *     included_invocations: int,
     *     included_gib_seconds: int,
     * }
     */
    private function emptyEstimate(): array
    {
        return [
            'subtotal_cents' => 0,
            'billable_invocations' => 0,
            'billable_gib_seconds' => 0,
            'included_invocations' => 0,
            'included_gib_seconds' => 0,
        ];
    }
}
