<?php

namespace App\Services\Sites\Clone;

use App\Jobs\ProvisionSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Servers\ServerSystemUserService;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VmSiteCloneStrategy
{
    public function __construct(
        private readonly RepositoryTreeCopier $treeCopier,
        private readonly ServerSystemUserService $systemUserService,
        private readonly SiteProvisioner $siteProvisioner,
    ) {}

    /**
     * @throws \Throwable
     */
    public function execute(Site $source, Server $destServer, string $primaryHostname, string $name): Site
    {
        $source->loadMissing('server');
        $slug = Str::slug($name) ?: 'site';

        $attributes = SiteCloneAttributeMapper::baseAttributes($source, $destServer, $name, $slug, $primaryHostname);
        $attributes = SiteCloneAttributeMapper::withCloneMeta($attributes, $source, 'in_progress', null);

        $newSite = DB::transaction(function () use ($source, $attributes, $primaryHostname): Site {
            /** @var Site $created */
            $created = Site::query()->create($attributes);
            $created->ensureUniqueSlug();
            $created->save();

            SiteDomain::query()->create([
                'site_id' => $created->id,
                'hostname' => strtolower(trim($primaryHostname)),
                'is_primary' => true,
                'www_redirect' => false,
            ]);

            SiteCloneDeployRowsReplicator::replicate($source, $created);

            return $created->fresh(['server']);
        });

        $srcPath = rtrim($source->effectiveRepositoryPath(), '/');
        $dstPath = rtrim($newSite->effectiveRepositoryPath(), '/');

        try {
            $this->treeCopier->copyTree($source->server, $srcPath, $destServer, $dstPath);

            $user = $newSite->effectiveSystemUser($destServer);
            $this->systemUserService->chownSiteRepositoryTree($newSite->fresh(['server']), $user);
        } catch (\Throwable $e) {
            $this->markCloneFailed($newSite, $source, $e->getMessage());
            $newSite->update(['status' => Site::STATUS_ERROR]);

            throw $e;
        }

        $newSite->refresh();
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
    }

    private function markCloneFailed(Site $newSite, Site $source, string $message): void
    {
        $meta = is_array($newSite->meta) ? $newSite->meta : [];
        $meta['clone'] = [
            'source_site_id' => $source->id,
            'status' => 'failed',
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $newSite->update(['meta' => $meta]);
    }
}
