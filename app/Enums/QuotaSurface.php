<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Server;
use App\Models\Site;

/**
 * The product surfaces that carry their own plan ceiling.
 *
 * Every managed thing dply runs is a `Site` row and counts against one
 * ceiling. The Edge, Cloud surfaces lived here until those
 * products moved to their own app; `Site` covers container (Docker/Kubernetes)
 * apps too, since those live on a real machine host.
 */
enum QuotaSurface: string
{
    case Site = 'site';

    /**
     * Which surface a site's usage counts against, decided by the host row.
     * Managed-product sites are identified by the logical host they hang off,
     * not by their own columns.
     */
    public static function forSite(Site $site): self
    {
        $server = $site->server;

        if ($server === null) {
            return self::Site;
        }

        // Driven off hostKinds() rather than a parallel match, so a surface
        // can never classify one way and count the other.
        $hostKind = $server->hostKind();

        foreach (self::cases() as $surface) {
            $kinds = $surface->hostKinds();

            if ($kinds !== null && in_array($hostKind, $kinds, true)) {
                return $surface;
            }
        }

        return self::Site;
    }

    /**
     * Which surface a new thing created ON this host would consume. Used by
     * create gates, which run before any Site row exists.
     */
    public static function forServer(Server $server): self
    {
        $hostKind = $server->hostKind();

        foreach (self::cases() as $surface) {
            $kinds = $surface->hostKinds();

            if ($kinds !== null && in_array($hostKind, $kinds, true)) {
                return $surface;
            }
        }

        return self::Site;
    }

    /**
     * Key under `subscription.standard.plans.<plan>` holding this ceiling.
     */
    public function planConfigKey(): string
    {
        return match ($this) {
            self::Site => 'max_sites',
        };
    }

    /**
     * Key under `subscription.standard.beta` holding the beta envelope for
     * this surface. Beta caps replace plan ceilings until cutover.
     */
    public function betaConfigKey(): string
    {
        return match ($this) {
            self::Site => 'sites',
        };
    }

    /**
     * Fallback beta ceiling when the config key is absent.
     */
    public function betaDefault(): int
    {
        return match ($this) {
            self::Site => 25,
        };
    }

    /**
     * `singular|plural` for trans_choice, e.g. "2 sites".
     */
    public function nounKey(): string
    {
        return match ($this) {
            self::Site => 'site|sites',
        };
    }

    public function noun(int $count = 1): string
    {
        return trans_choice($this->nounKey(), $count);
    }

    /**
     * How the surface is named in headings, e.g. "site limit reached".
     */
    public function label(): string
    {
        return match ($this) {
            self::Site => 'site',
        };
    }

    /**
     * Where the org reviews what is already consuming this ceiling.
     */
    public function indexRouteName(): string
    {
        return match ($this) {
            self::Site => 'sites.index',
        };
    }

    /**
     * Ceilings in the order they are shown to customers.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Site];
    }

    /**
     * Host kinds that consume this surface — used to count usage in SQL
     * without hydrating every site. Null means "machine hosts" (the `Site`
     * surface), which is the complement of the managed-product kinds.
     *
     * @return list<string>|null
     */
    public function hostKinds(): ?array
    {
        return match ($this) {
            self::Site => null,
        };
    }
}
