<?php

use App\Models\CloudDatabase;
use App\Models\Site;
use App\Modules\Cache\Models\ManagedCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Promote the per-function Valkey clusters out of `Site.meta`.
 *
 * `ProvisionServerlessCacheJob` stored a whole managed cluster as a JSON blob
 * on the site — `{size, status, cluster_id, host, port}` — which no index
 * lists, no policy authorises, and no billing observer sees. That blob is the
 * invisible-per-site-default the ADR rejected for the shared tier, and this is
 * where it gets paid off (docs/adr/dply-cache.md, decision 10).
 *
 * Each blob becomes a real `cloud_databases` row plus a dedicated
 * `managed_caches` row pointing at it. Nothing is provisioned, nothing is
 * restarted, and no provider call is made — the cluster keeps running and only
 * its representation changes.
 *
 * ## The price change, and why these are stamped
 *
 * `CloudResourceCostCalculator` bills managed databases. These clusters were
 * created through the Serverless cache panel, which billed nothing — the UI
 * called them "billed by DigitalOcean" and dply charged no markup. Materialising
 * them as `cloud_databases` rows therefore risks turning a refactor into a bill
 * the customer never agreed to.
 *
 * `managed_caches.grandfathered_at` is stamped so the calculator keeps
 * excluding them. `managed-services-tier.md` decision 7 requires notifying a
 * customer when a resource changes billability, but that rule was written for a
 * flip caused by a CUSTOMER action (converting a site). Here the customer did
 * nothing, so the answer is not to notify — it is not to charge.
 *
 * Connection secrets are NOT carried over: the blob only ever held host and
 * port, never the password, so the row is created without a connection block
 * and the existing provider poll fills it in on its next pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasTable('managed_caches')) {
            return;
        }

        Site::query()
            ->whereNotNull('meta')
            ->orderBy('id')
            ->chunkById(100, function ($sites): void {
                foreach ($sites as $site) {
                    $this->fold($site);
                }
            });
    }

    private function fold(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $cache = is_array($serverless['cache'] ?? null) ? $serverless['cache'] : [];

        $clusterId = trim((string) ($cache['cluster_id'] ?? ''));

        // No cluster means nothing was ever provisioned — an errored or
        // abandoned attempt. Dropping the blob is the whole point.
        if ($clusterId === '') {
            $this->clearBlob($site, $meta, $serverless);

            return;
        }

        // Idempotent: a re-run must not mint a second row for one cluster.
        $existing = CloudDatabase::query()->where('backend_id', $clusterId)->first();

        $database = $existing ?? CloudDatabase::query()->create([
            'organization_id' => $site->organization_id,
            'name' => 'cache-'.(Str::slug((string) $site->slug) ?: 'fn'),
            'engine' => CloudDatabase::ENGINE_REDIS,
            'version' => '8',
            'size' => (string) ($cache['size'] ?? 'db-s-1vcpu-1gb'),
            'region' => (string) ($site->server?->region ?? ''),
            'backend' => CloudDatabase::BACKEND_DIGITALOCEAN,
            'backend_id' => $clusterId,
            'provider_credential_id' => $site->server?->provider_credential_id,
            'status' => ($cache['status'] ?? '') === 'online'
                ? CloudDatabase::STATUS_ACTIVE
                : CloudDatabase::STATUS_PROVISIONING,
            // Deliberately empty. The blob never held the password, so an
            // invented connection block would be a lie the deploy path would
            // then act on.
            'connection' => [],
            'meta' => ['folded_from' => 'serverless_meta', 'site_id' => $site->id],
        ]);

        if (! ManagedCache::query()->where('cloud_database_id', $database->id)->exists()) {
            ManagedCache::query()->create([
                'organization_id' => $site->organization_id,
                'name' => $this->uniqueName((string) $site->organization_id, (string) $site->slug),
                'tier' => ManagedCache::TIER_DEDICATED,
                'status' => ManagedCache::STATUS_ACTIVE,
                'cloud_database_id' => $database->id,
                // Free when it was provisioned; a refactor is not a reason to
                // start charging for it.
                'grandfathered_at' => now(),
                'meta' => ['folded_from' => 'serverless_meta', 'site_id' => $site->id],
            ]);
        }

        // The function keeps its REDIS_* env untouched — it is still dialling
        // the same cluster. Only the blob goes.
        $this->clearBlob($site, $meta, $serverless);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $serverless
     */
    private function clearBlob(Site $site, array $meta, array $serverless): void
    {
        if (! array_key_exists('cache', $serverless)) {
            return;
        }

        unset($serverless['cache']);
        $meta['serverless'] = $serverless;

        $site->forceFill(['meta' => $meta])->saveQuietly();
    }

    private function uniqueName(string $organizationId, string $slug): string
    {
        $base = Str::slug($slug) ?: 'cache';
        $candidate = $base;
        $suffix = 2;

        while (ManagedCache::query()
            ->where('organization_id', $organizationId)
            ->where('name', $candidate)
            ->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Irreversible by design.
     *
     * Rebuilding the blob would mean writing a cluster id back into a column
     * the code no longer reads, next to a `managed_caches` row that still
     * points at the same cluster — two owners for one resource, which is the
     * condition this migration exists to end. Rolling back means dropping the
     * cache rows, which `down()` on the schema migrations already does.
     */
    public function down(): void
    {
        DB::table('managed_caches')->whereNotNull('grandfathered_at')->delete();
    }
};
