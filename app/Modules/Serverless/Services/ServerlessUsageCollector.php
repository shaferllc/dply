<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\ServerlessUsageSnapshot;
use App\Models\Site;
use App\Modules\Serverless\Models\FunctionInvocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
 *
 * Published front-end assets are metered here too, from two sources that both
 * come from elsewhere:
 *
 *  - storage, measured exactly by {@see ServerlessAssetGarbageCollector} while
 *    it sweeps each site's bucket prefix, and left on the site for this
 *    roll-up to read;
 *  - egress, read per hostname from Cloudflare by
 *    {@see ServerlessAssetEgressReader}, deduplicated so a custom asset domain
 *    is not billed twice.
 *
 * Both degrade to zero rather than failing the run, so a Cloudflare outage or
 * an unswept bucket costs a day of asset numbers, not the compute meter.
 */
class ServerlessUsageCollector
{
    public function __construct(private ?ServerlessAssetEgressReader $assetEgress = null) {}

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

        $assetEgress = $this->assetEgressForSites($sites, $day, $dayEnd);

        $totalInvocations = 0;
        $totalGibSeconds = 0;
        $touched = 0;

        foreach ($sites as $site) {
            $usage = $this->usageForSite($site, $day, $dayEnd);
            $assets = $assetEgress[(string) $site->id] ?? ['requests' => 0, 'bytes' => 0, 'by_hostname' => []];
            $assetStorageBytes = $this->assetStorageBytes($site);

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
                    'asset_storage_bytes' => $assetStorageBytes,
                    'asset_bytes_egress' => $assets['bytes'],
                    'asset_requests' => $assets['requests'],
                    'meta' => [
                        'by_source' => $usage['by_source'],
                        // Raw per-hostname numbers, kept so the billed total
                        // stays auditable and so a change to the
                        // double-counting rule can be recomputed from stored
                        // data instead of re-collected.
                        'assets_by_hostname' => $assets['by_hostname'] ?: null,
                    ],
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
     * Last measured stored bytes for a site, written by the asset garbage
     * collector. Zero until it has swept the site once.
     */
    private function assetStorageBytes(Site $site): int
    {
        $assets = $site->serverlessConfig()['assets'] ?? [];

        return is_array($assets) ? max(0, (int) ($assets['storage_bytes'] ?? 0)) : 0;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, array{requests: int, bytes: int, by_hostname: array<string, array{requests: int, bytes: int}>}>
     */
    private function assetEgressForSites(Collection $sites, CarbonInterface $start, CarbonInterface $end): array
    {
        try {
            return $this->assetEgressReader()->usageForSites($sites, $start, $end);
        } catch (\Throwable $e) {
            Log::warning('serverless.usage.asset_egress_failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function assetEgressReader(): ServerlessAssetEgressReader
    {
        return $this->assetEgress ?? app(ServerlessAssetEgressReader::class);
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
