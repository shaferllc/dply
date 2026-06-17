<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\ExternalSecretStore;
use App\Models\Site;
use App\Models\SiteSecretResidency;
use Illuminate\Support\Collection;

/**
 * Builds the manifest the on-box resolver shim (dply-resolve-secrets.sh) reads
 * to materialize a site's on-box external secrets ON THE SERVER, so dply never
 * fetches the values itself (Tier 3+ end-to-end ZK).
 *
 * The manifest carries the STORE access config (e.g. a Vault token) but NEVER a
 * secret value — dply may broker access to the store, but the secret material
 * itself flows store -> box only. The manifest is sensitive (it can hold a store
 * token) so it is staged 0600 and the shim deletes it after resolving.
 */
class OnBoxSecretManifestBuilder
{
    /** The env var name the shim rewrites in place, marked by an on-box directive. */
    public const DIRECTIVE_PREFIX = '${dply:onbox:';

    public static function directiveFor(string $residencyId): string
    {
        return self::DIRECTIVE_PREFIX.$residencyId.'}';
    }

    /**
     * The site's external residencies whose store resolves on-box.
     *
     * @return Collection<int, SiteSecretResidency>
     */
    public function onBoxResidencies(Site $site): Collection
    {
        $external = $site->secretResidencies()
            ->where('mode', SiteSecretResidency::MODE_EXTERNAL)
            ->whereNotNull('store_id')
            ->get();

        if ($external->isEmpty()) {
            return collect();
        }

        $stores = ExternalSecretStore::query()
            ->whereIn('id', $external->pluck('store_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $external->filter(fn (SiteSecretResidency $r): bool => (bool) $stores->get($r->store_id)?->resolvesOnBox())
            ->values();
    }

    public function hasOnBoxSecrets(Site $site): bool
    {
        return $this->onBoxResidencies($site)->isNotEmpty();
    }

    /**
     * The manifest array (json-encodable) for the shim.
     *
     * @return array{version: int, secrets: array<int, array{env_key: string, directive: string, driver: string, reference: string, config: array<string, mixed>}>}
     */
    /** @return array<string, mixed> */
    public function buildFor(Site $site): array
    {
        $residencies = $this->onBoxResidencies($site);
        $stores = ExternalSecretStore::query()
            ->whereIn('id', $residencies->pluck('store_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $secrets = $residencies->map(function (SiteSecretResidency $r) use ($stores): array {
            $store = $stores->get($r->store_id);

            return [
                'env_key' => $r->key,
                'directive' => self::directiveFor($r->id),
                'driver' => (string) $store?->driver,
                'reference' => (string) $r->reference,
                'config' => (array) ($store->config ?? []),
            ];
        })->all();

        return ['version' => 1, 'secrets' => $secrets];
    }
}
