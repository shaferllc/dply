<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;

/**
 * Explains why mutually-exclusive stack services (webserver, edge proxy) are
 * stopped on a server — e.g. nginx inactive because Caddy is the active webserver.
 */
final class SystemdServiceStandbyReasonResolver
{
    private const GROUP_WEBSERVER = 'webserver';

    private const GROUP_EDGE_PROXY = 'edge_proxy';

    /**
     * @var array<string, array{group: string, engine: string, label: string}>
     */
    private const UNIT_MAP = [
        'nginx' => ['group' => self::GROUP_WEBSERVER, 'engine' => 'nginx', 'label' => 'nginx'],
        'caddy' => ['group' => self::GROUP_WEBSERVER, 'engine' => 'caddy', 'label' => 'Caddy'],
        'apache2' => ['group' => self::GROUP_WEBSERVER, 'engine' => 'apache', 'label' => 'Apache'],
        'lshttpd' => ['group' => self::GROUP_WEBSERVER, 'engine' => 'openlitespeed', 'label' => 'OpenLiteSpeed'],
        'traefik' => ['group' => self::GROUP_EDGE_PROXY, 'engine' => 'traefik', 'label' => 'Traefik'],
        'haproxy' => ['group' => self::GROUP_EDGE_PROXY, 'engine' => 'haproxy', 'label' => 'HAProxy'],
        'envoy' => ['group' => self::GROUP_EDGE_PROXY, 'engine' => 'envoy', 'label' => 'Envoy'],
        'openresty' => ['group' => self::GROUP_EDGE_PROXY, 'engine' => 'openresty', 'label' => 'OpenResty'],
    ];

    /**
     * Human-readable reason when a systemd unit is not running because another
     * engine in the same role is active (post webserver / edge-proxy switch).
     */
    public function reasonForUnit(Server $server, string $unit, ?string $activeState = null): ?string
    {
        if ($this->unitIsRunning($activeState)) {
            return null;
        }

        $meta = $this->unitMeta($unit);
        if ($meta === null) {
            return null;
        }

        $activeEngine = $this->activeEngineForGroup($server, $meta['group']);
        if ($activeEngine === null || $activeEngine === $meta['engine']) {
            return null;
        }

        $activeLabel = $this->labelForEngine($meta['group'], $activeEngine);

        return __('Inactive because :active is the active :role on this server.', [
            'active' => $activeLabel,
            'role' => $this->roleLabel($meta['group']),
        ]);
    }

    /**
     * Copy for webserver / edge-proxy workspace tabs when the engine is not active.
     */
    public function inactiveEngineHint(Server $server, string $engineKey, bool $isEdgeProxyPanel): ?string
    {
        $group = $isEdgeProxyPanel ? self::GROUP_EDGE_PROXY : self::GROUP_WEBSERVER;
        $activeEngine = $this->activeEngineForGroup($server, $group);
        if ($activeEngine === null || $activeEngine === $engineKey) {
            return null;
        }

        $activeLabel = $this->labelForEngine($group, $activeEngine);
        $engineLabel = $this->labelForEngine($group, $engineKey);

        return __(':active is the active :role — :engine stays stopped after the last switch.', [
            'active' => $activeLabel,
            'engine' => $engineLabel,
            'role' => $this->roleLabel($group),
        ]);
    }

    private function unitIsRunning(?string $activeState): bool
    {
        $state = strtolower(trim((string) $activeState));

        return in_array($state, ['active', 'activating', 'reloading'], true);
    }

    /**
     * @return array{group: string, engine: string, label: string}|null
     */
    private function unitMeta(string $unit): ?array
    {
        $base = strtolower((string) preg_replace('/\.service$/i', '', trim($unit)));

        return self::UNIT_MAP[$base] ?? null;
    }

    private function activeEngineForGroup(Server $server, string $group): ?string
    {
        if ($group === self::GROUP_WEBSERVER) {
            $active = strtolower(trim((string) (($server->meta ?? [])['webserver'] ?? 'nginx')));

            return $active !== '' ? $active : null;
        }

        if ($group === self::GROUP_EDGE_PROXY) {
            $proxy = $server->edgeProxy();
            if ($proxy === null || $proxy === '') {
                return null;
            }

            return strtolower($proxy);
        }

        return null;
    }

    private function labelForEngine(string $group, string $engineKey): string
    {
        if ($group === self::GROUP_WEBSERVER) {
            $catalog = WebserverWorkspaceViewData::webserverCatalog();
            if (isset($catalog[$engineKey]['label'])) {
                return (string) $catalog[$engineKey]['label'];
            }
        }

        if ($group === self::GROUP_EDGE_PROXY) {
            $catalog = EdgeProxyWorkspaceViewData::edgeProxyCatalog();
            if (isset($catalog[$engineKey]['label'])) {
                return (string) $catalog[$engineKey]['label'];
            }
        }

        foreach (self::UNIT_MAP as $meta) {
            if ($meta['group'] === $group && $meta['engine'] === $engineKey) {
                return $meta['label'];
            }
        }

        return ucfirst($engineKey);
    }

    private function roleLabel(string $group): string
    {
        return $group === self::GROUP_EDGE_PROXY
            ? __('edge proxy')
            : __('webserver');
    }
}
