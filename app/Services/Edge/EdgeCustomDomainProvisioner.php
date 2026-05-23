<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Http\Middleware\ResolveEdgeCustomDomain;
use App\Models\EdgeDeployment;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareDnsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Provision DNS for custom hostnames on Edge sites — manual CNAME verification
 * or optional auto-provision via org Cloudflare DNS credentials.
 */
final class EdgeCustomDomainProvisioner
{
    public function __construct(
        private readonly EdgeHostMapPublisher $hostMapPublisher,
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function provision(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        $edgeHost = $site->edgeHostname();
        if ($edgeHost === '') {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => '',
                'error' => __('No Edge hostname configured yet — complete the first deploy first.'),
            ]);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => $edgeHost,
                'error' => __('No active deployment — deploy the site before attaching a custom domain.'),
            ]);
        }

        $credential = $this->findCloudflareCredentialForZone($site, $hostname);
        if ($credential === null) {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => $edgeHost,
                'attached_at' => now()->toIso8601String(),
                'error' => null,
            ]);
        }

        $zone = $this->findOwnedCloudflareZone($credential, $hostname);
        if ($zone === null) {
            return $this->updateEntry($site, $hostname, [
                'mode' => 'manual',
                'dns_status' => 'pending',
                'cname_target' => $edgeHost,
                'attached_at' => now()->toIso8601String(),
                'error' => null,
            ]);
        }

        $recordName = (string) Str::beforeLast($hostname, '.'.$zone);
        if ($recordName === '' || $recordName === $hostname) {
            $recordName = '@';
        }

        try {
            $dns = new CloudflareDnsService($credential);
            $dns->upsertCnameRecord($zone, $recordName, $edgeHost);

            $entry = $this->updateEntry($site, $hostname, [
                'mode' => 'auto',
                'dns_status' => 'ready',
                'cname_target' => $edgeHost,
                'zone' => $zone,
                'record_name' => $recordName,
                'attached_at' => now()->toIso8601String(),
                'verified_at' => now()->toIso8601String(),
                'error' => null,
            ]);

            $this->publishReadyHostname($site->fresh(), $hostname);

            return $entry;
        } catch (Throwable $e) {
            Log::warning('Edge custom-domain auto provisioning failed.', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);

            return $this->updateEntry($site, $hostname, [
                'mode' => 'auto',
                'dns_status' => 'failed',
                'cname_target' => $edgeHost,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        $edgeHost = $site->edgeHostname();
        if ($edgeHost === '') {
            return $this->updateEntry($site, $hostname, [
                'dns_status' => 'pending',
                'error' => __('No Edge hostname configured yet — complete the first deploy first.'),
            ]);
        }

        $records = @dns_get_record($hostname, DNS_CNAME | DNS_A);
        if (! is_array($records) || $records === []) {
            return $this->updateEntry($site, $hostname, [
                'dns_status' => 'failed',
                'cname_target' => $edgeHost,
                'error' => __('No DNS records found for :hostname. Publish the CNAME and wait for propagation.', ['hostname' => $hostname]),
            ]);
        }

        $resolved = [];
        foreach ($records as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            if ($type === 'CNAME') {
                $resolved[] = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            } elseif ($type === 'A') {
                $resolved[] = (string) ($record['ip'] ?? '');
            }
        }
        $resolved = array_filter($resolved);
        $expected = strtolower(rtrim($edgeHost, '.'));
        $matches = in_array($expected, $resolved, true);

        $entry = $this->updateEntry($site, $hostname, [
            'dns_status' => $matches ? 'ready' : 'failed',
            'cname_target' => $edgeHost,
            'verified_at' => now()->toIso8601String(),
            'error' => $matches
                ? null
                : __('Hostname resolves to :actual, expected :expected.', [
                    'actual' => implode(', ', $resolved),
                    'expected' => $expected,
                ]),
        ]);

        if ($matches) {
            $this->publishReadyHostname($site->fresh(), $hostname);
        }

        return $entry;
    }

    public function remove(Site $site, string $hostname): void
    {
        $hostname = strtolower(trim($hostname));
        $meta = $site->edgeMeta();
        $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        $removed = $domains[$hostname] ?? null;
        unset($domains[$hostname]);

        $routing['custom_domains'] = $domains;
        $meta['routing'] = $routing;
        $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

        try {
            $this->hostMapPublisher->unpublishHostname($site, $hostname, $this->contextResolver->forSite($site));
        } catch (Throwable $e) {
            Log::info('Edge custom-domain KV cleanup failed (non-fatal).', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);
        }

        ResolveEdgeCustomDomain::invalidateHostMap();

        if (is_array($removed) && ($removed['mode'] ?? null) === 'auto') {
            $this->removeAutoDnsRecord($site, $hostname, $removed);
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function updateEntry(Site $site, string $hostname, array $patch): array
    {
        $meta = $site->edgeMeta();
        $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        $existing = is_array($domains[$hostname] ?? null) ? $domains[$hostname] : [];
        $domains[$hostname] = array_merge($existing, ['hostname' => $hostname], $patch);

        $routing['custom_domains'] = $domains;
        $meta['routing'] = $routing;
        $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

        ResolveEdgeCustomDomain::invalidateHostMap();

        return $domains[$hostname];
    }

    private function publishReadyHostname(Site $site, string $hostname): void
    {
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            throw new RuntimeException('No active deployment to attach domain to.');
        }

        $deployment = EdgeDeployment::query()->findOrFail($activeId);
        $context = $this->contextResolver->forSite($site);
        $this->hostMapPublisher->publishHostname($site, $deployment, $hostname, $context);
        ResolveEdgeCustomDomain::invalidateHostMap();
    }

    private function findCloudflareCredentialForZone(Site $site, string $hostname): ?ProviderCredential
    {
        $labels = explode('.', $hostname);
        for ($i = 1; $i <= count($labels) - 2; $i++) {
            $zone = implode('.', array_slice($labels, $i));
            $credential = ProviderCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', 'cloudflare')
                ->orderBy('name')
                ->get()
                ->first(function (ProviderCredential $cred) use ($zone): bool {
                    try {
                        return (new CloudflareDnsService($cred))->zoneExists($zone);
                    } catch (Throwable) {
                        return false;
                    }
                });

            if ($credential !== null) {
                return $credential;
            }
        }

        return null;
    }

    private function findOwnedCloudflareZone(ProviderCredential $credential, string $hostname): ?string
    {
        $labels = explode('.', $hostname);
        for ($i = 1; $i <= count($labels) - 2; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            try {
                if ((new CloudflareDnsService($credential))->zoneExists($candidate)) {
                    return $candidate;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function removeAutoDnsRecord(Site $site, string $hostname, array $entry): void
    {
        $zone = (string) ($entry['zone'] ?? '');
        $recordName = (string) ($entry['record_name'] ?? '');
        if ($zone === '' || $recordName === '') {
            return;
        }

        $credential = $this->findCloudflareCredentialForZone($site, $hostname);
        if ($credential === null) {
            return;
        }

        try {
            $dns = new CloudflareDnsService($credential);
            $fqdn = $recordName === '@' ? $zone : strtolower($recordName).'.'.$zone;
            $record = $dns->findCnameRecord($zone, $fqdn);
            if ($record !== null && isset($record['id'])) {
                $dns->deleteDnsRecord($zone, (string) $record['id']);
            }
        } catch (Throwable $e) {
            Log::info('Edge custom-domain Cloudflare record cleanup failed (non-fatal).', [
                'site_id' => $site->id,
                'hostname' => $hostname,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
