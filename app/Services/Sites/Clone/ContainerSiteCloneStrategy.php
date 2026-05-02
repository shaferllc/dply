<?php

namespace App\Services\Sites\Clone;

use App\Jobs\ProvisionSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Docker / Kubernetes runtime: clone control-plane metadata and re-run provisioning (no remote volume clone).
 */
final class ContainerSiteCloneStrategy
{
    public function __construct(
        private readonly SiteProvisioner $siteProvisioner,
    ) {}

    public function execute(Site $source, Server $destServer, string $primaryHostname, string $name): Site
    {
        $slug = Str::slug($name) ?: 'site';

        $attributes = SiteCloneAttributeMapper::baseAttributes($source, $destServer, $name, $slug, $primaryHostname);
        $attributes = SiteCloneAttributeMapper::withCloneMeta($attributes, $source, 'in_progress', null);

        return DB::transaction(function () use ($source, $attributes, $primaryHostname): Site {
            /** @var Site $newSite */
            $newSite = Site::query()->create($attributes);
            $newSite->ensureUniqueSlug();
            $newSite->save();

            SiteDomain::query()->create([
                'site_id' => $newSite->id,
                'hostname' => strtolower(trim($primaryHostname)),
                'is_primary' => true,
                'www_redirect' => false,
            ]);

            SiteCloneDeployRowsReplicator::replicate($source, $newSite);

            $meta = is_array($newSite->meta) ? $newSite->meta : [];
            $meta['clone'] = [
                'source_site_id' => $source->id,
                'status' => 'completed',
                'message' => null,
                'at' => now()->toIso8601String(),
            ];
            $newSite->update(['meta' => $meta]);

            $this->siteProvisioner->markQueued($newSite->fresh(['server', 'domains']));
            ProvisionSiteJob::dispatch($newSite->id);

            return $newSite->fresh();
        });
    }
}
