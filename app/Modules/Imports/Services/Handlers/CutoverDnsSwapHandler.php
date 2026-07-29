<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Cloud\Services\AzureDnsService;
use App\Modules\Cloud\Cloudflare\CloudflareDnsService;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Cloud\Services\GcpDnsService;
use App\Modules\Cloud\Services\HetznerService;
use App\Modules\Imports\Services\StepHandler;
use App\Modules\Imports\Services\WaitForTargetServerException;
use App\Modules\Cloud\Services\LinodeService;
use App\Modules\Cloud\Services\Route53Service;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use App\Modules\Cloud\Services\VultrService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cutover step #3: swap DNS for the site's domain to the dply server's IP.
 * Automated when the org has a DNS-capable ProviderCredential covering the
 * domain's zone (Q9b 'clean' or 'bridged'); falls back to instructions in
 * result_data (the progress page surfaces them) when no DNS automation.
 *
 * The actual DNS record mutation is delegated to dply's existing DNS adapters
 * (DigitalOceanService::updateDomainRecord, CloudflareDnsService, etc.) so
 * the per-provider quirks live in one place.
 */
class CutoverDnsSwapHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_CUTOVER_DNS_SWAP;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('cutover_dns_swap requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('cutover_dns_swap requires a target_site_id.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration missing.');
        }
        $target = Server::find($migration->target_server_id);
        if ($target === null) {
            throw new RuntimeException('Target dply server missing.');
        }
        if ($target->ip_address === null || $target->ip_address === '') {
            throw new WaitForTargetServerException('Target server has no IP yet; DNS swap deferred.');
        }
        $site = Site::find($child->target_site_id);
        if ($site === null) {
            throw new RuntimeException('Target dply Site missing.');
        }

        $domain = $child->domain;
        $orgId = (string) ($target->organization_id ?? '');

        $dnsCredential = $this->resolveDnsCredentialForDomain($orgId, $domain);
        if ($dnsCredential === null) {
            // Q9b fallback: no automation, surface instructions.
            $step->status = ImportMigrationStep::STATUS_SKIPPED;
            $step->result_data = [
                'strategy' => 'instructions',
                'domain' => $domain,
                'records' => [
                    ['type' => 'A', 'name' => '@', 'value' => $target->ip_address],
                    ['type' => 'A', 'name' => 'www', 'value' => $target->ip_address],
                ],
                'note' => 'No DNS automation connected. Update your A records manually, then click Confirm cutover.',
            ];
            $step->save();

            return;
        }

        $result = $this->swapViaAdapter($dnsCredential, $domain, $target->ip_address);
        $step->result_data = array_merge(['strategy' => 'automated', 'credential' => $dnsCredential->provider], $result);
        $step->save();
    }

    /**
     * Probe each DNS-capable credential in the org and return the first whose
     * account hosts the zone for the domain. dply's DNS adapters expose
     * different zone-lookup shapes (CloudflareDnsService::findZoneId,
     * DigitalOceanService::fetchDomain) — we try each in turn.
     */
    protected function resolveDnsCredentialForDomain(string $orgId, string $domain): ?ProviderCredential
    {
        $zone = $this->zoneNameFor($domain);
        $candidates = ProviderCredential::query()
            ->where('organization_id', $orgId)
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->orderBy('created_at')
            ->get();

        foreach ($candidates as $credential) {
            try {
                if ($this->credentialHostsZone($credential, $zone)) {
                    return $credential;
                }
            } catch (\Throwable $e) {
                // Treat as not-a-match; move on to the next.
                Log::info('DNS credential zone probe failed', [
                    'credential' => $credential->id,
                    'provider' => $credential->provider,
                    'zone' => $zone,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    protected function credentialHostsZone(ProviderCredential $credential, string $zone): bool
    {
        return match ($credential->provider) {
            'digitalocean' => (new DigitalOceanService($credential->getApiToken() ?? ''))
                ->domainExistsInAccount($zone),
            'hetzner' => (new HetznerService($credential))->zoneExists($zone),
            'linode' => (new LinodeService($credential))->domainExists($zone),
            'vultr' => (new VultrService($credential))->domainExists($zone),
            'aws' => (new Route53Service($credential))->hostedZoneExists($zone),
            'gcp' => (new GcpDnsService($credential))->zoneExists($zone),
            'azure' => (new AzureDnsService($credential))->zoneExists($zone),
            'cloudflare' => (new CloudflareDnsService($credential))->zoneExists($zone),
            default => false,
        };
    }

    /**
     * Best-effort apex-zone extraction. For app.example.com → example.com; for
     * sub.tenant.example.co.uk → example.co.uk via a small public-suffix-ish
     * heuristic. A perfect implementation would use the PSL; for v1 we take
     * the last two labels except when the TLD is a known second-level
     * country suffix (co.uk, com.au, etc.).
     */
    protected function zoneNameFor(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $parts = explode('.', $domain);
        if (count($parts) <= 2) {
            return $domain;
        }
        $multiLevelTlds = ['co.uk', 'com.au', 'co.jp', 'com.br', 'co.za', 'ac.uk', 'gov.uk'];
        $tail2 = implode('.', array_slice($parts, -2));
        $tail3 = implode('.', array_slice($parts, -3));
        if (in_array($tail2, $multiLevelTlds, true)) {
            return $tail3;
        }

        return $tail2;
    }

    /**
     * @return array<string, mixed>
     */
    protected function swapViaAdapter(ProviderCredential $credential, string $domain, string $newIp): array
    {
        $zone = $this->zoneNameFor($domain);
        $relative = $this->relativeRecordName($domain, $zone);

        return match ($credential->provider) {
            'digitalocean' => $this->swapViaDigitalOcean($credential, $domain, $zone, $relative, $newIp),
            'hetzner', 'linode', 'vultr', 'aws', 'gcp', 'azure' => $this->swapViaDnsProvider($credential, $zone, $relative, $newIp),
            'cloudflare' => $this->swapViaCloudflare($credential, $domain, $zone, $relative, $newIp),
            default => [
                'strategy' => 'instructions',
                'reason' => 'no_adapter_for_provider:'.$credential->provider,
                'records' => [['type' => 'A', 'name' => $relative, 'value' => $newIp]],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function swapViaDnsProvider(ProviderCredential $credential, string $zone, string $relative, string $newIp): array
    {
        $record = SiteDnsProviderFactory::forCredential($credential)->upsertRecord($zone, 'A', $relative, $newIp);

        return [
            'zone' => $zone,
            'record' => $relative,
            'record_id' => (string) ($record['id'] ?? ''),
            'new_ip' => $newIp,
            'attempted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function swapViaDigitalOcean(ProviderCredential $credential, string $domain, string $zone, string $relative, string $newIp): array
    {
        $token = $credential->getApiToken() ?? '';
        $service = new DigitalOceanService($token);
        $created = $service->createDomainRecord($zone, 'A', $relative, $newIp, ttl: 60);
        $recordId = is_array($created) ? (int) ($created['id'] ?? 0) : 0;

        return [
            'zone' => $zone,
            'record' => $relative,
            'record_id' => $recordId,
            'new_ip' => $newIp,
            'attempted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function swapViaCloudflare(ProviderCredential $credential, string $domain, string $zone, string $relative, string $newIp): array
    {
        $service = new CloudflareDnsService($credential);
        $result = $service->upsertARecord($zone, $relative, $newIp);
        $recordId = is_array($result) ? (string) ($result['id'] ?? '') : '';

        return [
            'zone' => $zone,
            'record' => $relative,
            'record_id' => $recordId,
            'new_ip' => $newIp,
            'attempted_at' => now()->toIso8601String(),
        ];
    }

    protected function relativeRecordName(string $domain, string $zone): string
    {
        $domain = strtolower(trim($domain));
        $zone = strtolower(trim($zone));
        if ($domain === $zone) {
            return '@';
        }
        if (str_ends_with($domain, '.'.$zone)) {
            return mb_substr($domain, 0, -1 * (strlen($zone) + 1));
        }

        return $domain;
    }
}
