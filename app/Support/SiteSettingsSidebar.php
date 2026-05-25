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
        if ($site->usesEdgeRuntime()) {
            return self::edgeItems($site);
        }

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

        // Container / serverless workspaces run behind the dply cloud — host
        // webserver, system user, basic auth, certificates, and framework-
        // specific stack tabs (Laravel/Rails/WordPress) all belong either
        // to the cloud or to the operator's artifact, not this workspace.
        // BACKGROUND (Schedule / Workers) sits between RUNTIME and OBSERVABILITY
        // so the page reads: configure → run → observe → destroy.
        //
        // The `routing` item below is DIFFERENT from the VM `routing` (which
        // edits nginx server blocks). Here it manages dply's edge proxy:
        // hostname & DNS, custom domains pointed at the function, path
        // redirects, response headers + CORS, the invocation URL. Same group
        // key ("networking"), different surface.
        $base = $isContainerWorkspace
            ? [
                ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home', 'group' => 'general'],
                ['id' => 'settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'group' => 'general'],
                ['id' => 'routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-share', 'group' => 'networking', 'route' => 'sites.routing'],
                // Deployments is the history list — recipe (URL/branch/pipeline/hooks/etc.) lives on Repository (section=repository), per Q3.
                ['id' => 'deploy', 'label' => __('Deployments'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy', 'route' => 'sites.deployments.index'],
                // Serverless Repository is a dedicated Livewire page (browse
                // files / branches / switch repo) — distinct from the VM
                // section-router partial that just shows the config form.
                ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-folder-open', 'group' => 'deploy', 'route' => 'sites.repository'],
                ['id' => 'commits', 'label' => __('Commits'), 'icon' => 'heroicon-o-code-bracket', 'group' => 'deploy', 'route' => 'sites.commits'],
                ['id' => 'runtime', 'label' => __('Runtime'), 'icon' => 'heroicon-o-cube-transparent', 'group' => 'runtime'],
                ['id' => 'environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'runtime'],
                ['id' => 'resources', 'label' => __('Resources'), 'icon' => 'heroicon-o-puzzle-piece', 'group' => 'runtime', 'route' => 'sites.resources'],
                ['id' => 'schedule', 'label' => __('Schedule'), 'icon' => 'heroicon-o-calendar-days', 'group' => 'background', 'route' => 'sites.schedule'],
                ['id' => 'workers', 'label' => __('Workers'), 'icon' => 'heroicon-o-bolt', 'group' => 'background', 'route' => 'sites.workers'],
                ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability'],
                ['id' => 'platform', 'label' => __('Platform'), 'icon' => 'heroicon-o-cube', 'group' => 'observability'],
                ['id' => 'notifications', 'label' => __('Notifications'), 'icon' => 'heroicon-o-bell', 'group' => 'observability'],
                ['id' => 'monitor', 'label' => __('Monitor'), 'icon' => 'heroicon-o-chart-bar', 'group' => 'observability', 'route' => 'sites.monitor'],
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

        // The Platform tab is the OpenWhisk inspector — it only applies to
        // DigitalOcean Functions hosts, not docker / kubernetes containers.
        if (! $site->usesFunctionsRuntime()) {
            $base = array_values(array_filter($base, fn (array $item): bool => $item['id'] !== 'platform'));
        }

        // Runtime sub-tabs (runtime-php / runtime-ruby / runtime-static) are
        // VM-only — they expose engine knobs that, for container/serverless
        // workspaces, live in the artifact (Dockerfile / function manifest).
        $withRuntimeChild = $isContainerWorkspace
            ? $base
            : collect($base)
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
                        [
                            'id' => 'cdn',
                            'label' => __('CDN / Edge'),
                            'icon' => 'heroicon-o-globe-alt',
                            'group' => 'networking',
                            'route' => 'sites.cdn',
                        ],
                    ];
                })
                ->values()
                ->all()
            : $withRuntimeChild;

        $withBackground = $showBackgroundGroup
            ? self::insertBackgroundGroup($withWebserver)
            : $withWebserver;

        // Framework-specific stack tabs (Laravel/Rails/WordPress) only apply to
        // VM workspaces where dply manages the stack directly. Container/
        // serverless workspaces never include these items in the base.
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
     * Edge-native workspace — git builds, CDN delivery, custom domains.
     * No VM runtime, SSH, nginx, or certificate automation tabs.
     *
     * @return list<array{id: string, label: string, icon: string, group: string}>
     */
    private static function edgeItems(Site $site): array
    {
        $edgeMeta = $site->edgeMeta();
        $isPreviewChild = ! empty($edgeMeta['preview_parent_site_id']);

        $items = [
            ['id' => 'general', 'label' => __('Overview'), 'icon' => 'heroicon-o-home', 'group' => 'general'],
            ['id' => 'edge-deploys', 'label' => __('Deploys'), 'icon' => 'heroicon-o-code-bracket-square', 'group' => 'deploy'],
            ['id' => 'edge-domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt', 'group' => 'networking'],
            ['id' => 'edge-build', 'label' => __('Build'), 'icon' => 'heroicon-o-wrench-screwdriver', 'group' => 'deploy'],
            ['id' => 'edge-environment', 'label' => __('Environment'), 'icon' => 'heroicon-o-command-line', 'group' => 'deploy'],
            ['id' => 'edge-deploy-triggers', 'label' => __('Deploy triggers'), 'icon' => 'heroicon-o-bolt', 'group' => 'deploy'],
            ['id' => 'edge-routing', 'label' => __('Routing'), 'icon' => 'heroicon-o-arrows-right-left', 'group' => 'networking'],
            ['id' => 'edge-bindings', 'label' => __('Bindings'), 'icon' => 'heroicon-o-cube', 'group' => 'networking', 'route' => 'sites.edge-bindings'],
            ['id' => 'edge-audit', 'label' => __('Audit log'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability'],
            ['id' => 'edge-members', 'label' => __('Members'), 'icon' => 'heroicon-o-user-group', 'group' => 'access'],
        ];

        if (! $isPreviewChild) {
            $items[] = ['id' => 'edge-delivery', 'label' => __('Delivery'), 'icon' => 'heroicon-o-cloud', 'group' => 'networking'];
        }

        if (! $isPreviewChild) {
            $items[] = ['id' => 'edge-previews', 'label' => __('Previews'), 'icon' => 'heroicon-o-sparkles', 'group' => 'deploy'];
        }

        if (! $isPreviewChild) {
            $items[] = ['id' => 'edge-traffic', 'label' => __('Traffic & analytics'), 'icon' => 'heroicon-o-signal', 'group' => 'observability'];
            $items[] = ['id' => 'edge-billing', 'label' => __('Billing & usage'), 'icon' => 'heroicon-o-chart-bar', 'group' => 'observability'];
        }

        $items[] = ['id' => 'edge-logs', 'label' => __('Build & deploy logs'), 'icon' => 'heroicon-o-clipboard-document-list', 'group' => 'observability'];
        $items[] = ['id' => 'danger', 'label' => __('Danger zone'), 'icon' => 'heroicon-o-exclamation-triangle', 'group' => 'danger'];

        return $items;
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
