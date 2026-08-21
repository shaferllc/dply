<?php

declare(strict_types=1);

namespace App\Modules\Cache\Actions;

use App\Models\ServiceCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Cache\Models\CacheSite;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Support\CacheWiring;
use App\Support\Sites\SiteEnvFile;
use RuntimeException;

/**
 * Point a site at a cache.
 *
 * The pivot is the source of truth (docs/adr/dply-cache.md, decision 4). What
 * else happens depends on the runtime, and neither branch is an alternative
 * mechanism — both are projections of the same row:
 *
 *  - a **serverless** function has no bindings, so the env map is merged into
 *    `Site.env_file_content`, which is what the artifact builder bundles;
 *  - any other runtime additionally gets a `SiteBinding` of type `cache`, so
 *    the workspace Resources tab keeps telling the truth. That row is written
 *    FROM the pivot and never read back as authority.
 *
 * One cache per site in v1, enforced by the unique on `cache_site.site_id`.
 * Re-attaching a different cache is a swap, not an error: the old keys are
 * stripped first so a detach/attach cycle cannot leave two endpoints behind.
 */
final class AttachCacheToSite
{
    public function __construct(private readonly DetachCacheFromSite $detach) {}

    /**
     * @return array<string, string> the env map written to the site
     */
    public function handle(ManagedCache $cache, Site $site, ?string $keyPrefix = null): array
    {
        if ($cache->organization_id !== $site->organization_id) {
            throw new RuntimeException(__('That cache belongs to a different organization.'));
        }

        if (! $cache->isActive()) {
            throw new RuntimeException(__('That cache is not ready yet.'));
        }

        // A site may hold one cache. Swapping goes through detach so the
        // previous cache's keys are removed rather than overwritten in place —
        // an overwrite leaves any key the new map does not happen to set.
        $existing = CacheSite::query()->where('site_id', $site->id)->first();

        if ($existing instanceof CacheSite) {
            if ($existing->cache_id === $cache->id) {
                $this->detach->handle($cache, $site);
            } else {
                $previous = ManagedCache::query()->find($existing->cache_id);

                if ($previous instanceof ManagedCache) {
                    $this->detach->handle($previous, $site);
                }
            }
        }

        // A credential per site, so detaching one site cannot break another.
        $minted = (new MintCacheCredential)->handle(
            $cache,
            __('Site: :site', ['site' => $site->name ?: $site->id]),
        );

        CacheSite::query()->create([
            'cache_id' => $cache->id,
            'site_id' => $site->id,
            'key_prefix' => $keyPrefix,
        ]);

        $env = CacheWiring::envFor($cache, $minted['credential'], $minted['plaintext'], $keyPrefix);

        SiteEnvFile::merge($site, $env);

        if ($site->serverless_backend === null) {
            $this->writeBindingProjection($site, $cache, $env);
        }

        return $env;
    }

    /**
     * Mirror the attachment into the VM binding system.
     *
     * Written from the pivot, never read as authority — decision 4 chose the
     * pivot precisely because `SiteBinding` is VM-only and its `cache` type is
     * a target-less driver choice. This keeps the Resources tab honest without
     * making it a second source of truth.
     *
     * @param  array<string, string>  $env
     */
    private function writeBindingProjection(Site $site, ManagedCache $cache, array $env): void
    {
        SiteBinding::query()->updateOrCreate(
            ['site_id' => $site->id, 'type' => 'cache'],
            [
                'mode' => 'attach_existing',
                'status' => SiteBinding::STATUS_CONFIGURED,
                'name' => 'dply-cache-'.$cache->name,
                'target_type' => 'managed_cache',
                'target_id' => $cache->id,
                'injected_env' => $env,
                'config' => ['driver' => 'dynamodb', 'cache_id' => $cache->id],
            ],
        );
    }
}
