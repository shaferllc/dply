<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;

/**
 * Canonical provider-side tags stamped on every VM dply creates.
 *
 * Two things need to be true of any box we create, from the provider console
 * alone: (1) it is ours — so an operator (or a sweeper) can tell a dply box
 * from one someone hand-rolled in the same account, and (2) which dply server
 * row it belongs to — so an orphan can be traced back (or reaped) without
 * matching on the display name, which users rename freely.
 *
 * Providers model this two ways, so this class renders the same pair of facts
 * in both shapes:
 *
 *   - {@see tags()}   — flat string list, for DigitalOcean / Vultr / Linode.
 *   - {@see labels()} — key/value map, for Hetzner / UpCloud / AWS / Azure /
 *                       Oracle (which all key their tags).
 *
 * Values stay inside the tightest limits across providers: Linode requires
 * 3-50 chars, Hetzner label values cap at 63 and must be alphanumeric with
 * `-`, `_`, `.`. A `dply-` + 26-char lowercase ULID is 31 chars, so it fits
 * everywhere unchanged.
 *
 * OVH is the one gap — its public-cloud instance create takes no tags at all.
 */
final class ProviderResourceTags
{
    /** Marker for "dply created this". */
    public const MARKER = 'dply';

    /** Prefix for the per-server identity tag. */
    public const PREFIX = self::MARKER.'-';

    /** Prefix for the per-site identity tag on related provider resources. */
    public const SITE_PREFIX = self::MARKER.'-site-';

    /** Marker for a managed database / cache cluster (vs a VM). */
    public const KIND_DATABASE = self::MARKER.'-database';

    /** Label key carrying the server ULID on key/value providers. */
    public const SERVER_ID_KEY = self::PREFIX.'server-id';

    /** Label key carrying the marker on key/value providers. */
    public const MANAGED_BY_KEY = 'managed-by';

    /**
     * The per-server identity tag — `dply-<server ulid>`.
     */
    public static function forServer(Server $server): string
    {
        return self::PREFIX.$server->getKey();
    }

    /**
     * The per-site identity tag — `dply-site-<site ulid>`.
     */
    public static function forSite(Site $site): string
    {
        return self::SITE_PREFIX.$site->getKey();
    }

    /**
     * Flat tags for a managed database / Valkey cluster in the provider
     * console. Always includes {@see MARKER} and {@see KIND_DATABASE}, plus
     * the related server and site when we know them — so the droplet and
     * its cluster share `dply-<server id>` and can be filtered together.
     *
     * @return list<string>
     */
    public static function forManagedDatabase(?Server $server = null, ?Site $site = null): array
    {
        $tags = [self::MARKER, self::KIND_DATABASE];

        if ($server instanceof Server) {
            $tags[] = self::forServer($server);
        }

        if ($site instanceof Site) {
            $tags[] = self::forSite($site);
        }

        return array_values(array_unique($tags));
    }

    /**
     * Resolve the site (and therefore its server) a CloudDatabase was
     * provisioned for, then return {@see forManagedDatabase()}.
     *
     * @return list<string>
     */
    public static function forCloudDatabase(CloudDatabase $database): array
    {
        $site = self::siteForCloudDatabase($database);

        return self::forManagedDatabase($site?->server, $site);
    }

    private static function siteForCloudDatabase(CloudDatabase $database): ?Site
    {
        $siteId = trim((string) (data_get($database->meta, 'provisioned_for_site_id') ?? ''));
        if ($siteId !== '') {
            $site = Site::query()->find($siteId);
            if ($site instanceof Site) {
                return $site;
            }
        }

        $database->loadMissing('sites.server');
        $attached = $database->sites->first();
        if ($attached instanceof Site) {
            return $attached;
        }

        $binding = SiteBinding::query()
            ->where('target_type', 'cloud_database')
            ->where('target_id', $database->id)
            ->first();

        return $binding?->site;
    }

    /**
     * Flat tag list for providers whose tags are plain strings.
     *
     * @return list<string>
     */
    public static function tags(Server $server): array
    {
        return [self::MARKER, self::forServer($server)];
    }

    /**
     * Canonical tags merged into caller-supplied ones (ours win, order kept,
     * blanks and duplicates dropped). Used where the user can also supply
     * their own tags — e.g. DigitalOcean's `meta.digitalocean.tags`.
     *
     * @param  array<int|string, mixed>  $existing
     * @return list<string>
     */
    public static function mergeTags(Server $server, array $existing = []): array
    {
        $normalized = [];
        foreach ($existing as $tag) {
            if (! is_string($tag) && ! is_int($tag)) {
                continue;
            }
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $normalized[] = $tag;
            }
        }

        return array_values(array_unique([...self::tags($server), ...$normalized]));
    }

    /**
     * Key/value labels for providers whose tags are keyed.
     *
     * @return array<string, string>
     */
    public static function labels(Server $server): array
    {
        return [
            self::MANAGED_BY_KEY => self::MARKER,
            self::SERVER_ID_KEY => (string) $server->getKey(),
        ];
    }

    /**
     * Whether a provider-reported tag list / label map belongs to this server.
     * Accepts either shape so callers (orphan sweeps, imports) don't have to
     * care which provider the payload came from.
     *
     * @param  array<int|string, mixed>  $tags
     */
    public static function belongsToServer(Server $server, array $tags): bool
    {
        $needle = self::forServer($server);

        foreach ($tags as $key => $value) {
            if (is_string($key) && $key === self::SERVER_ID_KEY && (string) $value === (string) $server->getKey()) {
                return true;
            }
            if ((is_string($value) || is_int($value)) && (string) $value === $needle) {
                return true;
            }
        }

        return false;
    }
}
