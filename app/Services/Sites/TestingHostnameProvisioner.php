<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\DigitalOceanService;
use Illuminate\Support\Str;

class TestingHostnameProvisioner
{
    public function provision(Site $site): ?SiteDomain
    {
        $site->loadMissing(['server', 'domains']);

        if (! $this->isEnabled()) {
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

        $existingHostname = $site->testingHostname();
        if ($existingHostname !== '') {
            $existingDomain = $site->domains->firstWhere('hostname', $existingHostname);
            if ($existingDomain instanceof SiteDomain) {
                return $existingDomain;
            }
        }

        $zone = $this->chooseZone($site);
        $hostname = $this->buildHostname($site, $zone);
        $recordName = $this->relativeRecordName($hostname, $zone);
        $service = new DigitalOceanService((string) config('services.digitalocean.token'));

        try {
            $record = $service->findDomainRecord($zone, 'A', $recordName, $serverIp)
                ?? $service->createDomainRecord($zone, 'A', $recordName, $serverIp);

            $domain = SiteDomain::query()->firstOrCreate([
                'site_id' => $site->id,
                'hostname' => $hostname,
            ], [
                'is_primary' => false,
                'www_redirect' => false,
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
            ]);

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

    public function isEnabled(): bool
    {
        return (bool) config('services.digitalocean.auto_testing_hostname_enabled')
            && trim((string) config('services.digitalocean.token')) !== ''
            && $this->configuredDomains() !== [];
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

    private function storeResult(Site $site, array $payload): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['testing_hostname'] = $payload;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }
}
