<?php

declare(strict_types=1);

namespace App\Modules\Scaffold\Services;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Sites\Dns\SiteDnsProviderFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Per-site placeholder hostname assignment for scaffolded sites (Q13).
 *
 * When a scaffolded site has no custom domain attached, this service
 * picks a slug-derived hostname under the configured platform-default
 * zone (e.g. <slug>.ondply.io), creates the A record via the DNS
 * provider configured for that zone, and records the assignment to
 * meta.scaffold.placeholder_dns so the journey + future teardown can
 * find it.
 *
 * No zone credential configured? Fall back to nip.io (Q12 — universal
 * escape hatch that works without dply owning any DNS infra).
 *
 * Slug collision is handled by appending a 4-char hash suffix on
 * conflict; on second collision we error rather than retry forever.
 */
class PlaceholderDnsManager
{
    public const META_PATH = 'scaffold.placeholder_dns';

    /**
     * Assign a placeholder hostname for the site. Returns the
     * hostname that ends up resolving to the server's IP.
     *
     * @return array{hostname: string, zone: ?string, record_id: ?string, source: string}
     */
    /** @return array<string, mixed> */
    public function assign(Site $site): array
    {
        if ($this->alreadyAssigned($site)) {
            return $site->meta['scaffold']['placeholder_dns'];
        }

        $serverIp = $this->serverIp($site);
        if ($serverIp === null) {
            throw new \RuntimeException('Site server has no IP address recorded — cannot assign placeholder DNS.');
        }

        $zoneConfig = $this->resolveDefaultZone();

        if ($zoneConfig === null) {
            return $this->assignNipIo($site, $serverIp);
        }

        return $this->assignToZone($site, $serverIp, $zoneConfig['zone'], $zoneConfig['credential']);
    }

    /**
     * Tear down the placeholder when the site is being destroyed or
     * when a real domain is attached and the placeholder is no longer
     * the primary. Idempotent — already-deleted records are tolerated.
     */
    public function release(Site $site): void
    {
        $assignment = $site->meta['scaffold']['placeholder_dns'] ?? null;
        if (! is_array($assignment)) {
            return;
        }

        if (($assignment['source'] ?? null) === 'nip.io') {
            // nip.io has no records to delete.
            $this->forgetAssignment($site);

            return;
        }

        $zone = $assignment['zone'] ?? null;
        $recordId = $assignment['record_id'] ?? null;
        if (! is_string($zone) || ! is_string($recordId) || $recordId === '') {
            $this->forgetAssignment($site);

            return;
        }

        $zoneConfig = $this->zoneConfig($zone);
        if ($zoneConfig === null) {
            // Credential gone — best-effort cleanup; record stays orphaned.
            Log::warning('PlaceholderDnsManager: zone credential missing on release', [
                'site_id' => $site->getKey(),
                'zone' => $zone,
            ]);
            $this->forgetAssignment($site);

            return;
        }

        try {
            $provider = SiteDnsProviderFactory::forCredential($zoneConfig['credential']);
            $provider->deleteRecord($zone, $recordId);
        } catch (Throwable $e) {
            Log::warning('PlaceholderDnsManager: record delete failed (idempotent)', [
                'site_id' => $site->getKey(),
                'zone' => $zone,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->forgetAssignment($site);
    }

    /**
     * @return array{zone: string, credential: ProviderCredential}|null
     */
    private function resolveDefaultZone(): ?array
    {
        $defaultZone = config('scaffold_placeholder.default_zone');
        if (! is_string($defaultZone) || $defaultZone === '') {
            return null;
        }

        return $this->zoneConfig($defaultZone);
    }

    /**
     * @return array{zone: string, credential: ProviderCredential}|null
     */
    private function zoneConfig(string $zone): ?array
    {
        $zones = (array) config('scaffold_placeholder.zones', []);
        $entry = $zones[$zone] ?? null;
        if (! is_array($entry) || empty($entry['credential_id'])) {
            return null;
        }

        $credential = ProviderCredential::query()->find($entry['credential_id']);
        if ($credential === null) {
            return null;
        }

        return ['zone' => $zone, 'credential' => $credential];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignNipIo(Site $site, string $serverIp): array
    {
        // nip.io recommends dashed-IP form because some validators
        // choke on the multi-dotted variant (Q12).
        $dashedIp = str_replace('.', '-', $serverIp);
        $hostname = sprintf('%s.%s.nip.io', $this->slug($site), $dashedIp);

        $assignment = [
            'hostname' => $hostname,
            'zone' => null,
            'record_id' => null,
            'source' => 'nip.io',
            'assigned_at' => now()->toISOString(),
        ];
        $this->persistAssignment($site, $assignment);

        return $assignment;
    }

    /**
     * @return array<string, mixed>
     */
    private function assignToZone(Site $site, string $serverIp, string $zone, ProviderCredential $credential): array
    {
        $name = $this->pickAvailableName($site, $zone, $credential);

        $provider = SiteDnsProviderFactory::forCredential($credential);
        $record = $provider->upsertRecord($zone, 'A', $name, $serverIp);

        $assignment = [
            'hostname' => $name.'.'.$zone,
            'zone' => $zone,
            'record_id' => isset($record['id']) ? (string) $record['id'] : null,
            'source' => 'dns_provider',
            'assigned_at' => now()->toISOString(),
        ];
        $this->persistAssignment($site, $assignment);

        return $assignment;
    }

    /**
     * Pick a record name that doesn't already collide with another
     * scaffolded site's placeholder. v1: try the bare slug first; on
     * conflict, append a 4-char hash. Second conflict → throw.
     */
    private function pickAvailableName(Site $site, string $zone, ProviderCredential $credential): string
    {
        $candidate = $this->slug($site);

        if (! $this->nameTakenInOtherSites($candidate, $zone, $site)) {
            return $candidate;
        }

        $candidate = $this->slug($site).'-'.substr(sha1($site->getKey().now()->timestamp), 0, 4);
        if (! $this->nameTakenInOtherSites($candidate, $zone, $site)) {
            return $candidate;
        }

        throw new \RuntimeException("Placeholder name [{$candidate}.{$zone}] still collides after retry — aborting.");
    }

    /**
     * Check the local DB only — placeholder collisions are between
     * dply-managed sites, not arbitrary records on the zone. v2 can
     * cross-check against the live DNS provider's record list.
     */
    private function nameTakenInOtherSites(string $name, string $zone, Site $exceptSite): bool
    {
        $hostname = $name.'.'.$zone;

        return Site::query()
            ->where('id', '!=', $exceptSite->getKey())
            ->where('meta->scaffold->placeholder_dns->hostname', $hostname)
            ->exists();
    }

    private function alreadyAssigned(Site $site): bool
    {
        $existing = $site->meta['scaffold']['placeholder_dns'] ?? null;

        return is_array($existing) && ! empty($existing['hostname']);
    }

    private function serverIp(Site $site): ?string
    {
        $ip = $site->server->public_ip ?? $site->server->ip_address ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private function slug(Site $site): string
    {
        $slug = Str::slug((string) $site->slug ?: (string) $site->name) ?: 'site';

        return substr($slug, 0, 40);
    }

    /**
     * @param  array<string, mixed> $assignment
     */
    private function persistAssignment(Site $site, array $assignment): void
    {
        $site = $site->fresh() ?? $site;
        $meta = ($site->meta );
        data_set($meta, self::META_PATH, $assignment);
        $site->meta = $meta;
        $site->save();
    }

    private function forgetAssignment(Site $site): void
    {
        $site = $site->fresh() ?? $site;
        $meta = ($site->meta );
        unset($meta['scaffold']['placeholder_dns']);
        $site->meta = $meta;
        $site->save();
    }
}
