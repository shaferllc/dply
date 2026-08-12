<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\ServerlessUsageSnapshot;
use App\Models\Site;
use App\Modules\Serverless\Models\FunctionInvocation;
use Carbon\CarbonInterface;

/**
 * Rolls up the operational {@see FunctionInvocation} log into daily
 * {@see ServerlessUsageSnapshot} rows for dply-managed functions — the FaaS
 * counterpart to {@see App\Modules\Edge\Services\EdgeUsageCollector}.
 *
 * Only managed functions (dply pays the provider) are metered; BYO functions
 * deploy to the customer's own account and are billed by their provider, so
 * they're skipped.
 *
 * GiB-seconds — the unit DigitalOcean actually bills ($0.0000185/GB-s) — are
 * computed from dply's own invocation log rather than a provider API: DO
 * exposes no usable per-function compute endpoint, but every invocation row
 * already carries `duration_ms`, and its action carries `memory_mb`. Their
 * product is the billable quantity, so the meter is derived from data dply
 * already owns. Rows whose action is unknown fall back to the site's
 * configured memory limit — the same value the deployer writes onto the
 * OpenWhisk action.
 */
class ServerlessUsageCollector
{
    /**
     * @return array{sites: int, invocations: int, gib_seconds: int}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $day = $date->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();
        $periodStart = $day->toDateString();
        $periodEnd = $dayEnd->toDateString();

        $sites = Site::query()
            ->where('serverless_backend', Site::SERVERLESS_BACKEND_DPLY)
            ->whereIn('status', [Site::STATUS_FUNCTIONS_ACTIVE, Site::STATUS_FUNCTIONS_CONFIGURED])
            ->get(['id', 'organization_id', 'meta']);

        $totalInvocations = 0;
        $totalGibSeconds = 0;
        $touched = 0;

        foreach ($sites as $site) {
            $usage = $this->usageForSite($site, $day, $dayEnd);

            $totalInvocations += $usage['invocations'];
            $totalGibSeconds += $usage['gib_seconds'];

            if ($dryRun) {
                $touched++;

                continue;
            }

            ServerlessUsageSnapshot::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'period_start' => $periodStart,
                    'source' => ServerlessUsageSnapshot::SOURCE_FUNCTION_INVOCATIONS,
                ],
                [
                    'organization_id' => $site->organization_id,
                    'period_end' => $periodEnd,
                    'invocations' => $usage['invocations'],
                    'gib_seconds' => $usage['gib_seconds'],
                    'meta' => ['by_source' => $usage['by_source']],
                ],
            );
            $touched++;
        }

        return [
            'sites' => $touched,
            'invocations' => $totalInvocations,
            'gib_seconds' => $totalGibSeconds,
        ];
    }

    /**
     * Aggregate one site's invocations for the day, split by source so the
     * platform's own tick/queue-pump burn stays visible next to organic web
     * traffic — the pump costs real GiB-seconds that no user request paid for.
     *
     * @return array{
     *     invocations: int,
     *     gib_seconds: int,
     *     by_source: array<string, array{invocations: int, gib_seconds: int}>,
     * }
     */
    private function usageForSite(Site $site, CarbonInterface $day, CarbonInterface $dayEnd): array
    {
        $fallbackMemoryMb = $site->serverlessLimits()['memory'];

        // toBase(): these are aggregate rows, not FunctionInvocation models —
        // the query builder hands back plain objects with the selected columns.
        $rows = FunctionInvocation::query()
            ->toBase()
            ->from('function_invocations')
            ->leftJoin('function_actions', 'function_actions.id', '=', 'function_invocations.function_action_id')
            ->where('function_invocations.site_id', $site->id)
            ->whereBetween('function_invocations.created_at', [$day, $dayEnd])
            ->groupBy('function_invocations.source')
            ->selectRaw('function_invocations.source as source')
            ->selectRaw('COUNT(*) as invocations')
            // NULLIF guards actions backfilled before memory_mb was recorded —
            // a 0 there would silently meter the function as free.
            ->selectRaw(
                'COALESCE(SUM(
                    (function_invocations.duration_ms / 1000.0)
                    * (COALESCE(NULLIF(function_actions.memory_mb, 0), ?) / 1024.0)
                ), 0) as gib_seconds',
                [$fallbackMemoryMb],
            )
            ->get();

        $invocations = 0;
        $gibSeconds = 0.0;
        $bySource = [];

        foreach ($rows as $row) {
            $rowInvocations = (int) $row->invocations;
            $rowGibSeconds = (float) $row->gib_seconds;

            $invocations += $rowInvocations;
            $gibSeconds += $rowGibSeconds;

            $bySource[(string) $row->source] = [
                'invocations' => $rowInvocations,
                'gib_seconds' => (int) round($rowGibSeconds),
            ];
        }

        return [
            'invocations' => $invocations,
            // Whole GiB-seconds: the snapshot column is an integer and a day's
            // rounding error is immaterial against a 90,000 GiB-s allowance.
            'gib_seconds' => (int) round($gibSeconds),
            'by_source' => $bySource,
        ];
    }
}
