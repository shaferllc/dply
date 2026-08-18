<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Server;
use App\Models\Site;

/**
 * The product surfaces that carry their own plan ceiling.
 *
 * Every managed thing dply runs is a `Site` row, but they are not
 * interchangeable units of value: a VM site consumes a machine the customer
 * already pays a plan tier for, while Edge apps, Cloud apps and functions are
 * billed a la carte per app (see `edge_cents` / `cloud_cents` /
 * `serverless_cents` in config/product/subscription.php).
 *
 * They used to share ONE org-wide ceiling, so a Free org with two Edge static
 * sites and one function was locked out of its first VM site and read a
 * "3 / 1" limit on a server showing "No sites yet". Each surface now counts and
 * blocks independently.
 *
 * `Site` deliberately covers container (Docker/Kubernetes) apps too — those
 * live on a real machine host, so they belong to the machine-site ceiling.
 */
enum QuotaSurface: string
{
    case Site = 'site';
    case Edge = 'edge';
    case Cloud = 'cloud';
    case Serverless = 'serverless';

    /**
     * Which surface a site's usage counts against, decided by the host row.
     * Managed-product sites are identified by the logical host they hang off,
     * not by their own columns.
     */
    public static function forSite(Site $site): self
    {
        $server = $site->server;

        if ($server === null) {
            return $site->isCloudContainerSite() ? self::Cloud : self::Site;
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
            self::Edge => 'max_edge_apps',
            self::Cloud => 'max_cloud_apps',
            self::Serverless => 'max_functions',
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
            self::Edge => 'edge_apps',
            self::Cloud => 'cloud_apps',
            self::Serverless => 'functions',
        };
    }

    /**
     * Fallback beta ceiling when the config key is absent.
     */
    public function betaDefault(): int
    {
        return match ($this) {
            self::Site => 25,
            self::Edge => 25,
            self::Cloud => 10,
            self::Serverless => 25,
        };
    }

    /**
     * `singular|plural` for trans_choice, e.g. "2 Edge apps".
     */
    public function nounKey(): string
    {
        return match ($this) {
            self::Site => 'site|sites',
            self::Edge => 'Edge app|Edge apps',
            self::Cloud => 'Cloud app|Cloud apps',
            self::Serverless => 'function|functions',
        };
    }

    public function noun(int $count = 1): string
    {
        return trans_choice($this->nounKey(), $count);
    }

    /**
     * How the surface is named in headings, e.g. "Edge app limit reached".
     */
    public function label(): string
    {
        return match ($this) {
            self::Site => 'site',
            self::Edge => 'Edge app',
            self::Cloud => 'Cloud app',
            self::Serverless => 'function',
        };
    }

    /**
     * Where the org reviews what is already consuming this ceiling.
     */
    public function indexRouteName(): string
    {
        return match ($this) {
            self::Site => 'sites.index',
            self::Edge => 'edge.index',
            self::Cloud => 'cloud.index',
            self::Serverless => 'serverless.index',
        };
    }

    /**
     * Ceilings in the order they are shown to customers.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Site, self::Cloud, self::Edge, self::Serverless];
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
            self::Edge => [Server::HOST_KIND_DPLY_EDGE],
            self::Cloud => [
                Server::HOST_KIND_DPLY_CLOUD,
                Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM,
                Server::HOST_KIND_AWS_APP_RUNNER,
            ],
            self::Serverless => [
                Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                Server::HOST_KIND_AWS_LAMBDA,
            ],
        };
    }
}
