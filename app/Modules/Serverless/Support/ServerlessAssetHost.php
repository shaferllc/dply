<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

use App\Models\Site;

/**
 * Naming for a function's published front-end assets.
 *
 * A single bucket holds every site's build, separated by prefix, and the
 * prefix IS the first DNS label of the site's asset hostname:
 *
 *   host    {label}-assets.{serverless apex}
 *   prefix  serverless-assets/{label}/
 *
 * That equality is the whole trick. It lets ONE fleet-wide Cloudflare rule
 * route every site — the rule captures the label off the Host header and
 * prepends the prefix — with no per-site configuration, no KV lookup and no
 * Worker on the hot path. It also means a hostname is structurally incapable
 * of reaching another site's prefix, so asset egress cannot be attributed to
 * the wrong site by crafting a URL.
 *
 * Uniqueness is inherited from DNS: {@see Site::ensureServerlessProxySlug()}
 * already guarantees globally-unique slugs, so two sites can never collide on
 * a prefix. Assets key on the proxy slug rather than the site id precisely
 * because the slug is the only value that appears in BOTH the hostname and the
 * bucket — and it is never reminted once allocated.
 */
final class ServerlessAssetHost
{
    /**
     * Label suffix marking an asset hostname apart from the function hostname.
     * The literal is what keeps the routing rule from ever matching the
     * function host itself.
     */
    public const HOST_SUFFIX = '-assets';

    public const STORAGE_PREFIX = 'serverless-assets';

    /** DNS caps a label at 63 characters, suffix included. */
    private const MAX_LABEL_LENGTH = 63;

    /**
     * The site's asset label — its proxy slug, shortened if appending the
     * suffix would overflow a DNS label. Null when no slug is allocated yet
     * (read-only: never mints one, so collectors can call this safely).
     */
    public static function label(Site $site): ?string
    {
        $slug = trim((string) ($site->serverlessConfig()['proxy_slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        return self::fit($slug);
    }

    /**
     * `{label}-assets.{apex}` — the default CDN hostname for this site's
     * assets. Shares the apex with the function host so both resolve under the
     * same wildcard record and the same Cloudflare zone.
     */
    public static function hostname(Site $site): ?string
    {
        $label = self::label($site);
        if ($label === null) {
            return null;
        }

        return $label.self::HOST_SUFFIX.'.'.ServerlessTestingDomains::apexFor($site->getKey());
    }

    /** Bucket prefix holding this site's published build. */
    public static function prefix(Site $site): ?string
    {
        $label = self::label($site);

        return $label === null ? null : self::STORAGE_PREFIX.'/'.$label;
    }

    /**
     * Where assets lived before they were keyed on the label: under the raw
     * site id. Reads fall back to this so a site still serves through the
     * cutover, before the backfill has renamed its prefix.
     */
    public static function legacyPrefix(Site $site): string
    {
        return self::STORAGE_PREFIX.'/'.$site->getKey();
    }

    /**
     * Operator-attached custom asset hostnames (e.g. cdn.acme.com), persisted
     * by the custom-domain flow. Empty until a site attaches one.
     *
     * @return list<string>
     */
    public static function customHostnames(Site $site): array
    {
        $assets = $site->serverlessConfig()['assets'] ?? [];
        $hostnames = is_array($assets) ? ($assets['custom_hostnames'] ?? []) : [];

        if (! is_array($hostnames)) {
            return [];
        }

        $normalized = [];
        foreach ($hostnames as $hostname) {
            $hostname = strtolower(trim((string) $hostname));
            if ($hostname !== '') {
                $normalized[] = $hostname;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Every hostname that can serve this site's assets — the default plus any
     * custom ones. Used to record raw per-hostname traffic; see
     * {@see \App\Modules\Serverless\Services\ServerlessAssetEgressReader} for
     * which subset is actually billed.
     *
     * @return list<string>
     */
    public static function hostnames(Site $site): array
    {
        $default = self::hostname($site);

        return array_values(array_unique(array_filter(array_merge(
            $default !== null ? [$default] : [],
            self::customHostnames($site),
        ))));
    }

    /**
     * PCRE matching an asset hostname on $apex, capturing the label — the
     * same expression the Cloudflare rewrite rule uses (Cloudflare's
     * `regex_replace` takes PCRE syntax).
     *
     * The `[a-z0-9-]*` is deliberately GREEDY. A site whose own slug contains
     * "-assets" produces `foo-assets-a1b2c3d4-assets.{apex}`; greedy matching
     * backtracks to the FINAL suffix and captures `foo-assets-a1b2c3d4`, which
     * is the correct prefix. A lazy quantifier would capture `foo` and serve
     * one tenant's hostname from another tenant's prefix.
     */
    public static function hostRegex(string $apex): string
    {
        return '^([a-z0-9][a-z0-9-]*)'
            .preg_quote(self::HOST_SUFFIX, '/')
            .'\.'.preg_quote(strtolower(trim($apex)), '/').'$';
    }

    /**
     * The label encoded in an asset hostname, or null when the host is not an
     * asset hostname on a dply-controlled apex.
     */
    public static function labelFromHostname(string $hostname): ?string
    {
        $hostname = strtolower(trim($hostname));

        foreach (ServerlessTestingDomains::routable() as $apex) {
            if (preg_match('/'.self::hostRegex($apex).'/', $hostname, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Shorten a slug so `{label}-assets` still fits a DNS label. Keeps a
     * readable head and re-establishes uniqueness with a hash of the full
     * slug, since the slug's own uniqueness lives in its trailing suffix and
     * would be lost to a plain truncation.
     */
    private static function fit(string $slug): string
    {
        $budget = self::MAX_LABEL_LENGTH - strlen(self::HOST_SUFFIX);
        if (strlen($slug) <= $budget) {
            return $slug;
        }

        return rtrim(substr($slug, 0, $budget - 9), '-').'-'.substr(sha1($slug), 0, 8);
    }
}
