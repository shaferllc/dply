<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Server-scoped bulk actions across all sites on a VM host.
 */
final class ServerBulkSiteActions
{
    public function __construct(
        private ServerCertificateInventory $certificateInventory,
    ) {}

    /**
     * @return array{redeploy_count: int, renewable_count: int, site_names: list<string>}
     */
    /** @return array<string, mixed> */
    public function preview(Server $server): array
    {
        $deployable = $this->deployableSites($server);
        $renewReport = $this->certificateInventory->forServer($server);
        $renewable = collect($renewReport['items'] ?? [])
            ->filter(fn (array $item): bool => (bool) ($item['can_renew'] ?? false))
            ->count();

        return [
            'redeploy_count' => $deployable->count(),
            'renewable_count' => $renewable,
            'site_names' => $deployable->pluck('name')->map(fn ($name) => (string) $name)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed> $siteIds
     * @return array{redeploy_count: int, renewable_count: int, site_names: list<string>}
     */
    /** @return array<string, mixed> */
    public function previewSelected(Server $server, array $siteIds): array
    {
        $normalizedIds = $this->normalizeSiteIds($siteIds);
        $deployable = $this->deployableSites($server)
            ->filter(fn (Site $site): bool => in_array((string) $site->id, $normalizedIds, true));

        $renewReport = $this->certificateInventory->forServer($server);
        $renewable = collect($renewReport['items'] ?? [])
            ->filter(fn (array $item): bool => (bool) ($item['can_renew'] ?? false))
            ->count();

        return [
            'redeploy_count' => $deployable->count(),
            'renewable_count' => $renewable,
            'site_names' => $deployable->pluck('name')->map(fn ($name) => (string) $name)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed> $siteIds
     * @return array{queued: int}
     */
    /** @return array<string, mixed> */
    public function redeployAll(Server $server, User $actor): array
    {
        return $this->redeploySelected(
            $server,
            $this->deployableSites($server)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            $actor,
        );
    }

    /**
     * @param  array<string, mixed> $siteIds
     * @return array{queued: int}
     */
    /** @return array<string, mixed> */
    public function redeploySelected(Server $server, array $siteIds, User $actor): array
    {
        $normalizedIds = $this->normalizeSiteIds($siteIds);
        $queued = 0;

        foreach ($this->deployableSites($server) as $site) {
            if (! in_array((string) $site->id, $normalizedIds, true)) {
                continue;
            }

            RunSiteDeploymentJob::dispatch(
                $site->fresh(),
                SiteDeployment::TRIGGER_MANUAL,
                null,
                (string) $actor->id,
            );
            $queued++;
        }

        return ['queued' => $queued];
    }

    /**
     * @param  array<string, mixed> $siteIds
     * @return list<string>
     */
    private function normalizeSiteIds(array $siteIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $siteIds,
        ))));
    }

    /**
     * @return Collection<int, Site>
     */
    private function deployableSites(Server $server): Collection
    {
        if (! $server->isVmHost() || ! $server->isReady()) {
            return collect();
        }

        return $server->sites()
            ->get()
            ->filter(function (Site $site): bool {
                if ($site->isSuspended()) {
                    return false;
                }

                return $site->isReadyForTraffic();
            })
            ->values();
    }
}
