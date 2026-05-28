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
     * @return array{queued: int}
     */
    public function redeployAll(Server $server, User $actor): array
    {
        $queued = 0;

        foreach ($this->deployableSites($server) as $site) {
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
