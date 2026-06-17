<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\FunctionInvocation;
use App\Models\ServerlessUsageSnapshot;
use App\Models\Site;
use Carbon\CarbonInterface;

/**
 * Rolls up the operational {@see FunctionInvocation} log into daily
 * {@see ServerlessUsageSnapshot} rows for dply-managed functions — the FaaS
 * counterpart to {@see App\Services\Edge\EdgeUsageCollector}.
 *
 * Only managed functions (dply pays the provider) are metered; BYO functions
 * deploy to the customer's own account and are billed by their provider, so
 * they're skipped. DigitalOcean Functions exposes no usable per-function
 * compute API, so `gib_seconds` stays 0 and billing meters invocations.
 */
class ServerlessUsageCollector
{
    /**
     * @return array{sites: int, invocations: int}
     */
    /** @return array<string, mixed> */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $day = $date->copy()->startOfDay();
        $periodStart = $day->toDateString();
        $periodEnd = $day->copy()->endOfDay()->toDateString();

        $sites = Site::query()
            ->where('serverless_backend', Site::SERVERLESS_BACKEND_DPLY)
            ->whereIn('status', [Site::STATUS_FUNCTIONS_ACTIVE, Site::STATUS_FUNCTIONS_CONFIGURED])
            ->get(['id', 'organization_id']);

        $totalInvocations = 0;
        $touched = 0;

        foreach ($sites as $site) {
            $invocations = FunctionInvocation::query()
                ->where('site_id', $site->id)
                ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
                ->count();

            $totalInvocations += $invocations;

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
                    'invocations' => $invocations,
                    'gib_seconds' => 0,
                    'meta' => null,
                ],
            );
            $touched++;
        }

        return ['sites' => $touched, 'invocations' => $totalInvocations];
    }
}
