<?php

namespace App\Services\Sites;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentRevisionTracker;
use App\Services\DigitalOceanService;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
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

        [$hostname, $zone] = $this->resolveHostnameAndZone($site);
        $recordName = $this->relativeRecordName($hostname, $zone);

        $credential = $site->dnsAutomationCredential();
        if ($credential !== null) {
            $dnsProvider = SiteDnsProviderFactory::forCredential($credential);
            $dnsProviderKey = $credential->provider;
        } else {
            $fallbackToken = trim((string) config('services.digitalocean.token'));
            if ($fallbackToken === '') {
                throw new \RuntimeException('DigitalOcean preview DNS requires an organization credential or app-level token.');
            }
            $dnsProvider = SiteDnsProviderFactory::forDigitalOceanAppConfigToken($fallbackToken);
            $dnsProviderKey = 'digitalocean';
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
                'record_id' => (int) ($record['id'] ?? 0),
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

        $strategy = (string) config('services.digitalocean.testing_domain_strategy', 'deterministic');

        return match ($strategy) {
            'random' => $domains[array_rand($domains)],
            default => $domains[$this->deterministicIndex($site, count($domains))],
        };
    }

    public function buildHostname(Site $site, string $zone): string
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

        return $this->configuredDomains() !== [];
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
            $recordId = (string) ($testingMeta['record_id'] ?? $previewRow?->provider_record_id ?? '');
            if ($recordId === '') {
                return;
            }
            (new CloudflareDnsService($credential))->deleteDnsRecord($zone, $recordId);
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
