<?php

declare(strict_types=1);

namespace App\Support\Preview;

use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Shared preview hostname label + apex rules for BYO VM sites and Edge
 * delivery — `{slug}-{idHash8}.{apex}` for primaries,
 * `{parentLabel}--{qualifier}.{apex}` for branch/PR previews.
 */
final class UnifiedPreviewHostname
{
    public function enabled(): bool
    {
        return (bool) config('preview.unified_hostnames', true);
    }

    /**
     * Stable primary label: `{slug}-{8-char sha1}` (matches BYO testing hostnames).
     */
    public function siteLabel(Site $site): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : (string) $site->name);
        $base = trim($base, '-');
        $base = $base !== '' ? $base : 'site';

        $suffixSource = $site->id ?: ($site->server_id ?: $site->name);
        $suffix = Str::lower(substr(sha1((string) $suffixSource), 0, 8));
        $label = Str::limit($base.'-'.$suffix, 63, '');

        return rtrim($label, '-');
    }

    public function canonicalHostname(Site $site, ?string $zone = null): string
    {
        $zone ??= $this->apexForSite($site);

        return strtolower($this->siteLabel($site).'.'.$zone);
    }

    /**
     * Preferred managed-preview apex — on-dply.site when present in the pool.
     */
    public function preferredApex(): string
    {
        if ((bool) config('preview.prefer_on_dply_apex', true)) {
            foreach (self::sharedTestingPool() as $domain) {
                if ($domain === self::preferredOnDplyApex()) {
                    return $domain;
                }
            }

            foreach (self::sharedTestingPool() as $domain) {
                if (self::isOnDplyDomain($domain)) {
                    return $domain;
                }
            }
        }

        if (self::sharedTestingPool() !== []) {
            return self::sharedTestingPool()[0];
        }

        return self::preferredOnDplyApex();
    }

    /**
     * Resolve apex for a site — custom DNS zone, existing hostname, or pool default.
     */
    public function apexForSite(Site $site): string
    {
        $dnsZone = strtolower(trim((string) ($site->dns_zone ?? '')));
        if ($dnsZone !== '') {
            return $dnsZone;
        }

        $existing = strtolower(trim($site->testingHostname()));
        if ($apex = $this->apexFromHostname($existing)) {
            return $apex;
        }

        return $this->preferredApex();
    }

    /**
     * Branch / PR preview label using double-dash qualifiers (Edge alias style).
     */
    public function branchPreviewLabel(Site $parent, string $branch, ?int $prNumber): string
    {
        $parentLabel = $this->siteLabel($parent);

        if ($prNumber !== null && $prNumber > 0) {
            return Str::limit($parentLabel.'--pr-'.$prNumber, 63, '');
        }

        $branchSlug = Str::slug(str_replace(['/', '_'], '-', $branch));
        if ($branchSlug === '') {
            $branchSlug = substr(md5($branch), 0, 8);
        }

        return Str::limit($parentLabel.'--'.$branchSlug, 63, '');
    }

    public function branchPreviewHostname(Site $parent, string $branch, ?int $prNumber, ?string $apex = null): string
    {
        $apex ??= $this->apexForSite($parent);

        return strtolower($this->branchPreviewLabel($parent, $branch, $prNumber).'.'.$apex);
    }

    public function adhocPreviewLabel(Site $parent, string $headSha): string
    {
        $parentLabel = $this->siteLabel($parent);
        $shortSha = substr(strtolower(trim($headSha)), 0, 7);

        return Str::limit($parentLabel.'--'.$shortSha, 63, '');
    }

    public function adhocPreviewHostname(Site $parent, string $headSha, ?string $apex = null): string
    {
        $apex ??= $this->apexForSite($parent);

        return strtolower($this->adhocPreviewLabel($parent, $headSha).'.'.$apex);
    }

    /**
     * When unified, prefer on-dply.* zones from the shared testing pool for BYO.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    public function orderedTestingZones(array $pool): array
    {
        if (! $this->enabled() || ! (bool) config('preview.prefer_on_dply_apex', true)) {
            return $pool;
        }

        $onDply = array_values(array_filter(
            $pool,
            static fn (string $domain): bool => self::isOnDplyDomain($domain),
        ));

        if ($onDply === []) {
            return $pool;
        }

        $preferred = self::preferredOnDplyApex();
        $ordered = in_array($preferred, $onDply, true) ? [$preferred] : [];
        foreach ($onDply as $domain) {
            if (! in_array($domain, $ordered, true)) {
                $ordered[] = $domain;
            }
        }
        foreach ($pool as $domain) {
            if (! in_array($domain, $ordered, true)) {
                $ordered[] = $domain;
            }
        }

        return $ordered;
    }

    public function apexFromHostname(string $hostname): ?string
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || ! str_contains($hostname, '.')) {
            return null;
        }

        $apex = substr($hostname, strpos($hostname, '.') + 1);

        return $apex !== '' && str_contains($apex, '.') ? $apex : null;
    }

    /**
     * @return list<string>
     */
    private static function sharedTestingPool(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            (array) config('services.digitalocean.testing_domains', []),
        )));
    }

    /**
     * Managed-preview hostnames live on the on-dply.* pool. This preference
     * used to sit in the Edge module's testing-domain helper; previews are the
     * only consumer left, so it lives here now.
     */
    private const PREFERRED_APEX = 'on-dply.site';

    private static function preferredOnDplyApex(): string
    {
        $pool = self::sharedTestingPool();

        foreach ([self::PREFERRED_APEX, 'on-dply.cloud'] as $candidate) {
            if (in_array($candidate, $pool, true)) {
                return $candidate;
            }
        }

        foreach ($pool as $domain) {
            if (self::isOnDplyDomain($domain)) {
                return $domain;
            }
        }

        return $pool[0] ?? self::PREFERRED_APEX;
    }

    private static function isOnDplyDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'on-dply.') || str_starts_with($domain, 'ondply.');
    }
}
