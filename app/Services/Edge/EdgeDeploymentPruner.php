<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prunes old SUPERSEDED edge deployments past the site's keep-count.
 * Deletes R2 (or fake local) artifacts and nulls storage_prefix while
 * keeping the row for audit (commit + timestamps preserved).
 */
class EdgeDeploymentPruner
{
    public function __construct(
        private readonly EdgeArtifactPublisher $artifactPublisher,
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    public function prune(Site $site): int
    {
        $keep = $this->keepCount($site);

        $eligible = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_SUPERSEDED)
            ->whereNotNull('storage_prefix')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        if ($eligible->count() <= $keep) {
            return 0;
        }

        $toPrune = $eligible->slice($keep);
        $pruned = 0;
        $fake = FakeEdgeProvision::enabled();
        $diskName = $fake ? null : $this->contextResolver->forSite($site)->diskName;

        foreach ($toPrune as $deployment) {
            try {
                if ($fake) {
                    File::deleteDirectory(
                        rtrim(FakeEdgeProvision::storageRoot(), '/').'/'.trim((string) $deployment->storage_prefix, '/')
                    );
                } else {
                    $this->artifactPublisher->deletePrefix((string) $deployment->storage_prefix, $diskName);
                }

                $deployment->update([
                    'storage_prefix' => null,
                    'pruned_at' => now(),
                ]);
                $pruned++;
            } catch (Throwable $e) {
                Log::warning('Edge prune failed for deployment', [
                    'deployment_id' => $deployment->id,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $pruned;
    }

    private function keepCount(Site $site): int
    {
        $configured = $site->releases_to_keep;
        $value = is_int($configured) && $configured > 0
            ? $configured
            : (int) config('edge.retention.default_keep', 10);

        return max(1, min(50, $value));
    }
}
