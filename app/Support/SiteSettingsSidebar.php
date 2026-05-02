<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Site;

/**
 * Sidebar nav items for the site workspace (Settings, Web server config, etc.).
 */
final class SiteSettingsSidebar
{
    /**
     * @return list<array{id: string, label: string, icon: string}>
     */
    public static function items(Site $site, Server $server): array
    {
        $showWebserverConfigEditor = $server->hostCapabilities()->supportsSsh()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();

        $runtimeMode = $site->runtimeTargetMode();
        $isContainerWorkspace = in_array($runtimeMode, ['docker', 'kubernetes', 'serverless'], true);

        $base = $isContainerWorkspace
            ? [
                ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home'],
                ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent'],
                ['id' => 'system-user', 'label' => __('System user'), 'icon' => 'heroicon-o-user'],
                ['id' => 'laravel-stack', 'label' => __('Laravel'), 'icon' => 'heroicon-o-bolt'],
                ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square'],
                ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-folder-open'],
                ['id' => 'commits', 'label' => __('Commits'), 'icon' => 'heroicon-o-code-bracket'],
                ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line'],
                ['id' => 'routing', 'label' => __('Networking'), 'icon' => 'heroicon-o-globe-alt'],
                ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-signal'],
                ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list'],
                ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell'],
                ['id' => 'monitor', 'label' => __('Monitor'), 'icon' => 'heroicon-o-chart-bar'],
                ['id' => 'basic-auth', 'label' => __('Authentication'), 'icon' => 'heroicon-o-lock-closed'],
                ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box'],
            ]
            : [
                ['id' => 'general', 'label' => __('General'), 'icon' => 'heroicon-o-rectangle-stack'],
                ['id' => 'routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-share'],
                ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-signal'],
                ['id' => 'certificates', 'label' => __('Certificates'), 'icon' => 'heroicon-o-shield-check'],
                ['id' => 'deploy', 'label' => __('Deploy'), 'icon' => 'heroicon-o-code-bracket-square'],
                ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-folder-open'],
                ['id' => 'commits', 'label' => __('Commits'), 'icon' => 'heroicon-o-code-bracket'],
                ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent'],
                ['id' => 'system-user', 'label' => __('System user'), 'icon' => 'heroicon-o-user'],
                ['id' => 'laravel-stack', 'label' => __('Laravel'), 'icon' => 'heroicon-o-bolt'],
                ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line'],
                ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list'],
                ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell'],
                ['id' => 'monitor', 'label' => __('Monitor'), 'icon' => 'heroicon-o-chart-bar'],
                ['id' => 'basic-auth', 'label' => __('Authentication'), 'icon' => 'heroicon-o-lock-closed'],
                ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box'],
            ];

        $withWebserver = $showWebserverConfigEditor
            ? collect($base)
                ->flatMap(function (array $item): array {
                    if ($item['id'] !== 'routing') {
                        return [$item];
                    }

                    return [
                        $item,
                        [
                            'id' => 'webserver-config',
                            'label' => __('Web server config'),
                            'icon' => 'heroicon-o-cog-6-tooth',
                        ],
                    ];
                })
                ->values()
                ->all()
            : $base;

        return collect($withWebserver)
            ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'laravel-stack' || $site->isLaravelFrameworkDetected())
            ->values()
            ->all();
    }
}
