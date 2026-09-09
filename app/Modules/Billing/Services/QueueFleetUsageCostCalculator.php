<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Converts metered managed-queue usage into customer-facing cents.
 *
 * Two quantities, priced independently (docs/adr/managed-queue-workers.md,
 * decision 6): worker time in MiB-seconds by compute class, and job lifecycle
 * operations. Rates are configured in millicents, because a MiB-second priced
 * in whole cents is either free or absurd — the rounding to whole cents
 * happens once, at the end, on the total.
 *
 * Dark by default, like {@see ServerLogUsageCostCalculator}: returns zero
 * unless `queue_service.billing.enabled` is on. Pricing a product before its
 * rates are calibrated is how a customer gets an invoice nobody intended to
 * send.
 */
class QueueFleetUsageCostCalculator
{
    private const MILLICENTS_PER_CENT = 1_000;

    public function isEnabled(): bool
    {
        return (bool) config('queue_service.billing.enabled', false);
    }

    /**
     * @param  array{flex_mib_seconds: int, pro_mib_seconds: int, operations: int}  $totals
     * @return array{
     *     subtotal_cents: int,
     *     worker_cents: int,
     *     operations_cents: int,
     *     flex_mib_seconds: int,
     *     pro_mib_seconds: int,
     *     operations: int,
     *     enabled: bool,
     * }
     */
    public function estimate(array $totals): array
    {
        // No null-coalescing: the shape is guaranteed by the parameter type,
        // and a fallback here would only hide a reader that stopped filling it.
        $flex = max(0, $totals['flex_mib_seconds']);
        $pro = max(0, $totals['pro_mib_seconds']);
        $operations = max(0, $totals['operations']);

        if (! $this->isEnabled()) {
            return $this->empty($flex, $pro, $operations);
        }

        $flexRate = (float) config('queue_service.fleets.pricing.flex_millicents_per_mib_second', 0);
        $proRate = $flexRate * (float) config('queue_service.fleets.pricing.pro_multiplier', 1.2);
        $opsRate = (float) config('queue_service.fleets.pricing.millicents_per_million_operations', 0);

        // Accumulated in millicents and rounded once. Rounding each component
        // to cents first would charge a rounding error per line on an invoice
        // whose lines are fractions of a cent each.
        $workerMillicents = ($flex * $flexRate) + ($pro * $proRate);
        $operationsMillicents = ($operations / 1_000_000) * $opsRate;

        $workerCents = (int) ceil($workerMillicents / self::MILLICENTS_PER_CENT);
        $operationsCents = (int) ceil($operationsMillicents / self::MILLICENTS_PER_CENT);

        return [
            'subtotal_cents' => $workerCents + $operationsCents,
            'worker_cents' => $workerCents,
            'operations_cents' => $operationsCents,
            'flex_mib_seconds' => $flex,
            'pro_mib_seconds' => $pro,
            'operations' => $operations,
            'enabled' => true,
        ];
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     worker_cents: int,
     *     operations_cents: int,
     *     flex_mib_seconds: int,
     *     pro_mib_seconds: int,
     *     operations: int,
     *     enabled: bool,
     * }
     */
    private function empty(int $flex, int $pro, int $operations): array
    {
        // Quantities still reported: the numbers have to be visible before
        // anyone can calibrate a price against them.
        return [
            'subtotal_cents' => 0,
            'worker_cents' => 0,
            'operations_cents' => 0,
            'flex_mib_seconds' => $flex,
            'pro_mib_seconds' => $pro,
            'operations' => $operations,
            'enabled' => false,
        ];
    }
}
