<?php

declare(strict_types=1);

namespace App\Modules\Cache\Actions;

use App\Models\Organization;
use App\Models\ServiceCredential;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Support\CacheEntitlements;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Create a shared-tier cache and its first credential.
 *
 * Synchronous and always active: the shared tier is a row plus a grant, so
 * there is nothing to provision and no `provisioning` state to sit in. The
 * dedicated tier is the one that waits on a cluster, and it arrives in M3.
 *
 * The plaintext secret is returned once and never again — the same contract as
 * a queue namespace's first credential.
 */
final class CreateManagedCache
{
    public function __construct(private readonly CacheEntitlements $entitlements) {}

    /**
     * @return array{cache: ManagedCache, credential: ServiceCredential, plaintext: string}
     */
    public function handle(Organization $organization, string $name, ?string $userId = null): array
    {
        if (! (bool) config('cache_service.enabled', false)) {
            throw new RuntimeException(__('dply Cache is not enabled on this installation.'));
        }

        $entitlement = $this->entitlements->for($organization);

        $current = ManagedCache::query()
            ->where('organization_id', $organization->id)
            ->count();

        if (! $entitlement->allowsAnother($current)) {
            throw new RuntimeException(__('Your plan does not allow another cache.'));
        }

        $name = $this->uniqueName($organization, $name);

        $cache = ManagedCache::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'tier' => ManagedCache::TIER_SHARED,
            'status' => ManagedCache::STATUS_ACTIVE,
        ]);

        $minted = (new MintCacheCredential)->handle($cache, __('Default credential'), userId: $userId);

        return [
            'cache' => $cache,
            'credential' => $minted['credential'],
            'plaintext' => $minted['plaintext'],
        ];
    }

    /**
     * Names are unique per org because they appear in the URL bar and in
     * attach pickers. Colliding is a rename, not an error: someone creating a
     * second "cache" meant a second cache, not a failed request.
     */
    private function uniqueName(Organization $organization, string $name): string
    {
        $base = Str::slug(trim($name)) ?: 'cache';
        $candidate = $base;
        $suffix = 2;

        while (ManagedCache::query()
            ->where('organization_id', $organization->id)
            ->where('name', $candidate)
            ->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
