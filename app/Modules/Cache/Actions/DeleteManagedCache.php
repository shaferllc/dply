<?php

declare(strict_types=1);

namespace App\Modules\Cache\Actions;

use App\Models\ServiceCredential;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Services\PostgresCacheStore;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\DB;

/**
 * Tear a cache down: revoke its credentials, drop its items, detach its sites,
 * delete the row.
 *
 * Credentials are revoked rather than deleted, and revoked BEFORE the items go.
 * A key that outlives its grant would authenticate and then resolve nothing —
 * which is the correct outcome, but leaves a live secret in a customer's `.env`
 * with no audit trail of when it stopped working.
 *
 * A credential granted on other resources as well is not deleted, only stripped
 * of this grant: it is one key serving several services, and revoking the whole
 * thing would take a queue down with a cache.
 */
final class DeleteManagedCache
{
    public function __construct(private readonly PostgresCacheStore $store) {}

    /**
     * @return array{items: int, credentials: int, sites: int, cluster: ?string}
     */
    public function handle(ManagedCache $cache): array
    {
        $credentials = $cache->credentials()->get();

        foreach ($credentials as $credential) {
            $grants = $credential->grants ?? [];
            unset($grants[ServiceCredential::grantKey(ServiceCredential::SERVICE_CACHE, $cache->id)]);

            if ($grants === []) {
                // Nothing left for this key to do.
                $credential->forceFill(['grants' => [], 'revoked_at' => now()])->save();
            } else {
                $credential->forceFill(['grants' => $grants])->save();
            }

            CacheFacade::forget($credential->cacheKey());
        }

        $items = $this->store->flush($cache);

        $sites = DB::table('cache_site')->where('cache_id', $cache->id)->delete();

        /*
         * A dedicated cache's CLUSTER is deliberately left running.
         *
         * Deleting it is destructive, irreversible, and owned by the Cloud
         * surface that created it — the same place its backups, resizes and
         * billing live. Tearing down a customer's Redis as a side effect of
         * removing a product-page row is not a decision this action should be
         * making on their behalf. The caller surfaces the cluster so it can be
         * dealt with deliberately.
         */
        $cluster = $cache->isShared() ? null : $cache->cloud_database_id;

        $cache->delete();

        return [
            'items' => $items,
            'credentials' => $credentials->count(),
            'sites' => $sites,
            'cluster' => $cluster,
        ];
    }
}
