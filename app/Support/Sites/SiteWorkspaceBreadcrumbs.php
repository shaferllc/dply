<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Support\SiteSettingsHeader;
use Laravel\Pennant\Feature;

/**
 * Breadcrumb items for BYO / Edge site workspace sub-pages.
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
        if ($site->usesEdgeRuntime()) {
            return self::edgeItems($server, $site, $currentLabel, $currentIcon);
        }

        $items = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Servers'), 'href' => route('servers.index'), 'icon' => 'server-stack'],
        ];

        if ($server->workspace && Feature::active('surface.projects')) {
            $items[] = [
                'label' => $server->workspace->name,
                'href' => route('projects.resources', $server->workspace),
                'icon' => 'rectangle-group',
            ];
        }

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
    private static function edgeItems(
        Server $server,
        Site $site,
        string $currentLabel,
        ?string $currentIcon,
    ): array {
        $items = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
            ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
            [
                'label' => $site->name,
                'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
                'icon' => 'globe-alt',
                'avatar' => $site->name ?: (string) $site->id,
                'avatar_image' => $site->logoUrl(),
            ],
            [
                'label' => $currentLabel,
                'icon' => $currentIcon ?? 'map-pin',
            ],
        ];

        return $items;
    }

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
