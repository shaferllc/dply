<?php

namespace App\Services\Sites;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use App\Modules\Providers\Namecheap\NamecheapDnsService;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Modules\Deploy\Services\DeploymentContractBuilder;
use App\Modules\Deploy\Services\DeploymentRevisionTracker;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use App\Support\Preview\UnifiedPreviewHostname;
use App\Support\TestingDomains;
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

        $disabledReason = $this->disabledReason($site);
        if ($disabledReason !== null) {
            $this->storeResult($site, [
                'status' => 'skipped',
                'reason' => $disabledReason,
            ]);

            return null;
        }

        $serverIp = trim((string) ($site->server->ip_address ?? ''));
        if ($serverIp === '') {
            $this->storeResult($site, [
                'status' => 'skipped',
                'reason' => 'missing_server_ip',
            ]);

            return null;
        }

        // Testing hostnames live on Dply-owned Cloudflare zones
        // (services.cloudflare.vm).
        $routing = $this->resolveTestingProviderForSite($site);
        $dnsProviderKey = $routing['provider'];
        $dnsProvider = $routing['dns_provider'];
        $pool = $routing['pool'];

        $zone = $this->chooseZoneFromPool($site, $pool);
        $hostname = $this->buildHostname($site, $zone);
        $recordName = $this->relativeRecordName($hostname, $zone);

        if ($dnsProviderKey === 'cloudflare') {
            $token = TestingDomains::cloudflareApiTokenForZone($zone);
            if ($token === '') {
                throw new \RuntimeException('Dply has no Cloudflare API token that can see zone ['.$zone.'].');
            }
            $dnsProvider = SiteDnsProviderFactory::forCloudflareAppConfigToken($token);
        }

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
    /**
     * Mint an ADDITIONAL managed preview hostname (non-primary) on a dply-owned
     * zone — the "Add preview URL" action. Same managed DNS provisioning as the
     * primary {@see provision()}, but with a unique random hostname and without
     * demoting the existing primary, so a site can carry several share URLs.
     */
    public function provisionAdditional(Site $site, ?string $label = null): ?SitePreviewDomain
    {
        $site->loadMissing(['server', 'previewDomains', 'organization', 'dnsProviderCredential']);

        if (! $this->isEnabledForSite($site)) {
            return null;
        }

        $serverIp = trim((string) ($site->server->ip_address ?? ''));
        if ($serverIp === '') {
            return null;
        }

        $routing = $this->resolveTestingProviderForSite($site);
        $dnsProviderKey = $routing['provider'];
        $dnsProvider = $routing['dns_provider'];
        $pool = $routing['pool'];

        $zone = $this->chooseZoneFromPool($site, $pool);
        $hostname = $this->buildAdditionalHostname($site, $zone);
        $recordName = $this->relativeRecordName($hostname, $zone);

        if ($dnsProviderKey === 'cloudflare') {
            $token = TestingDomains::cloudflareApiTokenForZone($zone);
            if ($token === '') {
                return null;
            }
            $dnsProvider = SiteDnsProviderFactory::forCloudflareAppConfigToken($token);
        }

        try {
            $record = $dnsProvider->upsertRecord($zone, 'A', $recordName, $serverIp);

            return SitePreviewDomain::query()->create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'label' => $label !== null && trim($label) !== '' ? trim($label) : 'Preview URL',
                'zone' => $zone,
                'record_name' => $recordName,
                'provider_type' => $dnsProviderKey,
                'provider_record_id' => (string) ($record['id'] ?? ''),
                'record_type' => 'A',
                'record_data' => $serverIp,
                'dns_status' => 'ready',
                'ssl_status' => 'none',
                'is_primary' => false,
                'auto_ssl' => true,
                'https_redirect' => true,
                'managed_by_dply' => true,
                'last_dns_checked_at' => now(),
                'meta' => [
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A unique managed hostname for an additional preview URL — the per-site
     * canonical name is deterministic (one per site), so additional ones get a
     * short random suffix and are checked for collisions before use.
     */
    private function buildAdditionalHostname(Site $site, string $zone): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : $site->name);
        $base = trim($base, '-');
        $base = $base !== '' ? $base : 'site';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $suffix = Str::lower(Str::random(6));
            $label = rtrim(Str::limit($base.'-'.$suffix, 63, ''), '-');
            $candidate = $label.'.'.$zone;

            if (! SitePreviewDomain::query()->where('hostname', $candidate)->exists()) {
                return $candidate;
            }
        }

        return rtrim(Str::limit($base.'-'.Str::lower(Str::random(10)), 63, ''), '-').'.'.$zone;
    }

    /**
     * Best-effort removal of a single managed preview domain's provider DNS record
     * (using the row's own stored zone + record id), so removing an added preview
     * URL doesn't orphan its A record. Failures are swallowed — the row removal
     * proceeds regardless, and an orphaned record is harmless beyond clutter.
     */
    public function deleteManagedPreviewRecord(Site $site, SitePreviewDomain $domain): void
    {
        $recordId = trim((string) ($domain->provider_record_id ?? ''));
        $zone = trim((string) ($domain->zone ?? ''));
        if (! (bool) $domain->managed_by_dply || $recordId === '' || $recordId === '0' || $zone === '') {
            return;
        }

        try {
            $routing = $this->resolveTestingProviderForSite($site);
            $routing['dns_provider']->deleteRecord($zone, $recordId);
        } catch (\Throwable $e) {
            // best-effort — leave the row removal to proceed.
        }
    }

    public function provisionForTenant(Site $site, SiteTenantDomain $tenant): bool
    {
        $site->loadMissing(['server', 'organization']);

        if (! $this->isEnabledForSite($site)) {
            $this->storeTenantResult($tenant, ['status' => 'skipped', 'reason' => 'disabled']);

            return false;
        }

        $serverIp = trim((string) ($site->server->ip_address ?? ''));
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeTenantResult(SiteTenantDomain $tenant, array $payload): void
    {
        $meta = is_array($tenant->meta) ? $tenant->meta : [];
        $meta['testing'] = $payload;
        $tenant->forceFill(['meta' => $meta])->save();
        $tenant->setAttribute('meta', $meta);
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

        $strategy = (string) config('services.cloudflare.testing_domain_strategy', 'deterministic');

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
        return $this->disabledReason($site) === null;
    }

    /**
     * WHY testing hostnames are unavailable, or null when they are available.
     *
     * Three separate conditions used to collapse into one 'disabled' reason,
     * so the provisioning failure told the operator to fix whichever one the
     * message happened to name — historically DigitalOcean, on installations
     * with no DigitalOcean at all. Each condition now reports itself.
     */
    public function disabledReason(Site $site): ?string
    {
        if (! $this->hasAvailableToken()) {
            return 'missing_cloudflare_token';
        }

        if ($this->normalizedSiteDnsZone($site) !== null) {
            return null;
        }

        return TestingDomains::vm() !== [] ? null : 'no_zones_configured';
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
        $serverIp = trim((string) ($testingMeta['record_data'] ?? $site->server->ip_address ?? ''));

        $previewRow = $site->previewDomains()->where('hostname', $hostname)->first();
        $providerType = is_string($previewRow?->provider_type) && $previewRow->provider_type !== ''
            ? $previewRow->provider_type
            : ($site->dnsAutomationCredential()->provider ?? 'cloudflare');

        if ($providerType === 'namecheap') {
            $recordId = (string) ($testingMeta['record_id'] ?? '');
            if ($recordId === '' || $recordId === '0') {
                $recordId = (string) ($previewRow->provider_record_id ?? '');
            }
            if ($recordId === '' || ! NamecheapDnsService::isConfigured()) {
                return;
            }
            NamecheapDnsService::fromAppConfig()->deleteDnsRecord($zone, $recordId);
        } elseif ($providerType === 'cloudflare') {
            $recordId = (string) ($testingMeta['record_id'] ?? '');
            if ($recordId === '' || $recordId === '0') {
                $recordId = (string) ($previewRow->provider_record_id ?? '');
            }
            if ($recordId === '') {
                return;
            }
            // Platform token only, matching creation: this record lives in a
            // dply-owned zone, so the customer credential that used to be tried
            // first here could never see it — the delete silently no-op'd and
            // left an orphan A record pointing at a released IP.
            $cloudflareAuth = TestingDomains::cloudflareApiTokenForZone($zone);
            if ($cloudflareAuth === '') {
                return;
            }
            (new CloudflareDnsService($cloudflareAuth))->deleteDnsRecord($zone, $recordId);
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
                $recordId = (string) ($previewRow->provider_record_id ?? '');
            }
            if ($recordId === '') {
                return;
            }
            SiteDnsProviderFactory::forCredential($credential)->deleteRecord($zone, $recordId);
        } elseif ($providerType === 'digitalocean') {
            // LEGACY ONLY. Testing hostnames have not been created through
            // DigitalOcean since the switch to Cloudflare-only routing; this
            // branch exists so records written by the old code are still
            // deletable instead of becoming orphan A records. It is scoped to
            // an explicit 'digitalocean' provider_type rather than acting as
            // the catch-all `else`, which used to fire DigitalOcean API calls
            // for any unrecognised value.
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
    /** @return array<int, string> */
    public function configuredDomains(): array
    {
        // Testing zones are Cloudflare-only. This used to read
        // services.digitalocean.testing_domains and re-normalise the result,
        // but that key no longer exists in config and TestingDomains::vm()
        // already lowercases, trims, de-dupes and returns a list.
        return TestingDomains::vm();
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
     * Public routing summary for the operator credential that controls a site's
     * testing zone — used by the wildcard-certificate issuer to drive certbot
     * DNS-01 hooks against the right provider with the right token. Mirrors the
     * credential resolution in {@see resolveTestingProviderForSite()} and folds
     * in the app-level DigitalOcean token fallback so callers always get a
     * usable token when one is available.
     */
    public function testingDnsRoutingForSite(Site $site): array
    {
        $site->loadMissing(['server', 'organization', 'dnsProviderCredential']);

        $routing = $this->resolveTestingProviderForSite($site);
        $credential = $routing['credential'];

        // Always the platform Cloudflare token: resolveTestingProviderForSite()
        // returns no credential and no other provider.
        $token = TestingDomains::cloudflareApiToken();

        return [
            'provider' => $routing['provider'],
            'credential' => $credential,
            'token' => (trim($token)),
        ];
    }

    /**
     * Testing hostnames mint on dply-owned zones from
     * services.cloudflare.vm.
     *
     * CLOUDFLARE ONLY, PLATFORM TOKEN ONLY. There is no provider choice and no
     * credential lookup left here, by design.
     *
     * This used to walk ['hetzner', 'cloudflare', 'digitalocean'] looking for a
     * ProviderCredential the ORG had connected, "so the record stays inside the
     * operator's existing DNS account". That premise is wrong: the record does
     * not go in the operator's zone, it goes in on-dply.cc, which dply owns. A
     * customer's DigitalOcean or Cloudflare token cannot write it. The result
     * was that connecting any DNS credential silently hijacked testing-hostname
     * creation and failed with "Zone [on-dply.cc] was not found", pointing the
     * blame at an account that was never involved.
     *
     * Namecheap and the DigitalOcean platform token are gone from this path too
     * — the zones are on Cloudflare, so anything else could only ever fail.
     * Existing records created under the old routing keep their stored
     * provider_type and are still deleted through the matching branch in
     * {@see self::delete()}.
     */
    private function resolveTestingProviderForSite(Site $site): array
    {
        $pool = TestingDomains::vm();
        if ($pool === []) {
            throw new \RuntimeException('Dply has no testing-hostname zones configured. Add them to services.cloudflare.vm in config/services.php.');
        }

        $token = TestingDomains::cloudflareApiToken();
        if ($token === '') {
            throw new \RuntimeException(
                'Testing hostnames require CLOUDFLARE_DNS_API_TOKEN. The testing zones ('
                .implode(', ', array_slice($pool, 0, 3)).(count($pool) > 3 ? ', …' : '')
                .') live in dply\'s own Cloudflare account, so only dply\'s platform token can write them. '
                .'Set CLOUDFLARE_DNS_API_TOKEN to a token whose Zone Resources include those zones.'
            );
        }

        return [
            'provider' => 'cloudflare',
            'dns_provider' => SiteDnsProviderFactory::forCloudflareAppConfigToken($token),
            'pool' => $pool,
            'credential' => null,
        ];
    }

    /**
     * Per-provider testing-zone pool from config. Reads the new
     * services.dply.testing_domains.<provider> map.
     *
     * The DigitalOcean branch that folded in a legacy zone list is gone:
     * testing zones are Cloudflare-only, and that merge is what kept the
     * DigitalOcean pool non-empty and therefore selectable.
     *
     * @return list<string>
     */
    public function configuredDomainsForProvider(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $map = config('services.dply.testing_domains', []);
        $list = is_array($map) && is_array($map[$provider] ?? null) ? $map[$provider] : [];

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

        $preferred = TestingDomains::vmApex();
        if ($preferred !== '' && in_array($preferred, $pool, true)) {
            return $preferred;
        }

        $ordered = app(UnifiedPreviewHostname::class)->orderedTestingZones($pool);
        $strategy = (string) config('services.cloudflare.testing_domain_strategy', 'deterministic');

        return match ($strategy) {
            'random' => $ordered[array_rand($ordered)],
            default => $ordered[$this->deterministicIndex($site, count($ordered))],
        };
    }

    private function configuredZoneForHostname(string $hostname): ?string
    {
        return TestingDomains::zoneForHost($hostname);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeResult(Site $site, array $payload): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['testing_hostname'] = $payload;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }

    /**
     * Testing hostnames are Cloudflare-only, so this asks exactly one question.
     *
     * It used to also accept Namecheap, a DigitalOcean token, or the mere
     * EXISTENCE of any org DNS credential — which let provisioning start on a
     * box with no usable platform token and fail later at the DNS write.
     */
    private function hasAvailableToken(): bool
    {
        return TestingDomains::cloudflareIsConfigured();
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
        $routing = $this->resolveTestingProviderForSite($site);
        $credential = $routing['credential'] ?? null;
        if (! $credential instanceof ProviderCredential) {
            // Cloudflare only: a Namecheap or DigitalOcean token present in
            // config cannot write a dply testing zone, so reporting
            // "app_config" for them claimed a capability that does not exist.
            return TestingDomains::cloudflareIsConfigured() ? 'app_config' : 'none';
        }

        if ($site->dns_provider_credential_id && $credential->id === $site->dns_provider_credential_id) {
            return 'site_credential';
        }

        return 'organization_credential';
    }
}
