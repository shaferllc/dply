<?php

declare(strict_types=1);

namespace App\Modules\Cache\Actions;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Support\CacheEntitlements;
use App\Modules\Cloud\Actions\CreateCloudDatabase;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Create a dedicated cache — a real Redis/Valkey cluster.
 *
 * Owns nothing itself. The cluster is a {@see CloudDatabase} created through
 * the Cloud module's own action, so every backend (DigitalOcean, Vultr,
 * Upstash, …), the provisioning poll, the resize path, and the billing hook in
 * `CloudResourceCostCalculator` are reused rather than reimplemented. This
 * action's whole job is to create the cluster and put a `ManagedCache` face on
 * it. See docs/adr/dply-cache.md, decision 3.
 *
 * Unlike the shared tier, this is asynchronous: the row lands in
 * `provisioning` and becomes active when the cluster does — which
 * {@see ManagedCache::isActive()} reads from the cluster rather than mirroring.
 *
 * No credential is minted. A dedicated cache is dialled directly over RESP with
 * the cluster's own password; there is no dply endpoint in front of it and so
 * nothing for a `ServiceCredential` to authorise.
 */
final class CreateDedicatedCache
{
    public function __construct(
        private readonly CacheEntitlements $entitlements,
        private readonly CreateCloudDatabase $createDatabase,
    ) {}

    /**
     * @param  array{name?: string, size?: string, region?: string, version?: string}  $payload
     */
    public function handle(Organization $organization, array $payload): ManagedCache
    {
        if (! (bool) config('cache_service.enabled', false)) {
            throw new RuntimeException(__('dply Cache is not enabled on this installation.'));
        }

        $entitlement = $this->entitlements->for($organization);
        $current = ManagedCache::query()->where('organization_id', $organization->id)->count();

        if (! $entitlement->allowsAnother($current)) {
            throw new RuntimeException(__('Your plan does not allow another cache.'));
        }

        $name = $this->uniqueName($organization, (string) ($payload['name'] ?? 'cache'));

        $database = $this->createDatabase->handle($organization, [
            'name' => 'cache-'.$name,
            'engine' => CloudDatabase::ENGINE_REDIS,
            'version' => (string) ($payload['version'] ?? ''),
            'size' => (string) ($payload['size'] ?? 'small'),
            'region' => (string) ($payload['region'] ?? ''),
        ]);

        return ManagedCache::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'tier' => ManagedCache::TIER_DEDICATED,
            // Mirrors the cluster's initial state. Not maintained afterwards —
            // isActive() reads the cluster, so this column is only ever a
            // starting value for a dedicated cache.
            'status' => ManagedCache::STATUS_PROVISIONING,
            'cloud_database_id' => $database->id,
        ]);
    }

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
