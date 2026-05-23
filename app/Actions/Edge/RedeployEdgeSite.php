<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Jobs\BuildEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Queue a fresh Edge deployment for an existing site — used by
 * manual redeploys and GitHub push webhooks.
 */
class RedeployEdgeSite
{
    public function handle(Site $site, ?string $gitCommit = null): EdgeDeployment
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \RuntimeException('Site is not an Edge delivery site.');
        }

        $edge = $site->edgeMeta();
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $branch = (string) ($source['branch'] ?? 'main');

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$site->organization_id.'/'.$site->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $site->organization_id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'git_commit' => $gitCommit,
            'storage_prefix' => $prefix,
        ]);

        $site->update(['status' => Site::STATUS_EDGE_PROVISIONING]);

        BuildEdgeSiteJob::dispatch($deployment->id);

        return $deployment;
    }
}
