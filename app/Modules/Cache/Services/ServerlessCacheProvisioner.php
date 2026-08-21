<?php

declare(strict_types=1);

namespace App\Modules\Cache\Services;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Cache\Actions\AttachCacheToSite;
use App\Modules\Cache\Actions\CreateManagedCache;
use App\Modules\Cache\Models\CacheSite;
use App\Modules\Cache\Models\ManagedCache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Give a serverless function a shared cache on deploy, if it has none.
 *
 * This is the zero-config half of the product. A function's default cache store
 * is per-invocation, so `ShouldBeUnique`, `WithoutOverlapping` and
 * `RateLimited` silently do nothing — the defect
 * `ServerlessQueueDoctorCommand` has been reporting all along. Making the fix
 * automatic is the point: a customer should not have to know the failure mode
 * exists in order to not have it.
 *
 * ## What it will not do
 *
 * It only wires a function whose cache store is *provably broken* — unset,
 * `array`, or `file`, all of which are per-container on a function. An operator
 * who pointed `CACHE_STORE` at their own Redis, or at a dedicated dply cache,
 * has made a choice, and silently repointing it would be a worse failure than
 * the one being fixed. Same rule `ProvisionServerlessCacheJob` applied to the
 * queue backend before it was retired.
 *
 * Failures are logged and swallowed. A cache is an improvement to a deploy, not
 * a precondition for one — taking a deploy down because a cache could not be
 * created would trade a silent bug for a loud outage.
 */
final class ServerlessCacheProvisioner
{
    /** Stores that are per-container on a function, and therefore not caches. */
    private const BROKEN_STORES = ['', 'array', 'file', 'null'];

    public function __construct(
        private readonly CreateManagedCache $create,
        private readonly AttachCacheToSite $attach,
    ) {}

    /**
     * @param  array<string, string>  $env  the function's managed environment
     * @return array<string, string>  keys to merge, empty when nothing to do
     */
    public function wire(Site $site, array $env): array
    {
        if (! (bool) config('cache_service.enabled', false)) {
            return [];
        }

        if (! $this->shouldWire($site, $env)) {
            return [];
        }

        $organization = $site->organization;

        if (! $organization instanceof Organization) {
            return [];
        }

        try {
            $cache = $this->cacheFor($organization);

            if (! $cache instanceof ManagedCache) {
                return [];
            }

            return $this->attach->handle($cache, $site);
        } catch (Throwable $e) {
            // Deliberately non-fatal — see the class docblock.
            Log::warning('serverless.cache.autowire_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, string>  $env
     */
    private function shouldWire(Site $site, array $env): bool
    {
        // Already has one. Re-attaching every deploy would rotate the
        // credential each time and churn the customer's environment for
        // nothing.
        if (CacheSite::query()->where('site_id', $site->id)->exists()) {
            return false;
        }

        $store = strtolower(trim((string) ($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? '')));

        return in_array($store, self::BROKEN_STORES, true);
    }

    /**
     * The org's shared cache, created on first use.
     *
     * Reused across every function in the org rather than one per site: the
     * tenancy boundary that matters is the key space, and `CACHE_PREFIX` gives
     * each site its own without a cluster each. It also keeps a ten-function
     * org from silently consuming ten of its plan's cache allowance.
     */
    private function cacheFor(Organization $organization): ?ManagedCache
    {
        $existing = ManagedCache::query()
            ->where('organization_id', $organization->id)
            ->where('tier', ManagedCache::TIER_SHARED)
            ->where('status', ManagedCache::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->first();

        if ($existing instanceof ManagedCache) {
            return $existing;
        }

        return $this->create->handle($organization, 'default')['cache'];
    }
}
