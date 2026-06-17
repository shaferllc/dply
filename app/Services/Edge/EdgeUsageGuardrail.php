<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeUsageSnapshot;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Computes a {@see EdgeGuardrailStatus} for a single Edge site against the
 * monthly request + egress caps in config/edge.php. Pulls totals from
 * EdgeUsageSnapshot (calendar-month window, UTC) and decides ok/warn/over
 * based on the worse of the two metrics. Pure read; persistence is the
 * caller's job (so the cron can detect transitions before writing).
 */
final class EdgeUsageGuardrail
{
    /**
     * @param  array<string, mixed>|null  $configOverride  optional override for tests
     */
    public function __construct(private readonly ?array $configOverride = null) {}

    public function evaluate(Site $site, ?Carbon $now = null): EdgeGuardrailStatus
    {
        $config = $this->configOverride ?? (array) config('edge.guardrail', []);
        $requestsCap = max(0, (int) ($config['requests_per_month'] ?? 0));
        $bytesCap = max(0, (int) ($config['bytes_per_month'] ?? 0));
        $warnAt = max(1, min(99, (int) ($config['warn_at_percent'] ?? 80)));

        $now ??= now();
        $periodStart = $now->copy()->utc()->startOfMonth();
        $periodEnd = $now->copy()->utc()->endOfMonth();

        $totals = EdgeUsageSnapshot::query()
            ->where('site_id', $site->id)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->selectRaw('COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(bytes_egress), 0) AS bytes_egress')
            ->first();

        $requests = (int) ($totals->requests ?? 0);
        $bytesEgress = (int) ($totals->bytes_egress ?? 0);

        $state = $this->resolveState(
            requests: $requests,
            requestsCap: $requestsCap,
            bytesEgress: $bytesEgress,
            bytesCap: $bytesCap,
            warnAt: $warnAt,
        );

        return new EdgeGuardrailStatus(
            state: $state,
            requests: $requests,
            bytesEgress: $bytesEgress,
            requestsCap: $requestsCap,
            bytesEgressCap: $bytesCap,
            warnAtPercent: $warnAt,
            evaluatedAt: new \DateTimeImmutable($now->copy()->utc()->toIso8601String()),
            periodStart: new \DateTimeImmutable($periodStart->toDateString()),
            periodEnd: new \DateTimeImmutable($periodEnd->toDateString()),
        );
    }

    private function resolveState(int $requests, int $requestsCap, int $bytesEgress, int $bytesCap, int $warnAt): string
    {
        $reqPct = $requestsCap > 0 ? ($requests / $requestsCap) * 100 : 0;
        $bytesPct = $bytesCap > 0 ? ($bytesEgress / $bytesCap) * 100 : 0;
        $worst = max($reqPct, $bytesPct);

        if ($worst >= 100) {
            return EdgeGuardrailStatus::STATE_OVER;
        }
        if ($worst >= $warnAt) {
            return EdgeGuardrailStatus::STATE_WARN;
        }

        return EdgeGuardrailStatus::STATE_OK;
    }
}
