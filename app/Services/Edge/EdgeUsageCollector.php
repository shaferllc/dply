<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeUsageSnapshot;
use App\Models\Site;
use App\Services\Billing\EdgeUsageTotals;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Persists daily Edge usage snapshots per site. v1 pulls from Cloudflare when
 * credentials + zone are configured; otherwise records zero rows tagged
 * {@see EdgeUsageSnapshot::SOURCE_PLACEHOLDER} so billing hooks stay wired.
 */
class EdgeUsageCollector
{
    public function __construct(
        private ?EdgeCloudflareClient $cloudflare = null,
    ) {}

    private function client(): EdgeCloudflareClient
    {
        return $this->cloudflare ?? EdgeCloudflareClient::fromConfig();
    }

    /**
     * @return array{sites: int, snapshots: int, source: string}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $periodStart = $date->copy()->startOfDay();
        $periodEnd = $date->copy()->endOfDay();
        $source = $this->resolveSource();
        $sites = $this->billableEdgeSites();
        $snapshotCount = 0;

        $usageByHostname = $source === EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL
            ? $this->fetchCloudflareUsage($periodStart, $periodEnd, $sites)
            : collect();

        foreach ($sites as $site) {
            $hostname = strtolower(trim((string) optional($site->primaryDomain())->hostname));
            $usage = $hostname !== '' && $usageByHostname->has($hostname)
                ? $usageByHostname->get($hostname)
                : new EdgeUsageTotals;

            if ($dryRun) {
                $snapshotCount++;

                continue;
            }

            EdgeUsageSnapshot::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'period_start' => $periodStart->toDateString(),
                    'source' => $source,
                ],
                [
                    'organization_id' => $site->organization_id,
                    'period_end' => $periodEnd->toDateString(),
                    'requests' => $usage->requests,
                    'bytes_egress' => $usage->bytesEgress,
                    'r2_storage_bytes' => $usage->r2StorageBytes,
                    'r2_class_a_ops' => $usage->r2ClassAOps,
                    'r2_class_b_ops' => $usage->r2ClassBOps,
                    'meta' => [
                        'hostname' => $hostname !== '' ? $hostname : null,
                        'collector' => 'EdgeUsageCollector',
                        'cloudflare_automated' => $source === EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL,
                    ],
                ],
            );

            $snapshotCount++;
        }

        if ($source === EdgeUsageSnapshot::SOURCE_PLACEHOLDER && $sites->isNotEmpty()) {
            Log::info('edge.usage.collector_placeholder', [
                'date' => $periodStart->toDateString(),
                'sites' => $sites->count(),
                'hint' => 'Set DPLY_EDGE_CF_ACCOUNT_ID, DPLY_EDGE_CF_API_TOKEN, and DPLY_EDGE_CF_ZONE_NAME for automated Cloudflare GraphQL collection.',
            ]);
        }

        return [
            'sites' => $sites->count(),
            'snapshots' => $snapshotCount,
            'source' => $source,
        ];
    }

    /**
     * @return Collection<int, Site>
     */
    private function billableEdgeSites(): Collection
    {
        return Site::query()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->whereNotNull('edge_backend')
            ->where('edge_backend', '!=', '')
            ->with(['domains' => fn ($q) => $q->orderByDesc('is_primary')])
            ->get()
            ->reject(fn (Site $site): bool => $site->isEdgePreview())
            ->values();
    }

    private function resolveSource(): string
    {
        if ($this->client()->canCollectAnalytics()) {
            return EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL;
        }

        return EdgeUsageSnapshot::SOURCE_PLACEHOLDER;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<string, EdgeUsageTotals>
     */
    private function fetchCloudflareUsage(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        Collection $sites,
    ): Collection {
        $hostnames = $sites
            ->map(fn (Site $site): string => strtolower(trim((string) optional($site->primaryDomain())->hostname)))
            ->filter(fn (string $hostname): bool => $hostname !== '')
            ->values()
            ->all();

        if ($hostnames === []) {
            return collect();
        }

        try {
            return $this->client()->fetchHttpUsageByHostnames(
                $hostnames,
                $periodStart,
                $periodEnd,
            );
        } catch (\Throwable $e) {
            Log::warning('edge.usage.cloudflare_fetch_failed', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }
}
