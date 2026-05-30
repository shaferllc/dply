<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceEdgeProxy;
use App\Models\Server;

/**
 * View-model for the server Edge proxy workspace blade tree.
 */
final class EdgeProxyWorkspaceViewData
{
    /**
     * @return array<string, array{label: string, icon: string, systemd: string, coming_soon?: bool}>
     */
    public static function edgeProxyCatalog(): array
    {
        $catalog = [
            'traefik' => ['label' => 'Traefik', 'icon' => 'heroicon-o-arrow-path-rounded-square', 'systemd' => 'traefik'],
            'haproxy' => ['label' => 'HAProxy', 'icon' => 'heroicon-o-scale', 'systemd' => 'haproxy'],
            'envoy' => ['label' => 'Envoy', 'icon' => 'heroicon-o-arrows-right-left', 'systemd' => 'envoy'],
            'openresty' => ['label' => 'OpenResty', 'icon' => 'heroicon-o-code-bracket-square', 'systemd' => 'openresty'],
        ];

        foreach (self::comingSoonEdgeProxies() as $key) {
            if (isset($catalog[$key])) {
                $catalog[$key]['coming_soon'] = true;
            }
        }

        return $catalog;
    }

    /**
     * @return list<string>
     */
    public static function comingSoonEdgeProxies(): array
    {
        $keys = config('server_workspace.edge_proxy_coming_soon', []);

        return is_array($keys) ? array_values(array_map('strtolower', $keys)) : [];
    }

    public static function isComingSoonEdgeProxy(string $key): bool
    {
        $key = strtolower(trim($key));

        return in_array($key, self::comingSoonEdgeProxies(), true);
    }

    /**
     * Edge proxies with a shipped {@see AddEdgeProxyJob} install path.
     *
     * @return list<string>
     */
    public static function installableEdgeProxies(): array
    {
        return array_values(array_filter(
            ['traefik', 'haproxy', 'envoy'],
            fn (string $key): bool => ! self::isComingSoonEdgeProxy($key),
        ));
    }

    /**
     * Webserver key recorded when the edge proxy was added (restore target on remove).
     */
    public static function previousWebserverKey(Server $server): string
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $previous = strtolower(trim((string) ($meta['edge_proxy_previous_webserver'] ?? '')));
        $catalog = WebserverWorkspaceViewData::webserverCatalog();
        if ($previous !== '' && isset($catalog[$previous])) {
            return $previous;
        }

        $fallback = strtolower(trim((string) ($meta['webserver'] ?? 'nginx')));

        return isset($catalog[$fallback]) ? $fallback : 'nginx';
    }

    public static function previousWebserverLabel(Server $server): string
    {
        $key = self::previousWebserverKey($server);

        return WebserverWorkspaceViewData::webserverCatalog()[$key]['label'] ?? ucfirst($key);
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(Server $server, WorkspaceEdgeProxy $component): array
    {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
        $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;

        $meta = $server->meta ?? [];
        $activeWebserver = strtolower((string) ($meta['webserver'] ?? 'nginx'));
        $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];

        $unitFor = function (string $unit) use ($units): ?array {
            foreach ($units as $u) {
                if (($u['unit'] ?? null) === $unit) {
                    return $u;
                }
            }

            return null;
        };

        $edgeProxyCatalog = self::edgeProxyCatalog();
        $activeEdgeProxy = $server->edgeProxy();
        $edgeProxyPreviousWebserver = self::previousWebserverKey($server);
        $edgeProxyPreviousLabel = self::previousWebserverLabel($server);
        $engineTabCatalog = $edgeProxyCatalog;

        $statePill = fn (?string $active): array => match ($active) {
            'active' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Active')],
            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
            'inactive' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Inactive')],
            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($active ?: 'unknown')],
        };

        $actionTriadFor = fn (string $key): array => match ($key) {
            'traefik' => [['traefik_test_config', false], ['apply_edge_backend_configs', true], ['reload_traefik', true], ['restart_traefik', true]],
            'haproxy' => [['haproxy_test_config', false], ['apply_edge_backend_configs', true], ['reload_haproxy', false], ['restart_haproxy', true]],
            'envoy' => [['envoy_test_config', false], ['apply_edge_backend_configs', true], ['reload_envoy', true], ['restart_envoy', true]],
            default => [],
        };

        $lifecycleGroupsFor = fn (string $key): array => match ($key) {
            'traefik' => [
                'health' => ['label' => __('Health'), 'rows' => [['traefik_test_config', false], ['apply_edge_backend_configs', true]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_traefik', false], ['reload_traefik', true], ['restart_traefik', true], ['stop_traefik', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_traefik', false], ['disable_traefik', true]]],
            ],
            'haproxy' => [
                'health' => ['label' => __('Health'), 'rows' => [['haproxy_test_config', false], ['apply_edge_backend_configs', true]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_haproxy', false], ['reload_haproxy', false], ['restart_haproxy', true], ['stop_haproxy', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_haproxy', false], ['disable_haproxy', true]]],
            ],
            'envoy' => [
                'health' => ['label' => __('Health'), 'rows' => [['envoy_test_config', false], ['apply_edge_backend_configs', true]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_envoy', false], ['reload_envoy', true], ['restart_envoy', true], ['stop_envoy', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_envoy', false], ['disable_envoy', true]]],
            ],
            default => [],
        };

        $cliToolsFor = fn (string $key): array => match ($key) {
            'traefik' => [['traefik_version', false], ['traefik_show_static_config', false], ['traefik_list_dynamic_configs', false]],
            'haproxy' => [['haproxy_version', false], ['haproxy_show_config', false], ['haproxy_show_runtime_info', false]],
            'envoy' => [['envoy_version', false], ['envoy_show_config', false], ['envoy_show_runtime_info', false]],
            default => [],
        };

        $engineHasFullControls = fn (string $key): bool => in_array($key, ['traefik', 'haproxy', 'envoy'], true);

        $iconForAction = fn (string $actionKey): string => match (true) {
            str_contains($actionKey, 'test_config') => 'heroicon-o-shield-check',
            str_contains($actionKey, 'apply_edge_backend') => 'heroicon-o-wrench-screwdriver',
            str_starts_with($actionKey, 'start_') => 'heroicon-o-play',
            str_starts_with($actionKey, 'stop_') => 'heroicon-o-stop',
            str_starts_with($actionKey, 'reload_') => 'heroicon-o-arrow-path',
            str_starts_with($actionKey, 'restart_') => 'heroicon-o-arrow-path-rounded-square',
            str_starts_with($actionKey, 'enable_') => 'heroicon-o-power',
            str_starts_with($actionKey, 'disable_') => 'heroicon-o-no-symbol',
            str_contains($actionKey, '_version') => 'heroicon-o-tag',
            str_contains($actionKey, '_show_config') => 'heroicon-o-document-text',
            str_contains($actionKey, '_show_static_config') => 'heroicon-o-document-text',
            str_contains($actionKey, '_list_dynamic_configs') => 'heroicon-o-list-bullet',
            str_contains($actionKey, '_runtime_info') => 'heroicon-o-cpu-chip',
            default => 'heroicon-o-bolt',
        };

        $groupHeaderFor = fn (string $groupKey): array => match ($groupKey) {
            'health' => ['title' => __('Health'), 'sub' => __('Validate config or rebuild edge routing + site backends')],
            'service' => ['title' => __('Service'), 'sub' => __('Start / stop / reload the daemon')],
            'boot' => ['title' => __('Boot'), 'sub' => __('Whether the daemon auto-starts at server boot')],
            default => ['title' => ucfirst($groupKey), 'sub' => ''],
        };

        $effectiveUnitState = fn (?array $unit, bool $isActiveEngine): array => [
            'active_state' => (string) ($unit['active_state'] ?? ($isActiveEngine ? 'active' : 'inactive')),
            'unit_file_state' => (string) ($unit['unit_file_state'] ?? ($isActiveEngine ? 'enabled' : 'disabled')),
        ];

        $shouldShowAction = fn (string $actionKey, array $state): bool => match (true) {
            str_starts_with($actionKey, 'start_') => $state['active_state'] !== 'active',
            str_starts_with($actionKey, 'stop_') => $state['active_state'] === 'active',
            str_starts_with($actionKey, 'reload_'),
            str_starts_with($actionKey, 'restart_') => $state['active_state'] === 'active',
            str_starts_with($actionKey, 'enable_') => $state['unit_file_state'] !== 'enabled',
            str_starts_with($actionKey, 'disable_') => $state['unit_file_state'] === 'enabled',
            default => true,
        };

        $versionFor = fn (string $key): string => '';

        $consoleActions = app(ServerConsoleActionLookup::class);
        $consoleState = $consoleActions->stateFor($server, 'edge-proxy');

        $webserverBannerRun = $consoleState['banner'];
        $webserverSwitchRun = $consoleState['webserver_switch'];
        $actionInFlight = $webserverBannerRun !== null
            && $webserverBannerRun->isInFlight()
            && ! $webserverBannerRun->isStale();

        $inflightEdgeProxy = $consoleState['inflight_edge_proxy'];
        $edgeProxyActionTarget = $inflightEdgeProxy && $webserverBannerRun?->kind === 'edge_proxy'
            ? (WebserverWorkspaceViewData::consoleActionMeta($webserverBannerRun)['target'] ?? null)
            : null;
        $inflightWebserverSwitch = $consoleState['inflight_webserver_switch'];
        $switchTargetWebserver = WebserverWorkspaceViewData::consoleActionMeta($webserverSwitchRun)['to'] ?? null;

        $activeToolActionOps = $component->activeManageActionOperations();
        $pendingToolActionKey = $component->pendingToolActionKey;

        $showWebserverSwitchConsole = false;

        return array_merge(compact(
            'card',
            'opsReady',
            'isDeployer',
            'meta',
            'activeWebserver',
            'units',
            'unitFor',
            'edgeProxyCatalog',
            'activeEdgeProxy',
            'edgeProxyPreviousWebserver',
            'edgeProxyPreviousLabel',
            'engineTabCatalog',
            'statePill',
            'actionTriadFor',
            'lifecycleGroupsFor',
            'cliToolsFor',
            'engineHasFullControls',
            'iconForAction',
            'groupHeaderFor',
            'effectiveUnitState',
            'shouldShowAction',
            'versionFor',
            'webserverBannerRun',
            'webserverSwitchRun',
            'inflightEdgeProxy',
            'edgeProxyActionTarget',
            'inflightWebserverSwitch',
            'switchTargetWebserver',
            'actionInFlight',
            'activeToolActionOps',
            'pendingToolActionKey',
            'showWebserverSwitchConsole',
        ));
    }
}
