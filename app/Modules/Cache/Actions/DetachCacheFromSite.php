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
use Illuminate\Support\Facades\Cache as CacheFacade;

/**
 * Unpoint a site from a cache.
 *
 * Strips exactly the keys attach wrote — the same discipline
 * `CloudDatabase::connectionEnvKeys()` exists for — rather than guessing at
 * what looks cache-shaped. A detach that leaves DYNAMODB_ENDPOINT behind while
 * removing the credential produces an app that fails on every cache call with
 * an auth error, which is a worse outcome than not detaching at all.
 *
 * The site's credential is revoked, not deleted: an operator debugging "why did
 * this stop working" should find a revoked key with a timestamp, not silence.
 */
final class DetachCacheFromSite
{
    public function handle(ManagedCache $cache, Site $site): void
    {
        CacheSite::query()
            ->where('site_id', $site->id)
            ->where('cache_id', $cache->id)
            ->delete();

        SiteEnvFile::strip($site, CacheWiring::MANAGED_KEYS);

        // Revoke only the credentials minted for this site, identified by the
        // name attach gave them. A cache's other site credentials, and any key
        // an operator created by hand, are left alone.
        $siteCredentialName = __('Site: :site', ['site' => $site->name ?: $site->id]);

        foreach ($cache->credentials()->where('name', $siteCredentialName)->get() as $credential) {
            if (! $credential->isRevoked()) {
                $credential->forceFill(['revoked_at' => now()])->save();
            }

            CacheFacade::forget($credential->cacheKey());
        }

        SiteBinding::query()
            ->where('site_id', $site->id)
            ->where('type', 'cache')
            ->where('target_type', 'managed_cache')
            ->where('target_id', $cache->id)
            ->delete();
    }
}
