<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Support\SiteSettingsHeader;

/**
 * Breadcrumb items for site workspace sub-pages.
 */
final class SiteWorkspaceBreadcrumbs
{
    /**
     * @return list<array{label: string, href?: string|null, icon?: string|null}>
     */
    public static function items(
        Server $server,
        Site $site,
        string $currentLabel,
        ?string $currentIcon = null,
    ): array {
        $items = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ];

        // The project deliberately isn't a crumb here. This trail is already
        // Dashboard → Servers → server → Sites → site → page; the project isn't
        // a step on that path (you don't reach the site through it), and it made
        // an eight-crumb bar that wrapped. The project is still reachable from
        // the server overview and the Projects surface.

        $items[] = [
            'label' => $server->name,
            'href' => route('servers.overview', $server),
            'icon' => 'server-stack',
            'avatar' => $server->name ?: (string) $server->id,
            'avatar_image' => $server->logoUrl(),
        ];
        $items[] = [
            'label' => __('Sites'),
            'href' => route('servers.sites', $server),
            'icon' => 'rectangle-stack',
        ];
        $items[] = [
            'label' => $site->name,
            'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
            'icon' => 'globe-alt',
            'avatar' => $site->name ?: (string) $site->id,
            'avatar_image' => $site->logoUrl(),
        ];
        $items[] = [
            'label' => $currentLabel,
            'icon' => $currentIcon ?? 'map-pin',
        ];

        return $items;
    }

    /**
     * @return list<array{label: string, href?: string|null, icon?: string|null}>
     */
    /**
     * @return list<array{label: string, href?: string|null, icon?: string|null}>
     */
    public static function iconKeyFromSection(string $section, Site $site, Server $server): string
    {
        $header = SiteSettingsHeader::for($site, $server, $section);
        $icon = $header['icon'];

        if ($icon === '') {
            return 'map-pin';
        }

        if (str_starts_with($icon, 'heroicon-o-')) {
            return substr($icon, strlen('heroicon-o-'));
        }

        return $icon;
    }
}
