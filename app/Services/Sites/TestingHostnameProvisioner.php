<?php

namespace App\Services\Sites;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentRevisionTracker;
use App\Services\DigitalOceanService;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Support\Str;

class TestingHostnameProvisioner
{
    public function __construct(
        private readonly DeploymentContractBuilder $contractBuilder,
        private readonly DeploymentRevisionTracker $revisionTracker,
    ) {}

    public function provision(Site $site): ?SitePreviewDomain
    {
        $site->loadMissing(['server', 'previewDomains', 'organization', 'dnsProviderCredential']);

        if (! $this->isEnabledForSite($site)) {
            $this->storeResult($site, [
                'status' => 'skipped',
                'reason' => 'disabled',
            ]);

            return null;
        }

        $serverIp = trim((string) ($site->server?->ip_address ?? ''));
        if ($serverIp === '') {
            $this->storeResult($site, [
                'status' => 'skipped',
                'reason' => 'missing_server_ip',
            ]);

            return null;
        }

        // Testing hostnames live on Dply-managed zones. Pick a pool that
        // matches a DNS provider the org already has connected so the
        // record stays inside the operator's existing DNS account; fall
        // back to the DigitalOcean pool when nothing matches.
        $routing = $this->resolveTestingProviderForSite($site);
        $dnsProviderKey = $routing['provider'];
        $dnsProvider = $routing['dns_provider'];
        $pool = $routing['pool'];

        $zone = $this->chooseZoneFromPool($site, $pool);
        $hostname = $this->buildHostname($site, $zone);
        $recordName = $this->relativeRecordName($hostname, $zone);

        try {
            $record = $dnsProvider->upsertRecord($zone, 'A', $recordName, $serverIp);

            SitePreviewDomain::query()
                ->where('site_id', $site->id)
                ->where('hostname', '!=', $hostname)
                ->update(['is_primary' => false]);

            $domain = SitePreviewDomain::query()->updateOrCreate([
                'site_id' => $site->id,
                'hostname' => $hostname,
            ], [
                'label' => 'Managed preview',
                'zone' => $zone,
                'record_name' => $recordName,
                'provider_type' => $dnsProviderKey,
                'provider_record_id' => (string) ($record['id'] ?? ''),
                'record_type' => 'A',
                'record_data' => $serverIp,
                'dns_status' => 'ready',
                'ssl_status' => 'none',
                'is_primary' => true,
                'auto_ssl' => true,
                'https_redirect' => true,
                'managed_by_dply' => true,
                'last_dns_checked_at' => now(),
                'meta' => [
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]);

            $this->storeResult($site, [
                'status' => 'ready',
                'hostname' => $hostname,
                'zone' => $zone,
                'record_name' => $recordName,
                // Keep the provider's id verbatim — DigitalOcean returns an int,
                // but Hetzner/Cloudflare return a string (Hetzner: "<name>/<TYPE>").
                // Casting to int (the old behaviour) collapsed those to 0, which
                // made the delete path unable to find — and therefore unable to
                // remove — the record when the site was torn down.
                'record_id' => $record['id'] ?? null,
                'record_type' => 'A',
                'record_data' => $serverIp,
                'provisioned_at' => now()->toIso8601String(),
                'credential_source' => $this->credentialSourceForSite($site),
            ]);
            $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'publication');

            return $domain;
        } catch (\Throwable $e) {
            $this->storeResult($site, [
                'status' => 'failed',
                'reason' => 'provider_error',
                'hostname' => $hostname,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_data' => $serverIp,
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]);

            return null;
        }
    }

    /**
     * Provision a managed testing-domain hostname for a single tenant so the app
     * can be reached as that tenant (e.g. acme-worker-1-ab12cd.on-dply.cc) before
     * the customer points their real DNS. Mirrors {@see provision()} but stores
     * the result on the tenant row's meta instead of a SitePreviewDomain. The
     * caller must re-apply the webserver config afterwards so the new hostname
     * lands in the vhost server_name (it's already in {@see Site::webserverHostnames()}).
     *
     * Idempotent: re-running reuses the tenant's existing hostname/zone.
     */
    public function provisionForTenant(Site $site, SiteTenantDomain $tenant): bool
    {
        $site->loadMissing(['server', 'organization']);

        if (! $this->isEnabledForSite($site)) {
            $this->storeTenantResult($tenant, ['status' => 'skipped', 'reason' => 'disabled']);

            return false;
        }

        $serverIp = trim((string) ($site->server?->ip_address ?? ''));
        if ($serverIp === '') {
            $this->storeTenantResult($tenant, ['status' => 'skipped', 'reason' => 'missing_server_ip']);

            return false;
        }

        $routing = $this->resolveTestingProviderForSite($site);
        $dnsProvider = $routing['dns_provider'];

        $existing = $tenant->testingMeta();
        $hostname = $tenant->testingHostname();
        $zone = is_string($existing['zone'] ?? null) && trim((string) $existing['zone']) !== ''
            ? strtolower(trim((string) $existing['zone']))
            : null;
        if ($hostname === null || $zone === null) {
            $zone = $this->chooseZoneFromPool($site, $routing['pool']);
            $hostname = $this->buildTenantHostname($site, $tenant, $zone);
        }
        $recordName = $this->relativeRecordName($hostname, $zone);

        try {
            $record = $dnsProvider->upsertRecord($zone, 'A', $recordName, $serverIp);

            $this->storeTenantResult($tenant, [
                'status' => 'ready',
                'dns_status' => 'ready',
                'hostname' => $hostname,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_id' => (string) ($record['id'] ?? ''),
                'record_type' => 'A',
                'record_data' => $serverIp,
                'provider_type' => $routing['provider'],
                'provisioned_at' => now()->toIso8601String(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->storeTenantResult($tenant, [
                'status' => 'failed',
                'dns_status' => 'failed',
                'hostname' => $hostname,
                'zone' => $zone,
                'record_name' => $recordName,
                'record_data' => $serverIp,
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]);

            return false;
        }
    }

    /**
     * Tear down a tenant's managed testing hostname: delete the DNS record (best
     * effort) and clear the tenant's testing meta. The caller re-applies the
     * webserver config so the hostname drops out of the vhost server_name.
     */
    public function deleteForTenant(Site $site, SiteTenantDomain $tenant): void
    {
        $site->loadMissing(['server', 'organization']);

        $meta = $tenant->testingMeta();
        $zone = strtolower(trim((string) ($meta['zone'] ?? '')));
        $recordId = (string) ($meta['record_id'] ?? '');

        if ($zone !== '' && $recordId !== '') {
            try {
                $this->resolveTestingProviderForSite($site)['dns_provider']->deleteRecord($zone, $recordId);
            } catch (\Throwable) {
                // Best effort — clear the local record regardless so the UI and
                // server_name stay consistent even if the provider call fails.
            }
        }

        $tenantMeta = is_array($tenant->meta) ? $tenant->meta : [];
        unset($tenantMeta['testing']);
        $tenant->forceFill(['meta' => $tenantMeta])->save();
    }

    public function buildTenantHostname(Site $site, SiteTenantDomain $tenant, string $zone): string
    {
        $siteBase = trim(Str::slug($site->slug !== '' ? $site->slug : $site->name), '-');
        $siteBase = $siteBase !== '' ? $siteBase : 'site';

        $tenantSource = (string) ($tenant->tenant_key ?: Str::before((string) $tenant->hostname, '.'));
        $tenantBase = trim(Str::slug($tenantSource), '-');
        $tenantBase = $tenantBase !== '' ? $tenantBase : 'tenant';

        $suffix = Str::lower(substr(sha1((string) ($tenant->id ?: $tenant->hostname)), 0, 6));

        $label = rtrim(Str::limit($tenantBase.'-'.$siteBase.'-'.$suffix, 63, ''), '-');

        return $label.'.'.$zone;
    }

    private function storeTenantResult(SiteTenantDomain $tenant, array $payload): void
    {
        $meta = is_array($tenant->meta) ? $tenant->meta : [];
        $meta['testing'] = $payload;
        $tenant->forceFill(['meta' => $meta])->save();
        $tenant->setAttribute('meta', $meta);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveHostnameAndZone(Site $site): array
    {
        $site->loadMissing(['previewDomains']);
        $customZone = $this->normalizedSiteDnsZone($site);
        $existingHostname = strtolower(trim($site->testingHostname()));

        if ($customZone !== null) {
            if ($existingHostname !== '' && str_ends_with($existingHostname, '.'.$customZone)) {
                return [$existingHostname, $customZone];
            }

            return [$this->buildHostname($site, $customZone), $customZone];
        }

        if ($existingHostname !== '') {
            $existingZone = $this->configuredZoneForHostname($existingHostname);
            if ($existingZone !== null) {
                return [$existingHostname, $existingZone];
            }
        }

        $zone = $this->chooseZone($site);

        return [$this->buildHostname($site, $zone), $zone];
    }

    private function normalizedSiteDnsZone(Site $site): ?string
    {
        $z = strtolower(trim((string) ($site->dns_zone ?? '')));

        return $z !== '' ? $z : null;
    }

    public function chooseZone(Site $site): string
    {
        $domains = $this->configuredDomains();
        if ($domains === []) {
            throw new \RuntimeException('No testing domains are configured.');
        }

        $domains = app(UnifiedPreviewHostname::class)->orderedTestingZones($domains);

        $strategy = (string) config('services.digitalocean.testing_domain_strategy', 'deterministic');

        return match ($strategy) {
            'random' => $domains[array_rand($domains)],
            default => $domains[$this->deterministicIndex($site, count($domains))],
        };
    }

    public function buildHostname(Site $site, string $zone): string
    {
        $hostnames = app(UnifiedPreviewHostname::class);
        if ($hostnames->enabled()) {
            return $hostnames->canonicalHostname($site, $zone);
        }

        return $this->legacyBuildHostname($site, $zone);
    }

    private function legacyBuildHostname(Site $site, string $zone): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : $site->name);
        $base = trim($base, '-');
        $base = $base !== '' ? $base : 'site';

        $suffixSource = $site->id ?: ($site->server_id ?: $site->name);
        $suffix = Str::lower(substr(sha1((string) $suffixSource), 0, 8));
        $label = Str::limit($base.'-'.$suffix, 63, '');
        $label = rtrim($label, '-');

        return $label.'.'.$zone;
    }

    public function isEnabledForSite(Site $site): bool
    {
        if (! (bool) config('services.digitalocean.auto_testing_hostname_enabled')) {
            return false;
        }

        if (! $this->hasAvailableToken()) {
            return false;
        }

        if ($this->normalizedSiteDnsZone($site) !== null) {
            return true;
        }

        // True if any provider pool has at least one zone configured.
        foreach (['digitalocean', 'hetzner', 'cloudflare'] as $providerKey) {
            if ($this->configuredDomainsForProvider($providerKey) !== []) {
                return true;
            }
        }

        return false;
    }

    public function delete(Site $site): void
    {
        $site->loadMissing(['server', 'previewDomains', 'dnsProviderCredential']);

        $testingMeta = is_array($site->meta['testing_hostname'] ?? null) ? $site->meta['testing_hostname'] : [];
        $hostname = strtolower(trim((string) ($testingMeta['hostname'] ?? $site->testingHostname())));
        if ($hostname === '') {
            return;
        }

        $zone = is_string($testingMeta['zone'] ?? null) && $testingMeta['zone'] !== ''
            ? (string) $testingMeta['zone']
            : null;
        if ($zone === null || trim($zone) === '') {
            $zone = $this->normalizedSiteDnsZone($site);
        }
        if ($zone === null || trim($zone) === '') {
            $preview = $site->primaryPreviewDomain();
            $z = is_string($preview?->zone) ? trim($preview->zone) : '';
            $zone = $z !== '' ? strtolower($z) : null;
        }
        if ($zone === null || trim($zone) === '') {
            $zone = $this->configuredZoneForHostname($hostname);
        }
        if ($zone === null || ! $this->hasAvailableToken()) {
            return;
        }

        $recordName = is_string($testingMeta['record_name'] ?? null) && $testingMeta['record_name'] !== ''
            ? (string) $testingMeta['record_name']
            : $this->relativeRecordName($hostname, $zone);
        $serverIp = trim((string) ($testingMeta['record_data'] ?? $site->server?->ip_address ?? ''));

        $previewRow = $site->previewDomains()->where('hostname', $hostname)->first();
        $providerType = is_string($previewRow?->provider_type) && $previewRow->provider_type !== ''
            ? $previewRow->provider_type
            : ($site->dnsAutomationCredential()?->provider ?? 'digitalocean');

        if ($providerType === 'cloudflare') {
            $site->loadMissing('dnsProviderCredential');
            $credential = $site->dnsProviderCredential;
            if ($credential === null || $credential->provider !== 'cloudflare') {
                $credential = ProviderCredential::query()
                    ->where('organization_id', $site->organization_id)
                    ->where('provider', 'cloudflare')
                    ->latest('updated_at')
                    ->first();
            }
            if ($credential === null) {
                return;
            }
            // Prefer the preview row's stored provider_record_id: the meta
            // record_id was historically int-cast to 0 for string-id providers
            // (Hetzner/Cloudflare), so trust it only when it's a non-empty,
            // non-"0" value and otherwise fall back to the row.
            $recordId = (string) ($testingMeta['record_id'] ?? '');
            if ($recordId === '' || $recordId === '0') {
                $recordId = (string) ($previewRow?->provider_record_id ?? '');
            }
            if ($recordId === '') {
                return;
            }
            (new CloudflareDnsService($credential))->deleteDnsRecord($zone, $recordId);
        } elseif (in_array($providerType, ['hetzner', 'linode', 'vultr', 'aws', 'gcp', 'azure'], true)) {
            $credential = $site->dnsAutomationCredential();
            if ($credential === null || $credential->provider !== $providerType) {
                return;
            }
            // Prefer the preview row's stored provider_record_id: the meta
            // record_id was historically int-cast to 0 for string-id providers
            // (Hetzner/Cloudflare), so trust it only when it's a non-empty,
            // non-"0" value and otherwise fall back to the row.
            $recordId = (string) ($testingMeta['record_id'] ?? '');
            if ($recordId === '' || $recordId === '0') {
                $recordId = (string) ($previewRow?->provider_record_id ?? '');
            }
            if ($recordId === '') {
                return;
            }
            SiteDnsProviderFactory::forCredential($credential)->deleteRecord($zone, $recordId);
        } else {
            $service = new DigitalOceanService($this->tokenForSite($site));
            $recordId = (int) ($testingMeta['record_id'] ?? 0);

            if ($recordId <= 0) {
                $record = $service->findDomainRecord($zone, 'A', $recordName, $serverIp !== '' ? $serverIp : null);
                $recordId = (int) ($record['id'] ?? 0);
            }

            if ($recordId > 0) {
                $service->deleteDomainRecord($zone, $recordId);
            }
        }

        $site->previewDomains()
            ->where('hostname', $hostname)
            ->delete();
    }

    /**
     * @return list<string>
     */
    public function configuredDomains(): array
    {
        $domains = config('services.digitalocean.testing_domains', []);

        return collect(is_array($domains) ? $domains : [])
            ->filter(fn (mixed $domain): bool => is_string($domain) && trim($domain) !== '')
            ->map(fn (string $domain): string => strtolower(trim($domain)))
            ->unique()
            ->values()
            ->all();
    }

    private function relativeRecordName(string $hostname, string $zone): string
    {
        return (string) Str::beforeLast($hostname, '.'.$zone);
    }

    private function deterministicIndex(Site $site, int $count): int
    {
        $key = (string) ($site->id ?: ($site->slug !== '' ? $site->slug : $site->name));

        return abs(crc32($key)) % $count;
    }

    /**
     * Decide which DNS provider + zone pool to use for a site's testing
     * hostname. Preference order:
     *   1) The org has a credential for a provider that has a non-empty
     *      configured pool (services.dply.testing_domains.<provider>).
     *      Use that credential + that pool.
     *   2) Otherwise fall back to DigitalOcean — an org-level DO credential
     *      if one is connected, else the app-level services.digitalocean.token.
     *
     * Throws when DO fallback is also unavailable.
     *
     * @return array{provider: string, dns_provider: \App\Services\Sites\Dns\DnsProvider, pool: list<string>, credential: ?ProviderCredential}
     */
    private function resolveTestingProviderForSite(Site $site): array
    {
        $providers = ['hetzner', 'cloudflare', 'digitalocean'];
        if ($site->organization_id !== null) {
            foreach ($providers as $providerKey) {
                $pool = $this->configuredDomainsForProvider($providerKey);
                if ($pool === []) {
                    continue;
                }

                $credential = ProviderCredential::query()
                    ->where('organization_id', $site->organization_id)
                    ->where('provider', $providerKey)
                    ->latest('updated_at')
                    ->first();
                if ($credential === null) {
                    continue;
                }

                return [
                    'provider' => $providerKey,
                    'dns_provider' => SiteDnsProviderFactory::forCredential($credential),
                    'pool' => $pool,
                    'credential' => $credential,
                ];
            }
        }

        // Fallback: DigitalOcean. Use the org's DO credential if present,
        // else the app-level token.
        $doPool = $this->configuredDomainsForProvider('digitalocean');
        if ($doPool === []) {
            throw new \RuntimeException('Dply has no testing-hostname zones configured. Set DPLY_TESTING_DOMAINS (or the per-provider variants) in your environment.');
        }

        $doCredential = $site->organization_id
            ? ProviderCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', 'digitalocean')
                ->latest('updated_at')
                ->first()
            : null;

        if ($doCredential !== null) {
            return [
                'provider' => 'digitalocean',
                'dns_provider' => SiteDnsProviderFactory::forCredential($doCredential),
                'pool' => $doPool,
                'credential' => $doCredential,
            ];
        }

        $doToken = trim((string) config('services.digitalocean.token'));
        if ($doToken === '') {
            throw new \RuntimeException('Dply needs a connected DigitalOcean credential (or services.digitalocean.token) to create testing hostnames.');
        }

        return [
            'provider' => 'digitalocean',
            'dns_provider' => SiteDnsProviderFactory::forDigitalOceanAppConfigToken($doToken),
            'pool' => $doPool,
            'credential' => null,
        ];
    }

    /**
     * Per-provider testing-zone pool from config. Reads the new
     * services.dply.testing_domains.<provider> map, with DigitalOcean
     * folding in the legacy services.digitalocean.testing_domains list
     * so existing setups keep working without env changes.
     *
     * @return list<string>
     */
    public function configuredDomainsForProvider(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $map = config('services.dply.testing_domains', []);
        $list = is_array($map) && is_array($map[$provider] ?? null) ? $map[$provider] : [];

        if ($provider === 'digitalocean') {
            $list = array_merge($list, $this->configuredDomains());
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? strtolower(trim($v)) : '',
            $list,
        ))));
    }

    /**
     * Same selection strategy as {@see chooseZone()} but against an arbitrary
     * zone list — used after the per-provider pool is resolved.
     *
     * @param  list<string>  $pool
     */
    private function chooseZoneFromPool(Site $site, array $pool): string
    {
        if ($pool === []) {
            throw new \RuntimeException('No testing zones configured for the resolved DNS provider.');
        }

        $ordered = app(UnifiedPreviewHostname::class)->orderedTestingZones($pool);
        $strategy = (string) config('services.digitalocean.testing_domain_strategy', 'deterministic');

        return match ($strategy) {
            'random' => $ordered[array_rand($ordered)],
            default => $ordered[$this->deterministicIndex($site, count($ordered))],
        };
    }

    /**
     * Pick a Dply-managed testing zone other than the one that just failed.
     * Used when a custom dns_zone errors out (e.g. not delegated yet) so
     * the operator still ends up with a reachable testing URL.
     */
    private function chooseFallbackTestingZone(Site $site, string $primaryZone): ?string
    {
        $domains = array_values(array_filter($this->configuredDomains(), fn (string $z): bool => $z !== $primaryZone));
        if ($domains === []) {
            return null;
        }
        $ordered = app(UnifiedPreviewHostname::class)->orderedTestingZones($domains);

        return $ordered[$this->deterministicIndex($site, count($ordered))];
    }

    /**
     * Default-token DigitalOcean DNS provider for fallback record creation
     * when the operator's chosen DNS provider can't fulfil the upsert.
     * Returns null when no app-level token is configured — in that case we
     * let the original exception propagate so the caller still surfaces it.
     */
    private function fallbackDigitalOceanProvider(): ?\App\Services\Sites\Dns\DnsProvider
    {
        $token = trim((string) config('services.digitalocean.token'));
        if ($token === '') {
            return null;
        }

        return SiteDnsProviderFactory::forDigitalOceanAppConfigToken($token);
    }

    private function configuredZoneForHostname(string $hostname): ?string
    {
        foreach ($this->configuredDomains() as $domain) {
            if ($hostname === $domain || str_ends_with($hostname, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    private function storeResult(Site $site, array $payload): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['testing_hostname'] = $payload;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }

    private function hasAvailableToken(): bool
    {
        if (trim((string) config('services.digitalocean.token')) !== '') {
            return true;
        }

        return ProviderCredential::query()
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->whereNotNull('organization_id')
            ->exists();
    }

    /**
     * DigitalOcean DNS delete path (preview rows created with app-level DO token use DO API).
     */
    private function tokenForSite(Site $site): string
    {
        $credential = $site->dnsAutomationCredential();
        if ($credential !== null && $credential->provider === 'digitalocean') {
            $token = $credential->getApiToken();
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        $token = trim((string) config('services.digitalocean.token'));
        if ($token === '') {
            throw new \RuntimeException('DigitalOcean preview DNS requires an organization credential or app-level token.');
        }

        return $token;
    }

    private function credentialSourceForSite(Site $site): string
    {
        $credential = $site->dnsAutomationCredential();
        if ($credential === null) {
            return trim((string) config('services.digitalocean.token')) !== '' ? 'app_config' : 'none';
        }

        if ($site->dns_provider_credential_id && $credential->id === $site->dns_provider_credential_id) {
            return 'site_credential';
        }

        return 'organization_credential';
    }
}
