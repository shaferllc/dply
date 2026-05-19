<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Site;

/**
 * Sidebar nav items for the site workspace (Settings, Web server config, etc.).
 *
 * Each item shape: {id, label, icon, group, route?, parent?}.
 * `route` (optional) names a dedicated route the sidebar.blade.php should link to;
 * absent items fall back to `sites.show?section={id}` (the default settings tab router).
 */
final class SiteSettingsSidebar
{
    /**
     * @return list<array{id: string, label: string, icon: string, group: string, route?: string, parent?: string}>
     */
    public static function items(Site $site, Server $server): array
    {
        $supportsSsh = $server->hostCapabilities()->supportsSsh();

        if ($site->isCustom()) {
            return self::flagSupervisorSetup(self::customItems($site), $server);
        }

        $showWebserverConfigEditor = $supportsSsh
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();

        $runtimeMode = $site->runtimeTargetMode();
        $isContainerWorkspace = in_array($runtimeMode, ['docker', 'kubernetes', 'serverless'], true);

        // Background items (cron / daemons) require SSH on the host. Container and
        // serverless workspaces don't have crontabs or supervisor — skip the group
        // entirely for them so the heading doesn't render empty.
        $showBackgroundGroup = $supportsSsh && ! $isContainerWorkspace;

        $base = $isContainerWorkspace
            ? [
                ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home', 'group' => 'general'],
                ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'group' => 'general'],
                ['id' => 'routing', 'label' => __('Networking'), 'icon' => 'heroicon-o-globe-alt', 'group' => 'networking'],
                ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-signal', 'group' => 'networking'],
                ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy'],
                ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-folder-open', 'group' => 'deploy'],
                ['id' => 'commits', 'label' => __('Commits'), 'icon' => 'heroicon-o-code-bracket', 'group' => 'deploy', 'route' => 'sites.commits'],
                ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent', 'group' => 'runtime'],
                ['id' => 'system-user', 'label' => __('System user'), 'icon' => 'heroicon-o-user', 'group' => 'runtime'],
                ['id' => 'laravel-stack', 'label' => __('Laravel'), 'icon' => 'heroicon-o-bolt', 'group' => 'runtime'],
                ['id' => 'rails-stack', 'label' => __('Rails'), 'icon' => 'heroicon-o-bolt', 'group' => 'runtime'],
                ['id' => 'wordpress', 'label' => __('WordPress'), 'icon' => 'heroicon-o-globe-alt', 'group' => 'runtime'],
                ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'runtime'],
                ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability'],
                ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell', 'group' => 'observability'],
                ['id' => 'monitor', 'label' => __('Monitor'), 'icon' => 'heroicon-o-chart-bar', 'group' => 'observability', 'route' => 'sites.monitor'],
                ['id' => 'basic-auth', 'label' => __('Authentication'), 'icon' => 'heroicon-o-lock-closed', 'group' => 'access'],
                ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box', 'group' => 'danger'],
            ]
            : [
                ['id' => 'general', 'label' => __('General'), 'icon' => 'heroicon-o-rectangle-stack', 'group' => 'general'],
                ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'group' => 'general'],
                ['id' => 'routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-share', 'group' => 'networking'],
                ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-signal', 'group' => 'networking'],
                ['id' => 'certificates', 'label' => __('Certificates'), 'icon' => 'heroicon-o-shield-check', 'group' => 'networking'],
                ['id' => 'deploy', 'label' => __('Deploy'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy'],
                ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-folder-open', 'group' => 'deploy'],
                ['id' => 'commits', 'label' => __('Commits'), 'icon' => 'heroicon-o-code-bracket', 'group' => 'deploy', 'route' => 'sites.commits'],
                ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent', 'group' => 'runtime'],
                ['id' => 'system-user', 'label' => __('System user'), 'icon' => 'heroicon-o-user', 'group' => 'runtime'],
                ['id' => 'laravel-stack', 'label' => __('Laravel'), 'icon' => 'heroicon-o-bolt', 'group' => 'runtime'],
                ['id' => 'rails-stack', 'label' => __('Rails'), 'icon' => 'heroicon-o-bolt', 'group' => 'runtime'],
                ['id' => 'wordpress', 'label' => __('WordPress'), 'icon' => 'heroicon-o-globe-alt', 'group' => 'runtime'],
                ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'runtime'],
                ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability'],
                ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell', 'group' => 'observability'],
                ['id' => 'monitor', 'label' => __('Monitor'), 'icon' => 'heroicon-o-chart-bar', 'group' => 'observability', 'route' => 'sites.monitor'],
                ['id' => 'basic-auth', 'label' => __('Authentication'), 'icon' => 'heroicon-o-lock-closed', 'group' => 'access'],
                ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box', 'group' => 'danger'],
            ];

        $withRuntimeChild = collect($base)
            ->flatMap(function (array $item) use ($site): array {
                if ($item['id'] !== 'runtime') {
                    return [$item];
                }

                $child = self::runtimeChildFor($site);

                return $child === null ? [$item] : [$item, $child];
            })
            ->values()
            ->all();

        $withWebserver = $showWebserverConfigEditor
            ? collect($withRuntimeChild)
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
                            'group' => 'networking',
                            'route' => 'sites.webserver-config',
                        ],
                        [
                            'id' => 'caching',
                            'label' => __('Caching'),
                            'icon' => 'heroicon-o-bolt-slash',
                            'group' => 'networking',
                            'route' => 'sites.caching',
                        ],
                    ];
                })
                ->values()
                ->all()
            : $withRuntimeChild;

        $withBackground = $showBackgroundGroup
            ? self::insertBackgroundGroup($withWebserver)
            : $withWebserver;

        return self::flagSupervisorSetup(
            collect($withBackground)
                ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'laravel-stack' || $site->isLaravelFrameworkDetected())
                ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'rails-stack' || $site->isRailsFrameworkDetected())
                ->filter(fn (array $item): bool => ($item['id'] ?? null) !== 'wordpress' || $site->isWordPressDetected())
                ->values()
                ->all(),
            $server,
        );
    }

    /**
     * Insert the Background group (cron, daemons, queue workers) right before the
     * Access group so the sidebar order is observability → background → access → danger.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function insertBackgroundGroup(array $items): array
    {
        // The Schedule and Backups items navigate to the server-level pages — they're
        // provided here as a convenience entry point. The cron / daemons / queue-workers
        // items above use site-scoped routes (same component, site context bound).
        $background = [
            ['id' => 'cron', 'label' => __('Cron jobs'), 'icon' => 'heroicon-o-clock', 'group' => 'background', 'route' => 'sites.cron'],
            ['id' => 'schedule', 'label' => __('Schedule'), 'icon' => 'heroicon-o-calendar-days', 'group' => 'background', 'route' => 'servers.schedule', 'route_params' => 'server_only'],
            ['id' => 'daemons', 'label' => __('Daemons'), 'icon' => 'heroicon-o-server-stack', 'group' => 'background', 'route' => 'sites.daemons'],
            ['id' => 'queue-workers', 'label' => __('Queue workers'), 'icon' => 'heroicon-o-bolt', 'group' => 'background', 'route' => 'sites.queue-workers'],
            ['id' => 'backups', 'label' => __('Backups'), 'icon' => 'heroicon-o-archive-box', 'group' => 'background', 'route' => 'servers.backups', 'route_params' => 'server_only'],
        ];

        $insertIndex = null;
        foreach ($items as $index => $item) {
            if (($item['group'] ?? null) === 'access') {
                $insertIndex = $index;
                break;
            }
        }

        if ($insertIndex === null) {
            return [...$items, ...$background];
        }

        return [
            ...array_slice($items, 0, $insertIndex),
            ...$background,
            ...array_slice($items, $insertIndex),
        ];
    }

    /**
     * Tight sidebar for Custom (headless) sites — no webserver, SSL, caching,
     * insights, or web-shaped runtime tabs. Daemons / Cron / Queue Workers
     * are first-class since they're the typical workload.
     *
     * @return list<array{id: string, label: string, icon: string, group: string, route?: string, parent?: string}>
     */
    private static function customItems(Site $site): array
    {
        $items = [
            ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home', 'group' => 'general'],
            ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'group' => 'general'],
            ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy'],
        ];

        if ($site->isCustomGitMode()) {
            $items[] = ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-folder-open', 'group' => 'deploy'];
        }

        $items = [...$items,
            ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'runtime'],
            ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability'],
            ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell', 'group' => 'observability'],
            ['id' => 'cron', 'label' => __('Cron jobs'), 'icon' => 'heroicon-o-clock', 'group' => 'background', 'route' => 'sites.cron'],
            ['id' => 'daemons', 'label' => __('Daemons'), 'icon' => 'heroicon-o-server-stack', 'group' => 'background', 'route' => 'sites.daemons'],
            ['id' => 'queue-workers', 'label' => __('Queue workers'), 'icon' => 'heroicon-o-bolt', 'group' => 'background', 'route' => 'sites.queue-workers'],
            ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-archive-box', 'group' => 'danger'],
        ];

        return $items;
    }

    /**
     * Attach `needs_setup => true` to the Daemons / Queue workers items when Supervisor
     * isn't installed on the host. Mirrors the same flag emitted by
     * {@see server_workspace_nav_for_server()} so the sidebar partial can render a
     * "needs install" dot without re-querying the server.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function flagSupervisorSetup(array $items, Server $server): array
    {
        if ($server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_INSTALLED) {
            return $items;
        }

        return array_map(static function (array $item): array {
            if (in_array($item['id'] ?? null, ['daemons', 'queue-workers'], true)) {
                $item['needs_setup'] = true;
            }

            return $item;
        }, $items);
    }

    /**
     * @return array{id: string, label: string, icon: string, group: string, parent: string}|null
     */
    private static function runtimeChildFor(Site $site): ?array
    {
        $runtime = (string) ($site->runtime ?? '');

        return match ($runtime) {
            'php' => ['id' => 'runtime-php', 'label' => __('PHP'), 'icon' => 'heroicon-o-cog', 'group' => 'runtime', 'parent' => 'runtime'],
            'ruby' => ['id' => 'runtime-ruby', 'label' => __('Ruby'), 'icon' => 'heroicon-o-cog', 'group' => 'runtime', 'parent' => 'runtime'],
            'static' => ['id' => 'runtime-static', 'label' => __('Static'), 'icon' => 'heroicon-o-cog', 'group' => 'runtime', 'parent' => 'runtime'],
            default => null,
        };
    }
}
