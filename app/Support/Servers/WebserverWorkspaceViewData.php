<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerWebserverAuditEvent;
use App\Services\Servers\WebserverSwitchPreflight;

/**
 * View-model for the server Webserver workspace blade tree. Keeps ~400 lines of
 * catalog/closure setup out of {@see resources/views/livewire/servers/workspace-webserver.blade.php}.
 */
final class WebserverWorkspaceViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Server $server, WorkspaceWebserver $component): array
    {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
        $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;

        $meta = $server->meta ?? [];
        $activeWebserver = strtolower((string) ($meta['webserver'] ?? 'nginx'));
        $nginx = is_array($meta['manage_nginx'] ?? null) ? $meta['manage_nginx'] : [];
        $phpFpm = is_array($meta['manage_php_fpm'] ?? null) ? $meta['manage_php_fpm'] : ['versions' => []];
        $certbot = is_array($meta['manage_certbot'] ?? null) ? $meta['manage_certbot'] : ['present' => false];
        $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];
        $defaultPhp = (string) ($meta['default_php_version'] ?? '8.3');

        $unitFor = function (string $unit) use ($units): ?array {
            foreach ($units as $u) {
                if (($u['unit'] ?? null) === $unit) {
                    return $u;
                }
            }

            return null;
        };

        $nginxVersion = (string) ($nginx['version'] ?? '');
        if ($nginxVersion !== '' && preg_match('#nginx/(\S+)#', $nginxVersion, $vm)) {
            $nginxVersion = $vm[1];
        }

        $webserverCatalog = [
            'nginx' => ['label' => 'nginx', 'icon' => 'heroicon-o-bolt', 'systemd' => 'nginx'],
            'caddy' => ['label' => 'Caddy', 'icon' => 'heroicon-o-shield-check', 'systemd' => 'caddy'],
            'apache' => ['label' => 'Apache', 'icon' => 'heroicon-o-cube', 'systemd' => 'apache2'],
            'openlitespeed' => ['label' => 'OpenLiteSpeed', 'icon' => 'heroicon-o-rocket-launch', 'systemd' => 'lshttpd'],
        ];

        $edgeProxyCatalog = [
            'traefik' => ['label' => 'Traefik', 'icon' => 'heroicon-o-arrow-path-rounded-square', 'systemd' => 'traefik'],
            'haproxy' => ['label' => 'HAProxy', 'icon' => 'heroicon-o-scale', 'systemd' => 'haproxy'],
        ];
        $activeEdgeProxy = $server->edgeProxy();
        $engineTabCatalog = $webserverCatalog;
        if ($activeEdgeProxy !== null && isset($edgeProxyCatalog[$activeEdgeProxy])) {
            $engineTabCatalog[$activeEdgeProxy] = $edgeProxyCatalog[$activeEdgeProxy] + ['is_edge_proxy' => true];
        }

        $certs = self::parseCertbotCerts($certbot);

        $statePill = fn (?string $active): array => match ($active) {
            'active' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Active')],
            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
            'inactive' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Inactive')],
            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($active ?: 'unknown')],
        };

        $actionTriadFor = fn (string $key): array => match ($key) {
            'nginx' => [['nginx_test_config', false], ['reload_nginx', false], ['restart_nginx', true]],
            'caddy' => [['caddy_test_config', false], ['reload_caddy', false], ['restart_caddy', true]],
            'apache' => [['apache_test_config', false], ['reload_apache', false], ['restart_apache', true]],
            'openlitespeed' => [['openlitespeed_test_config', false], ['reload_openlitespeed', false], ['restart_openlitespeed', true]],
            'traefik' => [['traefik_test_config', false], ['reload_traefik', true], ['restart_traefik', true]],
            'haproxy' => [['haproxy_test_config', false], ['reload_haproxy', false], ['restart_haproxy', true]],
            default => [],
        };

        $lifecycleGroupsFor = fn (string $key): array => match ($key) {
            'nginx' => [
                'health' => ['label' => __('Health'), 'rows' => [['nginx_test_config', false]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_nginx', false], ['reload_nginx', false], ['restart_nginx', true], ['stop_nginx', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_nginx', false], ['disable_nginx', true]]],
            ],
            'caddy' => [
                'health' => ['label' => __('Health'), 'rows' => [['caddy_test_config', false]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_caddy', false], ['reload_caddy', false], ['restart_caddy', true], ['stop_caddy', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_caddy', false], ['disable_caddy', true]]],
            ],
            'apache' => [
                'health' => ['label' => __('Health'), 'rows' => [['apache_test_config', false]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_apache', false], ['reload_apache', false], ['restart_apache', true], ['stop_apache', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_apache', false], ['disable_apache', true]]],
            ],
            'openlitespeed' => [
                'health' => ['label' => __('Health'), 'rows' => [['openlitespeed_test_config', false]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_openlitespeed', false], ['reload_openlitespeed', false], ['restart_openlitespeed', true], ['stop_openlitespeed', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_openlitespeed', false], ['disable_openlitespeed', true]]],
            ],
            'traefik' => [
                'health' => ['label' => __('Health'), 'rows' => [['traefik_test_config', false]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_traefik', false], ['reload_traefik', true], ['restart_traefik', true], ['stop_traefik', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_traefik', false], ['disable_traefik', true]]],
            ],
            'haproxy' => [
                'health' => ['label' => __('Health'), 'rows' => [['haproxy_test_config', false]]],
                'service' => ['label' => __('Service'), 'rows' => [['start_haproxy', false], ['reload_haproxy', false], ['restart_haproxy', true], ['stop_haproxy', true]]],
                'boot' => ['label' => __('Boot'), 'rows' => [['enable_haproxy', false], ['disable_haproxy', true]]],
            ],
            default => [],
        };

        $cliToolsFor = fn (string $key): array => match ($key) {
            'nginx' => [['nginx_build_info', false], ['nginx_effective_config', false], ['nginx_reopen_logs', false]],
            'caddy' => [['caddy_version', false], ['caddy_environ', false], ['caddy_list_modules', false], ['caddy_adapt', false], ['caddy_fmt_preview', false], ['caddy_fmt_overwrite', true]],
            'apache' => [['apache_build_info', false], ['apache_modules', false], ['apache_vhosts', false]],
            'openlitespeed' => [['openlitespeed_version', false], ['openlitespeed_modules', false], ['openlitespeed_status', false]],
            'traefik' => [['traefik_version', false], ['traefik_show_static_config', false], ['traefik_list_dynamic_configs', false]],
            'haproxy' => [['haproxy_version', false], ['haproxy_show_config', false], ['haproxy_show_runtime_info', false]],
            default => [],
        };

        $engineHasFullControls = fn (string $key): bool => in_array($key, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy'], true);

        $iconForAction = fn (string $actionKey): string => match (true) {
            str_contains($actionKey, 'test_config') => 'heroicon-o-shield-check',
            str_starts_with($actionKey, 'start_') => 'heroicon-o-play',
            str_starts_with($actionKey, 'stop_') => 'heroicon-o-stop',
            str_starts_with($actionKey, 'reload_') => 'heroicon-o-arrow-path',
            str_starts_with($actionKey, 'restart_') => 'heroicon-o-arrow-path-rounded-square',
            str_starts_with($actionKey, 'enable_') => 'heroicon-o-power',
            str_starts_with($actionKey, 'disable_') => 'heroicon-o-no-symbol',
            str_contains($actionKey, '_version') => 'heroicon-o-tag',
            str_contains($actionKey, '_modules') => 'heroicon-o-puzzle-piece',
            str_contains($actionKey, '_status') => 'heroicon-o-signal',
            str_contains($actionKey, '_show_config') => 'heroicon-o-document-text',
            str_contains($actionKey, '_show_static_config') => 'heroicon-o-document-text',
            str_contains($actionKey, '_list_dynamic_configs') => 'heroicon-o-list-bullet',
            str_contains($actionKey, '_runtime_info') => 'heroicon-o-cpu-chip',
            str_contains($actionKey, '_build_info') => 'heroicon-o-cube',
            str_contains($actionKey, '_vhosts') => 'heroicon-o-server-stack',
            str_contains($actionKey, '_reopen_logs') => 'heroicon-o-document-text',
            str_contains($actionKey, '_environ') => 'heroicon-o-list-bullet',
            str_contains($actionKey, '_effective_config') => 'heroicon-o-document-text',
            str_contains($actionKey, '_adapt') => 'heroicon-o-arrows-right-left',
            str_contains($actionKey, '_fmt') => 'heroicon-o-code-bracket',
            default => 'heroicon-o-bolt',
        };

        $groupHeaderFor = fn (string $groupKey): array => match ($groupKey) {
            'health' => ['title' => __('Health'), 'sub' => __('Validate config before reload')],
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

        $versionFor = fn (string $key): string => match ($key) {
            'nginx' => $nginxVersion,
            default => '',
        };

        $inflightSwitch = $component->hasInflightWebserverSwitch();
        $preflight = app(WebserverSwitchPreflight::class);
        $recentSwitches = ServerWebserverAuditEvent::query()
            ->with('user:id,name')
            ->where('server_id', $server->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $webserverBannerRun = ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->whereIn('kind', ['webserver_switch', 'edge_proxy', 'manage_action'])
            ->whereNull('dismissed_at')
            ->orderByRaw("CASE WHEN status IN ('queued','running') THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->first();

        $webserverSwitchRun = ($webserverBannerRun !== null && $webserverBannerRun->kind === 'webserver_switch')
            ? $webserverBannerRun
            : ConsoleAction::query()
                ->where('subject_type', $server->getMorphClass())
                ->where('subject_id', $server->id)
                ->where('kind', 'webserver_switch')
                ->whereNull('dismissed_at')
                ->orderByDesc('created_at')
                ->first();

        $actionInFlight = $webserverBannerRun !== null
            && $webserverBannerRun->isInFlight()
            && ! $webserverBannerRun->isStale();

        return compact(
            'card',
            'opsReady',
            'isDeployer',
            'meta',
            'activeWebserver',
            'nginx',
            'phpFpm',
            'certbot',
            'units',
            'defaultPhp',
            'unitFor',
            'nginxVersion',
            'webserverCatalog',
            'edgeProxyCatalog',
            'activeEdgeProxy',
            'engineTabCatalog',
            'certs',
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
            'inflightSwitch',
            'preflight',
            'recentSwitches',
            'webserverBannerRun',
            'webserverSwitchRun',
            'actionInFlight',
        );
    }

    /**
     * @param  array<string, mixed>  $certbot
     * @return list<array{name: string, domains: ?string, expiry: ?string, valid: int|null}>
     */
    private static function parseCertbotCerts(array $certbot): array
    {
        $certs = [];
        if (empty($certbot['certs_raw']) || ! is_string($certbot['certs_raw'])) {
            return $certs;
        }

        $name = null;
        $domains = null;
        $expiry = null;
        $valid = null;
        foreach (explode("\n", $certbot['certs_raw']) as $line) {
            $line = trim($line);
            if (preg_match('/^Certificate Name:\s*(.+)$/', $line, $m)) {
                if ($name !== null) {
                    $certs[] = compact('name', 'domains', 'expiry', 'valid');
                }
                $name = $m[1];
                $domains = null;
                $expiry = null;
                $valid = null;
            } elseif (preg_match('/^Domains:\s*(.+)$/', $line, $m)) {
                $domains = $m[1];
            } elseif (preg_match('/^Expiry Date:\s*(.+?)\s*\((INVALID|VALID:\s*([\d.]+)\s*days?)\)/', $line, $m)) {
                $expiry = $m[1];
                $valid = str_starts_with($m[2], 'VALID') ? (int) $m[3] : -1;
            }
        }
        if ($name !== null) {
            $certs[] = compact('name', 'domains', 'expiry', 'valid');
        }

        return $certs;
    }
}
