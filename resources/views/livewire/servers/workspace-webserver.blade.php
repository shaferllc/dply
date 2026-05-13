@php
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

    $unitFor = function (string $unit) use ($units) {
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

    // L7 edge proxies are a separate concept — they sit IN FRONT of the
    // active webserver (with Caddy as per-site backend). Picker lives in
    // its own section below the webserver picker; the management tab
    // (Overview/Tools/Logs/Config/Info) for the ACTIVE edge proxy only
    // shows up in the top tab strip when one is set.
    $edgeProxyCatalog = [
        'traefik' => ['label' => 'Traefik', 'icon' => 'heroicon-o-arrow-path-rounded-square', 'systemd' => 'traefik'],
        'haproxy' => ['label' => 'HAProxy', 'icon' => 'heroicon-o-scale', 'systemd' => 'haproxy'],
    ];
    $activeEdgeProxy = $server->edgeProxy();
    // Combined list driving the top tab strip + per-engine panel loop.
    // Webservers always render their tab; the active edge proxy (if any)
    // also gets a tab so the operator can manage it. is_edge_proxy steers
    // the panel renderer to skip the Switch CTA and use Add/Remove copy.
    $engineTabCatalog = $webserverCatalog;
    if ($activeEdgeProxy !== null && isset($edgeProxyCatalog[$activeEdgeProxy])) {
        $engineTabCatalog[$activeEdgeProxy] = $edgeProxyCatalog[$activeEdgeProxy] + ['is_edge_proxy' => true];
    }

    // Parse certbot output for the Advanced tab table (regex best-effort).
    $certs = [];
    if (! empty($certbot['certs_raw']) && is_string($certbot['certs_raw'])) {
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
    }

    $statePill = function (?string $active): array {
        return match ($active) {
            'active' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Active')],
            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
            'inactive' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Inactive')],
            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($active ?: 'unknown')],
        };
    };

    $actionTriadFor = function (string $key): array {
        return match ($key) {
            'nginx' => [['nginx_test_config', false], ['reload_nginx', false], ['restart_nginx', true]],
            'caddy' => [['caddy_test_config', false], ['reload_caddy', false], ['restart_caddy', true]],
            'apache' => [['apache_test_config', false], ['reload_apache', false], ['restart_apache', true]],
            'openlitespeed' => [['openlitespeed_test_config', false], ['reload_openlitespeed', false], ['restart_openlitespeed', true]],
            'traefik' => [['traefik_test_config', false], ['reload_traefik', true], ['restart_traefik', true]],
            'haproxy' => [['haproxy_test_config', false], ['reload_haproxy', false], ['restart_haproxy', true]],
            default => [],
        };
    };

    /**
     * Per-engine lifecycle button groups, rendered on the engine Overview
     * sub-tab. Each group becomes its own row in the action grid with a
     * short label, so start/stop/enable/disable don't visually merge with
     * the test/reload/restart "health" actions.
     *
     * Tuple shape: [action_key, dangerous?]
     */
    $lifecycleGroupsFor = function (string $key): array {
        return match ($key) {
            'nginx' => [
                'health' => [
                    'label' => __('Health'),
                    'rows' => [['nginx_test_config', false]],
                ],
                'service' => [
                    'label' => __('Service'),
                    'rows' => [
                        ['start_nginx', false],
                        ['reload_nginx', false],
                        ['restart_nginx', true],
                        ['stop_nginx', true],
                    ],
                ],
                'boot' => [
                    'label' => __('Boot'),
                    'rows' => [
                        ['enable_nginx', false],
                        ['disable_nginx', true],
                    ],
                ],
            ],
            'caddy' => [
                'health' => [
                    'label' => __('Health'),
                    'rows' => [['caddy_test_config', false]],
                ],
                'service' => [
                    'label' => __('Service'),
                    'rows' => [
                        ['start_caddy', false],
                        ['reload_caddy', false],
                        ['restart_caddy', true],
                        ['stop_caddy', true],
                    ],
                ],
                'boot' => [
                    'label' => __('Boot'),
                    'rows' => [
                        ['enable_caddy', false],
                        ['disable_caddy', true],
                    ],
                ],
            ],
            'apache' => [
                'health' => [
                    'label' => __('Health'),
                    'rows' => [['apache_test_config', false]],
                ],
                'service' => [
                    'label' => __('Service'),
                    'rows' => [
                        ['start_apache', false],
                        ['reload_apache', false],
                        ['restart_apache', true],
                        ['stop_apache', true],
                    ],
                ],
                'boot' => [
                    'label' => __('Boot'),
                    'rows' => [
                        ['enable_apache', false],
                        ['disable_apache', true],
                    ],
                ],
            ],
            'openlitespeed' => [
                'health' => [
                    'label' => __('Health'),
                    'rows' => [['openlitespeed_test_config', false]],
                ],
                'service' => [
                    'label' => __('Service'),
                    'rows' => [
                        ['start_openlitespeed', false],
                        ['reload_openlitespeed', false],
                        ['restart_openlitespeed', true],
                        ['stop_openlitespeed', true],
                    ],
                ],
                'boot' => [
                    'label' => __('Boot'),
                    'rows' => [
                        ['enable_openlitespeed', false],
                        ['disable_openlitespeed', true],
                    ],
                ],
            ],
            'traefik' => [
                'health' => [
                    'label' => __('Health'),
                    'rows' => [['traefik_test_config', false]],
                ],
                'service' => [
                    'label' => __('Service'),
                    'rows' => [
                        ['start_traefik', false],
                        ['reload_traefik', true],
                        ['restart_traefik', true],
                        ['stop_traefik', true],
                    ],
                ],
                'boot' => [
                    'label' => __('Boot'),
                    'rows' => [
                        ['enable_traefik', false],
                        ['disable_traefik', true],
                    ],
                ],
            ],
            'haproxy' => [
                'health' => [
                    'label' => __('Health'),
                    'rows' => [['haproxy_test_config', false]],
                ],
                'service' => [
                    'label' => __('Service'),
                    'rows' => [
                        ['start_haproxy', false],
                        ['reload_haproxy', false],
                        ['restart_haproxy', true],
                        ['stop_haproxy', true],
                    ],
                ],
                'boot' => [
                    'label' => __('Boot'),
                    'rows' => [
                        ['enable_haproxy', false],
                        ['disable_haproxy', true],
                    ],
                ],
            ],
            default => [],
        };
    };

    /** Per-engine CLI / diagnostic buttons rendered on the Tools sub-tab. */
    $cliToolsFor = function (string $key): array {
        return match ($key) {
            'nginx' => [
                ['nginx_build_info', false],
                ['nginx_effective_config', false],
                ['nginx_reopen_logs', false],
            ],
            'caddy' => [
                ['caddy_version', false],
                ['caddy_environ', false],
                ['caddy_list_modules', false],
                ['caddy_adapt', false],
                ['caddy_fmt_preview', false],
                ['caddy_fmt_overwrite', true],
            ],
            'apache' => [
                ['apache_build_info', false],
                ['apache_modules', false],
                ['apache_vhosts', false],
            ],
            'openlitespeed' => [
                ['openlitespeed_version', false],
                ['openlitespeed_modules', false],
                ['openlitespeed_status', false],
            ],
            'traefik' => [
                ['traefik_version', false],
                ['traefik_show_static_config', false],
                ['traefik_list_dynamic_configs', false],
            ],
            'haproxy' => [
                ['haproxy_version', false],
                ['haproxy_show_config', false],
                ['haproxy_show_runtime_info', false],
            ],
            default => [],
        };
    };

    /** True when the engine has its own config editor / logs / tools surface. */
    $engineHasFullControls = fn (string $key): bool => in_array($key, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy'], true);

    /**
     * Map an action key to a semantic heroicon — better than every button
     * sharing the same lightning bolt. Falls back to bolt for unknowns.
     */
    $iconForAction = function (string $actionKey): string {
        return match (true) {
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
    };

    /**
     * Group label → header copy. Slightly richer than the raw label so the
     * card header reads as guidance rather than a form caption.
     */
    $groupHeaderFor = function (string $groupKey): array {
        return match ($groupKey) {
            'health' => ['title' => __('Health'), 'sub' => __('Validate config before reload')],
            'service' => ['title' => __('Service'), 'sub' => __('Start / stop / reload the daemon')],
            'boot' => ['title' => __('Boot'), 'sub' => __('Whether the daemon auto-starts at server boot')],
            default => ['title' => ucfirst($groupKey), 'sub' => ''],
        };
    };

    /**
     * Derive an "effective" unit state — uses real values from
     * meta.manage_units when present, but falls back to sensible defaults
     * keyed by whether dply considers this engine the active one. The
     * fallback matters because the systemd inventory probe doesn't always
     * re-run after a webserver switch / edge-proxy add — so the cache may
     * lack the new engine's row entirely. Without this default the lifecycle
     * panel ended up showing Start AND Stop together for the engine the
     * operator was clearly managing.
     */
    $effectiveUnitState = function (?array $unit, bool $isActiveEngine): array {
        return [
            'active_state' => (string) ($unit['active_state'] ?? ($isActiveEngine ? 'active' : 'inactive')),
            'unit_file_state' => (string) ($unit['unit_file_state'] ?? ($isActiveEngine ? 'enabled' : 'disabled')),
        ];
    };

    /**
     * Filter lifecycle actions against the daemon's effective state so we
     * don't show both Start AND Stop (or Enable AND Disable) at the same
     * time. Reload/Restart require an active daemon (meaningless on a
     * stopped one — Start it instead). Enable/Disable mirror unit_file_state.
     *
     * Health + Tools always pass through — Test-config is THE point of
     * having it when the daemon is stopped, before you start it.
     */
    $shouldShowAction = function (string $actionKey, array $state): bool {
        $isActive = $state['active_state'] === 'active';
        $isEnabled = $state['unit_file_state'] === 'enabled';

        return match (true) {
            str_starts_with($actionKey, 'start_') => ! $isActive,
            str_starts_with($actionKey, 'stop_') => $isActive,
            str_starts_with($actionKey, 'reload_'),
            str_starts_with($actionKey, 'restart_') => $isActive,
            str_starts_with($actionKey, 'enable_') => ! $isEnabled,
            str_starts_with($actionKey, 'disable_') => $isEnabled,
            default => true,
        };
    };

    $versionFor = function (string $key) use ($nginxVersion): string {
        return match ($key) {
            'nginx' => $nginxVersion,
            default => '',
        };
    };

    $inflightSwitch = $this->hasInflightWebserverSwitch();
    $preflight = app(\App\Services\Servers\WebserverSwitchPreflight::class);
    $recentSwitches = \App\Models\ServerWebserverAuditEvent::query()
        ->with('user:id,name')
        ->where('server_id', $server->id)
        ->orderByDesc('created_at')
        ->limit(5)
        ->get();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="webserver"
    :title="__('Webserver')"
    :description="__('Pick which webserver runs on this box. Switching reprovisions all sites under the new daemon, then service-swaps to :80.')"
>
    {{-- Output for runAllowlistedAction lands in the manage_action
         ConsoleAction banner rendered below (same partial the webserver_switch
         flow uses). Pass null to the legacy flash partial to keep it dormant. --}}
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($isDeployer)
        <div class="mb-4 rounded-2xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
            {{ __('Deployers can view this page but cannot run SSH actions or switch the webserver.') }}
        </div>
    @endif

    @if (! $opsReady)
        <div class="mb-4 rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before webserver actions or switching can run.') }}
        </div>
    @endif

    @php
        // Single banner across both kinds — an in-flight run always wins (so a
        // fresh action's progress isn't hidden behind a stale completed row),
        // otherwise the most-recently-created non-dismissed row shows. Once
        // the operator dismisses it, the next-most-recent surfaces on the
        // next render.
        $webserverBannerRun = \App\Models\ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->whereIn('kind', ['webserver_switch', 'edge_proxy', 'manage_action'])
            ->whereNull('dismissed_at')
            ->orderByRaw("CASE WHEN status IN ('queued','running') THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->first();
        // Stop-and-revert affordance below only fires for the switch flow.
        // Reuse the unified row when it happens to be the switch, otherwise
        // do a small targeted lookup so the revert button stays available
        // even if a non-switch manage_action is currently in the banner slot.
        $webserverSwitchRun = ($webserverBannerRun !== null && $webserverBannerRun->kind === 'webserver_switch')
            ? $webserverBannerRun
            : \App\Models\ConsoleAction::query()
                ->where('subject_type', $server->getMorphClass())
                ->where('subject_id', $server->id)
                ->where('kind', 'webserver_switch')
                ->whereNull('dismissed_at')
                ->orderByDesc('created_at')
                ->first();
        // Single source of truth for "an action is in flight on this server".
        // We disable every action button (lifecycle + tools + switch + edge
        // proxy add/remove) while this is true so a fast double-click can't
        // queue a second manage_action on top of a running one.
        $actionInFlight = $webserverBannerRun !== null
            && $webserverBannerRun->isInFlight()
            && ! $webserverBannerRun->isStale();
    @endphp
    @include('livewire.partials.console-action-banner-static', [
        'run' => $webserverBannerRun,
        'kindLabels' => (array) config('console_actions.kinds', []),
    ])

    {{-- Operator escape hatch when the switch banner is stuck. Visible whenever
         a webserver_switch row is still in-flight (queued/running, including
         past the staleness threshold) — clicking "Stop & revert" opens the
         confirm-action modal which then calls stopAndRevertWebserverSwitch().
         Mirrors {@see WorkspaceManage::stopAndRevertWebserverSwitch()}. --}}
    @if ($webserverSwitchRun !== null && $webserverSwitchRun->isInFlight() && ! $isDeployer && $opsReady)
        @php
            $revertConfirmTitle = __('Stop and revert webserver switch?');
            $revertConfirmBody = __('This marks the in-flight switch as failed and runs a best-effort cleanup on the server: stop the partial daemon, apt-get remove the new package, drop its repo file, and restart the original webserver. Use this when the install has stalled.');
            $revertConfirmCta = __('Stop & revert');
        @endphp
        <div class="mb-4 -mt-1 flex justify-end">
            <button
                type="button"
                wire:click="openConfirmActionModal('stopAndRevertWebserverSwitch', ['{{ $webserverSwitchRun->id }}'], @js($revertConfirmTitle), @js($revertConfirmBody), @js($revertConfirmCta), true)"
                class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-50"
            >
                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                {{ __('Stop & revert') }}
            </button>
        </div>
    @endif

    {{-- Tab strip — mirrors WorkspaceDatabases / WorkspaceCaches. The active
         webserver gets a green "Active" badge in its tab; the rest are
         reachable for switching even when not installed. --}}
    <x-server-workspace-tablist :aria-label="__('Webserver workspace sections')">
        <x-server-workspace-tab
            id="ws-tab-overview"
            :active="$workspace_tab === 'overview'"
            wire:click="setWorkspaceTab('overview')"
            icon="heroicon-o-bolt"
        >
            {{ __('Overview') }}
        </x-server-workspace-tab>
        @foreach ($engineTabCatalog as $key => $info)
            @php
                $isEdgeProxyTab = ! empty($info['is_edge_proxy']);
                $isActiveEngine = $isEdgeProxyTab
                    ? $key === $activeEdgeProxy
                    : $key === $activeWebserver;
            @endphp
            <x-server-workspace-tab
                :id="'ws-tab-'.$key"
                :active="$workspace_tab === $key"
                wire:click="setWorkspaceTab('{{ $key }}')"
                :icon="$info['icon']"
            >
                {{ $info['label'] }}
                @if ($isActiveEngine)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ $isEdgeProxyTab ? __('Edge') : __('Active') }}</span>
                @elseif (! $isEdgeProxyTab && $preflight->isBlocked($server, $key))
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{{ __('Unavailable') }}</span>
                @endif
            </x-server-workspace-tab>
        @endforeach
        <x-server-workspace-tab
            id="ws-tab-advanced"
            :active="$workspace_tab === 'advanced'"
            wire:click="setWorkspaceTab('advanced')"
            icon="heroicon-o-wrench-screwdriver"
        >
            {{ __('Advanced') }}
        </x-server-workspace-tab>
    </x-server-workspace-tablist>

    <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setWorkspaceTab">

    {{-- =====================================================================
         OVERVIEW — active webserver card + alternatives grid.
         ===================================================================== --}}
    <x-server-workspace-tab-panel
        id="ws-panel-overview"
        labelled-by="ws-tab-overview"
        :hidden="$workspace_tab !== 'overview'"
        panel-class="space-y-6"
    >
        @php
            $activeInfo = $webserverCatalog[$activeWebserver] ?? null;
            $activeUnit = $activeInfo !== null ? $unitFor($activeInfo['systemd']) : null;
            $activePill = $statePill($activeUnit['active_state'] ?? null);
            $activeVersion = $versionFor($activeWebserver);
            $activeLifecycleGroups = $lifecycleGroupsFor($activeWebserver);
            $activeCliTools = $cliToolsFor($activeWebserver);
        @endphp

        @if ($activeInfo !== null)
            <div class="{{ $card }} overflow-hidden">
                {{-- Engine header — icon + label + version + status pill, all
                     more prominent than the old inline arrangement. --}}
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-forest/10">
                            <x-dynamic-component :component="$activeInfo['icon']" class="h-5 w-5 text-brand-forest" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ $activeInfo['label'] }}</h3>
                            @if ($activeVersion !== '')
                                <p class="font-mono text-[11px] text-brand-mist">{{ $activeVersion }}</p>
                            @endif
                        </div>
                    </div>
                    @if ($activeUnit !== null)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-medium ring-1 ring-brand-ink/10 {{ $activePill['classes'] }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $activePill['dot'] }}"></span>
                            {{ $activePill['label'] }}
                        </span>
                    @endif
                </div>

                @if ($opsReady && ! $isDeployer && ! empty($activeLifecycleGroups))
                    {{-- Lifecycle action groups in sub-cards. Each group gets
                         a header + sub-line + a row of semantic-icon buttons.
                         Stop/Disable/Restart get a danger ring rather than a
                         red border so they read as "still-an-action" but flagged.
                         State-aware filter hides Start when running and Stop
                         when stopped (and similarly for enable/disable) so we
                         never show both at once. --}}
                    <div class="grid gap-px bg-brand-ink/5 sm:grid-cols-1">
                        @php
                            // Operator is on the Overview tab — by definition the
                            // engine we're rendering controls for is the active one.
                            $effectiveState = $effectiveUnitState($activeUnit, true);
                        @endphp
                        @foreach ($activeLifecycleGroups as $groupKey => $group)
                            @php
                                $header = $groupHeaderFor($groupKey);
                                $visibleRows = array_values(array_filter(
                                    $group['rows'],
                                    fn ($pair) => $shouldShowAction($pair[0], $effectiveState),
                                ));
                            @endphp
                            @if (! empty($visibleRows))
                            <div class="bg-white px-6 py-4 sm:px-8">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ $header['title'] }}</p>
                                        @if ($header['sub'] !== '')
                                            <p class="mt-0.5 text-[12px] text-brand-mist">{{ $header['sub'] }}</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($visibleRows as [$actionKey, $dangerous])
                                            @if (! empty($serviceActions[$actionKey]))
                                                @php $action = $serviceActions[$actionKey]; @endphp
                                                <button
                                                    type="button"
                                                    @if ($dangerous) wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), true)" @else wire:click="runAllowlistedAction('{{ $actionKey }}')" @endif
                                                    wire:loading.attr="disabled"
                                                    wire:target="openConfirmActionModal,runAllowlistedAction"
                                                    @disabled($actionInFlight)
                                                    title="{{ $actionInFlight ? __('Another action is running — wait for it to finish.') : ($action['description'] ?? '') }}"
                                                    @class([
                                                        'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-60',
                                                        'border-brand-ink/15 bg-white text-brand-ink shadow-sm hover:bg-brand-sand/40' => ! $dangerous,
                                                        'border-rose-200 bg-rose-50/30 text-rose-800 hover:bg-rose-50' => $dangerous,
                                                    ])
                                                >
                                                    <x-dynamic-component :component="$iconForAction($actionKey)" class="h-3.5 w-3.5 opacity-80" aria-hidden="true" />
                                                    {{ $action['label'] }}
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @endif
                        @endforeach

                        @if (! empty($activeCliTools))
                            {{-- Tools row — read-only diagnostics. Visually
                                 quieter than the lifecycle rows above (the buttons
                                 lose their drop shadow + sit in a tinted bg) so it
                                 doesn't compete with the lifecycle group hierarchy. --}}
                            <div class="bg-brand-sand/15 px-6 py-4 sm:px-8">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Tools') }}</p>
                                        <p class="mt-0.5 text-[12px] text-brand-mist">{{ __('Read-only diagnostics — version, config dumps, module list, etc.') }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($activeCliTools as [$actionKey, $dangerous])
                                            @if (! empty($serviceActions[$actionKey]))
                                                @php $action = $serviceActions[$actionKey]; @endphp
                                                <button
                                                    type="button"
                                                    @if ($dangerous) wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), true)" @else wire:click="runAllowlistedAction('{{ $actionKey }}')" @endif
                                                    wire:loading.attr="disabled"
                                                    wire:target="openConfirmActionModal,runAllowlistedAction"
                                                    @disabled($actionInFlight)
                                                    title="{{ $actionInFlight ? __('Another action is running — wait for it to finish.') : ($action['description'] ?? '') }}"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-white disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    <x-dynamic-component :component="$iconForAction($actionKey)" class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
                                                    {{ $action['label'] }}
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <div class="{{ $card }} p-6 sm:p-8">
            <div class="max-w-2xl">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Switch webserver') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('One webserver per box. Switching reprovisions all sites under the new webserver — parallel install on :8080, then a brief service-swap to :80 (under 1 second blip).') }}
                </p>
            </div>

            @if ($inflightSwitch)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                    {{ __('A webserver switch is currently running. Switch buttons are disabled until it settles — watch the progress banner at the top of this page.') }}
                </div>
            @endif

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ($webserverCatalog as $key => $info)
                    @continue($key === $activeWebserver)
                    @php $isBlocked = $preflight->isBlocked($server, $key); @endphp
                    <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                        <div class="flex items-start gap-2">
                            <x-dynamic-component :component="$info['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-brand-forest" />
                            <p class="min-w-0 font-semibold text-brand-ink">{{ $info['label'] }}</p>
                        </div>

                        @if ($inflightSwitch)
                            <div class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-3 py-1.5 text-xs font-semibold text-brand-mist">
                                <x-spinner variant="forest" size="sm" />
                                <span>{{ __('Switching in progress…') }}</span>
                            </div>
                        @else
                            <button
                                type="button"
                                wire:click="openSwitchWebserver('{{ $key }}')"
                                wire:loading.attr="disabled"
                                wire:target="openSwitchWebserver"
                                @disabled($isDeployer || ! $opsReady || $isBlocked || $actionInFlight)
                                @class([
                                    'mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition disabled:opacity-60',
                                    'bg-brand-forest text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90' => ! $isBlocked,
                                    'cursor-not-allowed bg-brand-sand/40 text-brand-mist' => $isBlocked,
                                ])
                                title="{{ $isBlocked ? __('Unavailable — see preflight blocker') : '' }}"
                            >
                                <span wire:loading.remove wire:target="openSwitchWebserver" class="inline-flex">
                                    @if ($isBlocked)
                                        <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
                                    @else
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                    @endif
                                </span>
                                <span wire:loading wire:target="openSwitchWebserver" class="inline-flex">
                                    <x-spinner variant="cream" size="sm" />
                                </span>
                                @if ($isBlocked)
                                    {{ __('Unavailable') }}
                                @else
                                    {{ __('Switch to :name', ['name' => $info['label']]) }}
                                @endif
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- =====================================================================
             EDGE PROXY — separate concept from the webserver. Lives IN FRONT
             of whatever's serving :80, with Caddy as the per-site backend on
             ephemeral high ports. Mutually exclusive with caddy/nginx/apache/
             OLS serving :80 directly. Only one edge proxy can be active.
             ===================================================================== --}}
        <div class="{{ $card }} mt-6 p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Edge proxy') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('Optional L7 reverse proxy in front of your webserver. Caddy serves each site on an ephemeral high port; the edge proxy routes hosts to those backends on :80. Pick this when you want host-based load balancing, ACL routing, or sit-on-top of an existing webserver pattern.') }}
                    </p>
                </div>
                @if ($activeEdgeProxy !== null)
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[11px] font-medium text-brand-forest">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ $edgeProxyCatalog[$activeEdgeProxy]['label'] }} {{ __('active') }}
                    </span>
                @endif
            </div>

            @php $inflightEdge = $this->hasInflightEdgeProxyAction(); @endphp
            @if ($inflightEdge)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                    {{ __('An edge proxy action is currently running. Buttons are disabled until it settles — watch the progress banner at the top of this page.') }}
                </div>
            @endif

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ($edgeProxyCatalog as $key => $info)
                    @php $isActiveEdge = $key === $activeEdgeProxy; @endphp
                    <div @class([
                        'rounded-xl border bg-white p-4',
                        'border-brand-forest/30 ring-1 ring-brand-forest/20' => $isActiveEdge,
                        'border-brand-ink/10' => ! $isActiveEdge,
                    ])>
                        <div class="flex items-start gap-2">
                            <x-dynamic-component :component="$info['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-brand-forest" />
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-brand-ink">{{ $info['label'] }}</p>
                                @if ($isActiveEdge)
                                    <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('Routing traffic on :80') }}</p>
                                @endif
                            </div>
                        </div>

                        @if ($isActiveEdge)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removeEdgeProxy', [], @js(__('Remove edge proxy')), @js(__('Remove the :name edge proxy? Caddy will resume serving :80 directly.', ['name' => $info['label']])), @js(__('Remove')), true)"
                                @disabled($isDeployer || ! $opsReady || $inflightEdge || $actionInFlight)
                                class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                            >
                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                {{ __('Remove :name', ['name' => $info['label']]) }}
                            </button>
                        @elseif ($activeEdgeProxy !== null)
                            <button
                                type="button"
                                @disabled(true)
                                class="mt-3 inline-flex w-full cursor-not-allowed items-center justify-center gap-1.5 rounded-lg bg-brand-sand/40 px-3 py-1.5 text-xs font-semibold text-brand-mist"
                                title="{{ __('Remove the active edge proxy before switching to another.') }}"
                            >
                                <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
                                {{ __('Unavailable — remove :other first', ['other' => $edgeProxyCatalog[$activeEdgeProxy]['label']]) }}
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('addEdgeProxy', ['{{ $key }}'], @js(__('Add :name edge proxy', ['name' => $info['label']])), @js(__('Install :name in front of the webserver? Caddy will be installed as the per-site backend; your current webserver (:active) will be stopped.', ['name' => $info['label'], 'active' => $activeWebserver])), @js(__('Add :name', ['name' => $info['label']])), false)"
                                @disabled($isDeployer || ! $opsReady || $inflightEdge || $inflightSwitch || $actionInFlight)
                                class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-up-tray class="h-3.5 w-3.5" />
                                {{ __('Add :name', ['name' => $info['label']]) }}
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </x-server-workspace-tab-panel>

    {{-- =====================================================================
         PER-WEBSERVER TABS — one panel per catalog entry. Active webserver
         tab shows the same shape as the Overview active card (state + actions);
         non-active tabs show a brief "not installed / not in use" panel and a
         Switch CTA (with preflight-blocker reason if applicable).
         ===================================================================== --}}
    @foreach ($engineTabCatalog as $key => $info)
        @php
            $isEdgeProxyPanel = ! empty($info['is_edge_proxy']);
            $isActive = $isEdgeProxyPanel
                ? $key === $activeEdgeProxy
                : $key === $activeWebserver;
            $unit = $unitFor($info['systemd']);
            $pill = $statePill($unit['active_state'] ?? null);
            $version = $versionFor($key);
            $actionTriad = $actionTriadFor($key);
            // Edge proxies don't appear in the preflight blocker matrix
            // (that's webserver-switch concept). Skip the blocker resolve
            // for them so we don't try to "switch to" an edge proxy.
            $isBlocked = ! $isEdgeProxyPanel && ! $isActive && $preflight->isBlocked($server, $key);
            $blockerReason = $isBlocked ? $preflight->plan($server, $key)['blocker']['label'] ?? null : null;
        @endphp

        <x-server-workspace-tab-panel
            :id="'ws-panel-'.$key"
            :labelled-by="'ws-tab-'.$key"
            :hidden="$workspace_tab !== $key"
            panel-class="space-y-6"
        >
            {{-- Per-engine sub-tab strip — Overview (state + lifecycle buttons),
                 Tools (CLI diagnostics like `caddy fmt`/`nginx -T`/`apachectl -M`),
                 Logs (live tail of access + error), Config (in-app editor with
                 validate / save / backup / restore), Info (description, license,
                 docs links). Tools/Logs/Config are only shown for active engines
                 with full controls (nginx / caddy / apache) since the others
                 don't yet have backing config layouts. --}}
            @php $hasControls = $isActive && $engineHasFullControls($key); @endphp
            <x-server-workspace-tablist :aria-label="__(':engine workspace sections', ['engine' => $info['label']])">
                <x-server-workspace-tab
                    :id="'ws-subtab-'.$key.'-overview'"
                    :active="$engine_subtab === 'overview'"
                    wire:click="setEngineSubtab('overview')"
                    icon="heroicon-o-presentation-chart-line"
                >
                    {{ __('Overview') }}
                </x-server-workspace-tab>
                @if ($hasControls)
                    {{-- Tools sub-tab removed: the same per-engine diagnostic
                         buttons (version, modules, status, etc.) now live in
                         the Overview panel's Tools row, so the dedicated tab
                         was duplicate UI. --}}
                    <x-server-workspace-tab
                        :id="'ws-subtab-'.$key.'-logs'"
                        :active="$engine_subtab === 'logs'"
                        wire:click="setEngineSubtab('logs')"
                        icon="heroicon-o-document-text"
                    >
                        {{ __('Logs') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        :id="'ws-subtab-'.$key.'-config'"
                        :active="$engine_subtab === 'config'"
                        wire:click="setEngineSubtab('config')"
                        icon="heroicon-o-pencil-square"
                    >
                        {{ __('Config') }}
                    </x-server-workspace-tab>
                @endif
                {{-- Per-engine live-state sub-tabs. Only shown for the
                     active engine. OLS shipped in v1; nginx/Caddy/Apache/
                     Traefik/HAProxy land their tabs as their probes
                     come online (the setEngineSubtab() allow-list already
                     accepts all of them so URL deep-links don't break). --}}
                @php
                    // Per-engine live-state sub-tab strips. Each engine that
                    // has a probe contributes its own ordered list of sub-tabs.
                    // Engines without probes (caddy/nginx/apache for now) get
                    // the legacy default strip (Overview/Tools/Logs/Config/Info).
                    $liveStateSubTabs = match ($key) {
                        'openlitespeed' => [
                            'vhosts' => ['label' => __('Vhosts'), 'icon' => 'heroicon-o-server-stack'],
                            'listeners' => ['label' => __('Listeners'), 'icon' => 'heroicon-o-signal'],
                            'extapps' => ['label' => __('ExtApps'), 'icon' => 'heroicon-o-cpu-chip'],
                            'cache' => ['label' => __('Cache'), 'icon' => 'heroicon-o-bolt'],
                        ],
                        'traefik' => [
                            'routers' => ['label' => __('Routers'), 'icon' => 'heroicon-o-arrow-path-rounded-square'],
                            'services' => ['label' => __('Services'), 'icon' => 'heroicon-o-server'],
                            'middlewares' => ['label' => __('Middlewares'), 'icon' => 'heroicon-o-shield-check'],
                            'providers' => ['label' => __('Providers'), 'icon' => 'heroicon-o-cube'],
                        ],
                        'haproxy' => [
                            'frontends' => ['label' => __('Frontends'), 'icon' => 'heroicon-o-arrow-path-rounded-square'],
                            'backends' => ['label' => __('Backends'), 'icon' => 'heroicon-o-server-stack'],
                            'ssl' => ['label' => __('SSL'), 'icon' => 'heroicon-o-lock-closed'],
                            'runtime' => ['label' => __('Runtime'), 'icon' => 'heroicon-o-cpu-chip'],
                        ],
                        default => [],
                    };
                @endphp
                @if ($isActive && $liveStateSubTabs !== [])
                    @foreach ($liveStateSubTabs as $stKey => $stInfo)
                        <x-server-workspace-tab
                            :id="'ws-subtab-'.$key.'-'.$stKey"
                            :active="$engine_subtab === $stKey"
                            wire:click="setEngineSubtab('{{ $stKey }}')"
                            :icon="$stInfo['icon']"
                        >
                            {{ $stInfo['label'] }}
                        </x-server-workspace-tab>
                    @endforeach
                @endif
                <x-server-workspace-tab
                    :id="'ws-subtab-'.$key.'-info'"
                    :active="$engine_subtab === 'info'"
                    wire:click="setEngineSubtab('info')"
                    icon="heroicon-o-information-circle"
                >
                    {{ __('Info') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            @if ($engine_subtab === 'overview')
            <div class="{{ $card }} overflow-hidden">
                {{-- Header — engine icon + name + version + status pill.
                     Matches the redesigned Overview-tab panel for consistency. --}}
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-forest/10">
                            <x-dynamic-component :component="$info['icon']" class="h-5 w-5 text-brand-forest" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ $info['label'] }}</h3>
                            @if ($version !== '')
                                <p class="font-mono text-[11px] text-brand-mist">{{ $version }}</p>
                            @endif
                            @if (! $isActive && ! $isEdgeProxyPanel)
                                <p class="mt-0.5 text-[12px] text-brand-moss">{{ __('Not the active webserver on this server.') }}</p>
                            @endif
                        </div>
                    </div>
                    @if ($isActive && $unit !== null)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-medium ring-1 ring-brand-ink/10 {{ $pill['classes'] }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                            {{ $pill['label'] }}
                        </span>
                    @endif
                </div>

                @if ($isActive)
                    @if ($opsReady && ! $isDeployer)
                        @php
                            $lifecycleGroups = $lifecycleGroupsFor($key);
                            $tools = $cliToolsFor($key);
                        @endphp
                        @if (! empty($lifecycleGroups))
                            @php
                                // $isActive is set near the top of the panel loop
                                // ($key === activeWebserver || activeEdgeProxy).
                                $effectiveState = $effectiveUnitState($unit, $isActive);
                            @endphp
                            <div class="grid gap-px bg-brand-ink/5 sm:grid-cols-1">
                                @foreach ($lifecycleGroups as $groupKey => $group)
                                    @php
                                        $header = $groupHeaderFor($groupKey);
                                        $visibleRows = array_values(array_filter(
                                            $group['rows'],
                                            fn ($pair) => $shouldShowAction($pair[0], $effectiveState),
                                        ));
                                    @endphp
                                    @if (! empty($visibleRows))
                                    <div class="bg-white px-6 py-4 sm:px-8">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ $header['title'] }}</p>
                                                @if ($header['sub'] !== '')
                                                    <p class="mt-0.5 text-[12px] text-brand-mist">{{ $header['sub'] }}</p>
                                                @endif
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($visibleRows as [$actionKey, $dangerous])
                                                    @if (! empty($serviceActions[$actionKey]))
                                                        @php $action = $serviceActions[$actionKey]; @endphp
                                                        <button
                                                            type="button"
                                                            @if ($dangerous) wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), true)" @else wire:click="runAllowlistedAction('{{ $actionKey }}')" @endif
                                                            wire:loading.attr="disabled"
                                                            wire:target="openConfirmActionModal,runAllowlistedAction"
                                                            @disabled($actionInFlight)
                                                            title="{{ $actionInFlight ? __('Another action is running — wait for it to finish.') : ($action['description'] ?? '') }}"
                                                            @class([
                                                                'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-60',
                                                                'border-brand-ink/15 bg-white text-brand-ink shadow-sm hover:bg-brand-sand/40' => ! $dangerous,
                                                                'border-rose-200 bg-rose-50/30 text-rose-800 hover:bg-rose-50' => $dangerous,
                                                            ])
                                                        >
                                                            <x-dynamic-component :component="$iconForAction($actionKey)" class="h-3.5 w-3.5 opacity-80" aria-hidden="true" />
                                                            {{ $action['label'] }}
                                                        </button>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                    @endif
                                @endforeach

                                @if (! empty($tools))
                                    <div class="bg-brand-sand/15 px-6 py-4 sm:px-8">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Tools') }}</p>
                                                <p class="mt-0.5 text-[12px] text-brand-mist">{{ __('Read-only diagnostics — version, config dumps, module list, etc.') }}</p>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($tools as [$actionKey, $dangerous])
                                                    @if (! empty($serviceActions[$actionKey]))
                                                        @php $action = $serviceActions[$actionKey]; @endphp
                                                        <button
                                                            type="button"
                                                            @if ($dangerous) wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), true)" @else wire:click="runAllowlistedAction('{{ $actionKey }}')" @endif
                                                            wire:loading.attr="disabled"
                                                            wire:target="openConfirmActionModal,runAllowlistedAction"
                                                            @disabled($actionInFlight)
                                                            title="{{ $actionInFlight ? __('Another action is running — wait for it to finish.') : ($action['description'] ?? '') }}"
                                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-white disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            <x-dynamic-component :component="$iconForAction($actionKey)" class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
                                                            {{ $action['label'] }}
                                                        </button>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endif
                @else
                    @if ($isBlocked && $blockerReason)
                        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                            <p class="font-semibold">{{ __('Switching to :name is currently unavailable.', ['name' => $info['label']]) }}</p>
                            <p class="mt-1">{{ $blockerReason }}</p>
                        </div>
                    @endif

                    <div class="mt-5">
                        @if ($inflightSwitch)
                            <div class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-4 py-2 text-sm font-semibold text-brand-mist">
                                <x-spinner variant="forest" size="sm" />
                                <span>{{ __('Switching in progress…') }}</span>
                            </div>
                        @else
                            <button
                                type="button"
                                wire:click="openSwitchWebserver('{{ $key }}')"
                                wire:loading.attr="disabled"
                                wire:target="openSwitchWebserver"
                                @disabled($isDeployer || ! $opsReady || $isBlocked || $actionInFlight)
                                @class([
                                    'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:opacity-60',
                                    'bg-brand-forest text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90' => ! $isBlocked,
                                    'cursor-not-allowed bg-brand-sand/40 text-brand-mist' => $isBlocked,
                                ])
                            >
                                <span wire:loading.remove wire:target="openSwitchWebserver" class="inline-flex">
                                    @if ($isBlocked)
                                        <x-heroicon-o-no-symbol class="h-4 w-4" />
                                    @else
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    @endif
                                </span>
                                <span wire:loading wire:target="openSwitchWebserver" class="inline-flex">
                                    <x-spinner variant="cream" size="sm" />
                                </span>
                                @if ($isBlocked)
                                    {{ __('Unavailable') }}
                                @else
                                    {{ __('Switch to :name', ['name' => $info['label']]) }}
                                @endif
                            </button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- =============================================================
                 HEALTH CHARTS — per-engine time-series from the dply metrics
                 agent (server-metrics-snapshot.py). Three line charts: active
                 connections (gauge), requests/sec (counter-derived rate), 5xx
                 errors per minute. Edge proxies additionally render a backend
                 health table below. Empty state when the agent hasn't pushed
                 a webserver_health block yet (existing servers pre-backfill).
                 ============================================================= --}}
            @if ($isActive)
                @php
                    $engineHealth = app(\App\Services\Servers\ServerMetricsRangeQuery::class)
                        ->fetchEngineHealth($server, $key, $engine_metrics_range);
                    $latestBlock = $engineHealth['latest_block'] ?? null;
                    $rangeOptions = array_keys(\App\Services\Servers\ServerMetricsRangeQuery::RANGES);
                    $rangeLabels = [
                        '1h' => __('1h'),
                        '6h' => __('6h'),
                        '24h' => __('24h'),
                        '7d' => __('7d'),
                    ];
                @endphp
                <div class="{{ $card }} p-6 sm:p-8" wire:key="health-{{ $key }}-{{ $engine_metrics_range }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — recent health', ['engine' => $info['label']]) }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Live counters from the dply metrics agent. Charts show min/max band, line is the bucket average.') }}</p>
                        </div>
                        <div
                            x-data="{
                                range: @js($engine_metrics_range),
                                storageKey: @js('dply.engine-metrics-range:'.$server->id.':'.$key),
                                init() {
                                    try {
                                        const saved = window.localStorage?.getItem(this.storageKey);
                                        if (saved && saved !== this.range && @js($rangeOptions).includes(saved)) {
                                            this.range = saved;
                                            this.$wire.setEngineMetricsRange(saved);
                                        }
                                    } catch (e) {}
                                },
                                pick(r) {
                                    this.range = r;
                                    try { window.localStorage?.setItem(this.storageKey, r); } catch (e) {}
                                    this.$wire.setEngineMetricsRange(r);
                                },
                            }"
                            x-init="init()"
                            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/10 bg-white p-1 shadow-sm"
                            role="group"
                            aria-label="{{ __('Time range') }}"
                        >
                            @foreach ($rangeOptions as $opt)
                                <button
                                    type="button"
                                    @click="pick(@js($opt))"
                                    :class="range === @js($opt) ? 'bg-brand-ink text-brand-cream' : 'bg-transparent text-brand-moss hover:bg-brand-sand/40'"
                                    class="rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide transition-colors"
                                >
                                    {{ $rangeLabels[$opt] ?? $opt }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if ($latestBlock === null)
                        <div class="mt-5 rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-10 text-center text-sm text-brand-moss">
                            <x-heroicon-o-signal-slash class="mx-auto h-5 w-5 text-brand-mist" />
                            <p class="mt-2">{{ __('No health data yet. The agent will start posting :engine metrics on the next push.', ['engine' => $info['label']]) }}</p>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Existing servers may need a one-shot config backfill before the stats endpoint is reachable.') }}</p>
                        </div>
                    @else
                        <div class="mt-5 grid gap-4 sm:grid-cols-3">
                            {{-- Active connections (gauge) --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-link class="h-5 w-5 text-brand-forest" />
                                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Active connections') }}</h4>
                                    </div>
                                    <p class="text-lg font-semibold tabular-nums text-brand-ink">
                                        {{ number_format((int) ($latestBlock['active_connections'] ?? 0)) }}
                                    </p>
                                </header>
                                <div class="mt-3">
                                    <x-metrics-line-chart
                                        :series="$engineHealth['metrics']['active_connections'] ?? []"
                                        :y-min="0"
                                        color-class="text-brand-forest"
                                        format="count"
                                    />
                                </div>
                            </section>

                            {{-- Requests / sec (counter→rate) --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-bolt class="h-5 w-5 text-brand-forest" />
                                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('Requests / sec') }}</h4>
                                    </div>
                                    <p class="text-lg font-semibold tabular-nums text-brand-ink">
                                        {{ number_format((float) ($latestBlock['requests_per_sec'] ?? 0), 2) }}
                                    </p>
                                </header>
                                <div class="mt-3">
                                    <x-metrics-line-chart
                                        :series="$engineHealth['metrics']['requests_per_sec'] ?? []"
                                        :y-min="0"
                                        color-class="text-brand-forest"
                                        format="rate"
                                    />
                                </div>
                            </section>

                            {{-- 5xx errors / min (counter→rate) --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-exclamation-triangle class="h-5 w-5 text-rose-600" />
                                        <h4 class="text-sm font-semibold text-brand-ink">{{ __('5xx / min') }}</h4>
                                    </div>
                                    <p class="text-lg font-semibold tabular-nums text-rose-700">
                                        {{ number_format((int) ($latestBlock['errors_5xx_total'] ?? 0)) }}
                                    </p>
                                </header>
                                <div class="mt-3">
                                    <x-metrics-line-chart
                                        :series="$engineHealth['metrics']['errors_5xx_per_min'] ?? []"
                                        :y-min="0"
                                        color-class="text-rose-600"
                                        format="rate"
                                    />
                                </div>
                            </section>
                        </div>

                        @if (! empty($latestBlock['backends']))
                            {{-- Edge-proxy-only backend health table. HAProxy emits
                                 per-backend status via the stats socket; Traefik emits
                                 it via /metrics labels (not yet parsed but will land
                                 here when the agent learns to extract them). --}}
                            <div class="mt-5 rounded-2xl border border-brand-ink/10 bg-white">
                                <div class="border-b border-brand-ink/10 px-4 py-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Backends') }}</div>
                                <table class="w-full text-left text-sm">
                                    <thead class="text-[11px] uppercase tracking-wide text-brand-mist">
                                        <tr>
                                            <th class="px-4 py-2 font-medium">{{ __('Backend') }}</th>
                                            <th class="px-4 py-2 font-medium">{{ __('Server') }}</th>
                                            <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5">
                                        @foreach ($latestBlock['backends'] as $backend)
                                            @php
                                                $status = strtoupper((string) ($backend['status'] ?? '?'));
                                                $statusClass = match (true) {
                                                    str_contains($status, 'UP') => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
                                                    str_contains($status, 'DOWN') => 'bg-rose-50 text-rose-800 ring-rose-200',
                                                    default => 'bg-brand-sand/40 text-brand-moss ring-brand-ink/10',
                                                };
                                            @endphp
                                            <tr>
                                                <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $backend['backend'] ?? '—' }}</td>
                                                <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $backend['name'] ?? '—' }}</td>
                                                <td class="px-4 py-2">
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusClass }}">{{ $status }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            @endif

            {{-- =============================================================
                 TOOLS — per-engine CLI commands (e.g. caddy fmt / nginx -T /
                 apachectl -M). Output lands in the existing remote_output
                 surface near the bottom of the page.
                 ============================================================= --}}
            {{-- Tools panel removed: the same diagnostic buttons live in the
                 Overview panel's Tools row now, so this dedicated tab body
                 was duplicate UI. Sub-tab strip and setEngineSubtab() allow-
                 list were updated to match. --}}

            {{-- =============================================================
                 LOGS — last N lines of access / error / journal for the active
                 engine. Live toggle wires a poll so the buffer refreshes every
                 4s while the operator watches a request flow through.
                 ============================================================= --}}
            @if ($engine_subtab === 'logs' && $isActive && $engineHasFullControls($key))
                @php
                    $layout = $webserverConfigLayout[$key] ?? [];
                    $hasAccessLog = ! empty($layout['access_log']);
                    $hasErrorLog = ! empty($layout['error_log']);
                    $hasJournal = ! empty($layout['journal_unit']);
                @endphp
                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="max-w-2xl">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine logs', ['engine' => $info['label']]) }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Tail the last N lines of the access / error log. Toggle Live to poll every 4 s.') }}</p>
                        </div>
                        @if ($log_live)
                            <div wire:poll.4s="refreshWebserverLog" class="hidden" aria-hidden="true"></div>
                        @endif
                    </div>

                    @if (! $opsReady || $isDeployer)
                        <p class="mt-4 text-sm text-brand-moss">{{ __('Logs require ready ops access and a non-deployer role.') }}</p>
                    @else
                        <div class="mt-5 flex flex-wrap items-center gap-2">
                            @if ($hasAccessLog)
                                <button type="button" wire:click="refreshWebserverLog('access')" @class([
                                    'rounded-md border px-3 py-1.5 text-xs font-medium',
                                    'border-brand-forest bg-brand-forest text-brand-cream' => $log_kind === 'access',
                                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $log_kind !== 'access',
                                ])>{{ __('Access') }}</button>
                            @endif
                            @if ($hasErrorLog)
                                <button type="button" wire:click="refreshWebserverLog('error')" @class([
                                    'rounded-md border px-3 py-1.5 text-xs font-medium',
                                    'border-brand-forest bg-brand-forest text-brand-cream' => $log_kind === 'error',
                                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $log_kind !== 'error',
                                ])>{{ __('Error') }}</button>
                            @endif
                            @if ($hasJournal)
                                <button type="button" wire:click="refreshWebserverLog('journal')" @class([
                                    'rounded-md border px-3 py-1.5 text-xs font-medium',
                                    'border-brand-forest bg-brand-forest text-brand-cream' => $log_kind === 'journal',
                                    'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $log_kind !== 'journal',
                                ])>{{ __('journalctl') }}</button>
                            @endif

                            <span class="mx-2 hidden h-5 w-px bg-brand-ink/10 sm:inline-block" aria-hidden="true"></span>

                            <button type="button" wire:click="refreshWebserverLog" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                {{ __('Refresh') }}
                            </button>
                            <button type="button" wire:click="toggleWebserverLogLive" @class([
                                'inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium',
                                'border-emerald-300 bg-emerald-50 text-emerald-900' => $log_live,
                                'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $log_live,
                            ])>
                                @if ($log_live)
                                    <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-600" aria-hidden="true"></span>
                                    {{ __('Live') }}
                                @else
                                    <x-heroicon-o-play class="h-3.5 w-3.5" />
                                    {{ __('Live') }}
                                @endif
                            </button>
                            <span class="ml-auto inline-flex items-center gap-1 text-[11px] text-brand-moss">
                                {{ __('Lines:') }}
                                <select wire:change="refreshWebserverLog(null, $event.target.value)" class="rounded-md border border-brand-ink/15 bg-white py-0.5 pl-2 pr-7 text-[11px] font-medium text-brand-ink">
                                    @foreach ([100, 300, 500, 1000, 2000] as $n)
                                        <option value="{{ $n }}" @selected($log_lines === $n)>{{ $n }}</option>
                                    @endforeach
                                </select>
                            </span>
                        </div>

                        <pre class="mt-4 max-h-[60vh] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-4 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">{{ $log_output !== '' ? $log_output : __('Click Refresh (or toggle Live) to fetch the log.') }}</pre>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 CONFIG — file picker (left) + editor (right) with validate /
                 save / restore. Save is atomic on the server side (snapshot
                 to `_dply_backups/`, then `install -m 0644`), so a bad save
                 can always be undone by restoring the most recent backup.
                 ============================================================= --}}
            @if ($engine_subtab === 'config' && $isActive && $engineHasFullControls($key))
                <div class="{{ $card }} p-6 sm:p-8">
                    <div>
                        <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine config editor', ['engine' => $info['label']]) }}</h3>
                        <p class="mt-1 max-w-3xl text-sm text-brand-moss">{{ __('Edit → Validate (dry-run) → Save. Save snapshots the live file to _dply_backups/, atomically installs, re-validates, and auto-restores the snapshot if validation rejects the new file. Every save is kept as a revision.') }}</p>
                    </div>

                    @if (! $opsReady || $isDeployer)
                        <p class="mt-4 text-sm text-brand-moss">{{ __('Editing config requires ready ops access and a non-deployer role.') }}</p>
                    @else
                        <div class="mt-5 grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
                            {{-- File picker --}}
                            <div class="rounded-xl border border-brand-ink/10 bg-white">
                                <div class="border-b border-brand-ink/10 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Files') }}</div>
                                @if (empty($webserverConfigFiles))
                                    <p class="px-3 py-3 text-xs text-brand-moss">{{ __('No config files discovered. Confirm the server is reachable.') }}</p>
                                @else
                                    <ul class="max-h-[55vh] divide-y divide-brand-ink/5 overflow-auto text-sm">
                                        @foreach ($webserverConfigFiles as $f)
                                            @php $isSel = $config_selected_path === $f['path']; @endphp
                                            <li>
                                                <button
                                                    type="button"
                                                    wire:click="loadWebserverConfig(@js($f['path']))"
                                                    @class([
                                                        'flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-brand-sand/40',
                                                        'bg-brand-sand/50' => $isSel,
                                                    ])
                                                >
                                                    <x-heroicon-o-document class="mt-0.5 h-4 w-4 shrink-0 text-brand-moss" />
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block truncate font-medium text-brand-ink">{{ $f['label'] }}</span>
                                                        <span class="block truncate font-mono text-[10px] text-brand-mist">{{ $f['path'] }}</span>
                                                    </span>
                                                    <span class="shrink-0 font-mono text-[10px] text-brand-mist">{{ number_format($f['size']) }}b</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            {{-- Editor --}}
                            <div class="min-w-0">
                                @if ($config_selected_path === null)
                                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-12 text-center text-sm text-brand-moss">
                                        <x-heroicon-o-arrow-left class="mx-auto h-5 w-5 text-brand-mist" />
                                        <p class="mt-2">{{ __('Pick a file on the left to start editing.') }}</p>
                                    </div>
                                @else
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-all font-mono text-xs text-brand-moss">{{ $config_selected_path }}</p>
                                            @if ($config_truncated_on_load)
                                                <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-amber-200">
                                                    <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                                                    {{ __('Truncated on load — saving is disabled') }}
                                                </p>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button type="button" wire:click="loadWebserverConfig(@js($config_selected_path))" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">
                                                <x-heroicon-o-arrow-path class="h-3 w-3" />
                                                {{ __('Reload') }}
                                            </button>
                                            @php
                                                // Reset-to-default only makes sense for files dply owns a builder for.
                                                // For OLS that's httpd_config.conf and per-site vhconf.conf — gate on
                                                // those paths so the button doesn't tease engines we haven't wired up.
                                                $resetable = $key === 'openlitespeed'
                                                    && (
                                                        $config_selected_path === '/usr/local/lsws/conf/httpd_config.conf'
                                                        || (is_string($config_selected_path) && preg_match('#^/usr/local/lsws/conf/vhosts/[^/]+/vhconf\.conf$#', $config_selected_path) === 1)
                                                    );
                                            @endphp
                                            @if ($resetable)
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('resetWebserverConfigToDefault', [], @js(__('Reset to dply default?')), @js(__('Replace the editor buffer with the canonical content dply\'s provisioner would emit. Nothing is written until you click Save. Your current buffer is lost.')), @js(__('Reset')), false)"
                                                    class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                >
                                                    <x-heroicon-o-arrow-uturn-down class="h-3 w-3" />
                                                    {{ __('Reset to default') }}
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                wire:click="validateWebserverConfigBuffer"
                                                wire:loading.attr="disabled"
                                                wire:target="validateWebserverConfigBuffer"
                                                @disabled($config_truncated_on_load)
                                                class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="validateWebserverConfigBuffer" class="inline-flex">
                                                    <x-heroicon-o-shield-check class="h-3 w-3" />
                                                </span>
                                                <span wire:loading wire:target="validateWebserverConfigBuffer" class="inline-flex">
                                                    <x-spinner class="h-3 w-3" />
                                                </span>
                                                {{ __('Validate') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="saveWebserverConfig"
                                                wire:loading.attr="disabled"
                                                wire:target="saveWebserverConfig"
                                                @disabled($config_truncated_on_load)
                                                class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-forest bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="saveWebserverConfig" class="inline-flex">
                                                    <x-heroicon-o-cloud-arrow-up class="h-3 w-3" />
                                                </span>
                                                <span wire:loading wire:target="saveWebserverConfig" class="inline-flex">
                                                    <x-spinner variant="cream" class="h-3 w-3" />
                                                </span>
                                                {{ __('Save') }}
                                            </button>
                                        </div>
                                    </div>

                                    <textarea
                                        wire:model.live.debounce.500ms="config_contents"
                                        rows="22"
                                        spellcheck="false"
                                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                    >{{ $config_contents }}</textarea>

                                    {{-- Validate output --}}
                                    @if ($config_validate_output !== null)
                                        <div @class([
                                            'mt-3 rounded-xl border px-3 py-2 text-xs',
                                            'border-emerald-200 bg-emerald-50/70 text-emerald-900' => $config_validate_ok,
                                            'border-rose-200 bg-rose-50/70 text-rose-900' => ! $config_validate_ok,
                                        ])>
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                                                {{ $config_validate_ok ? __('Validation passed') : __('Validation reported problems') }}
                                            </p>
                                            <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-all font-mono text-[11px]">{{ $config_validate_output }}</pre>
                                        </div>
                                    @endif

                                    {{-- Revisions --}}
                                    @if (! empty($config_backups))
                                        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white">
                                            <div class="flex items-center justify-between border-b border-brand-ink/10 px-3 py-2">
                                                <span class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                                                    <x-heroicon-o-clock class="h-3 w-3" />
                                                    {{ __('Revisions') }}
                                                </span>
                                                <span class="text-[10px] text-brand-mist">{{ __(':n kept — newest first; click Restore to roll back', ['n' => count($config_backups)]) }}</span>
                                            </div>
                                            <ul class="max-h-48 divide-y divide-brand-ink/5 overflow-auto text-xs">
                                                @foreach ($config_backups as $b)
                                                    <li class="flex items-center justify-between gap-3 px-3 py-1.5">
                                                        <div class="min-w-0">
                                                            <p class="truncate font-mono text-[11px] text-brand-moss">{{ basename($b['path']) }}</p>
                                                            <p class="text-[10px] text-brand-mist">{{ \Illuminate\Support\Carbon::createFromTimestamp($b['mtime'])->diffForHumans() }} — {{ number_format($b['size']) }} bytes</p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('restoreWebserverConfigBackup', [@js($b['path'])], @js(__('Restore backup?')), @js(__('Overwrite the live file with this backup? A snapshot of the current contents is taken first.')), @js(__('Restore')), true)"
                                                            class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                        >
                                                            <x-heroicon-o-arrow-uturn-left class="inline h-3 w-3" />
                                                            {{ __('Restore') }}
                                                        </button>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 LIVE-STATE SUB-TABS — OpenLiteSpeed (v1). Reads cached state
                 from Server.meta.webserver_live_state.openlitespeed; "Refresh
                 now" button kicks a synchronous SSH probe via the Livewire
                 component's refreshEngineLiveState() action.

                 Future engines (nginx/Caddy/Apache/Traefik/HAProxy) drop their
                 own blocks here as their probes land.
                 ============================================================= --}}
            @php
                $liveStateTabsByEngine = [
                    'openlitespeed' => ['vhosts', 'listeners', 'extapps', 'cache'],
                    'traefik' => ['routers', 'services', 'middlewares', 'providers'],
                    'haproxy' => ['frontends', 'backends', 'ssl', 'runtime'],
                ];
                $tabsForThisEngine = $liveStateTabsByEngine[$key] ?? [];
                $isLiveStateView = $isActive && in_array($engine_subtab, $tabsForThisEngine, true);
            @endphp
            {{-- =============================================================
                 OPENLITESPEED — VHOSTS CONFIG. One collapsible card per
                 vhost found in httpd_config.conf. Edits land in the per-vhost
                 vhconf.conf file (one per site); the dply provisioner
                 regenerates this on the next site Apply, so we warn on each
                 card that edits are not durable across Apply cycles.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'vhosts' && $isActive && $engineHasFullControls($key))
                @php $vhostParams = \App\Services\Servers\OpenLiteSpeedVhostsConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="ols-vhosts-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenLiteSpeed vhost settings') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Per-vhost tunables in /usr/local/lsws/conf/vhosts/<name>/vhconf.conf. Each vhost maps to one Site — adds/removes happen in the Sites workspace.') }}
                                </p>
                                <p class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-amber-50/70 px-2.5 py-1 text-[11px] font-medium text-amber-900 ring-1 ring-amber-200">
                                    <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" />
                                    {{ __('Edits here are overwritten the next time you Apply the matching Site (or switch webserver). Use the Site workspace for durable changes.') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="loadOlsVhostsConfig"
                                wire:loading.attr="disabled"
                                wire:target="loadOlsVhostsConfig"
                                class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                            >
                                <span wire:loading.remove wire:target="loadOlsVhostsConfig" class="inline-flex">
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                </span>
                                <span wire:loading wire:target="loadOlsVhostsConfig" class="inline-flex">
                                    <x-spinner class="h-3.5 w-3.5" />
                                </span>
                                {{ __('Reload from server') }}
                            </button>
                        </div>

                        @if ($ols_vhosts_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $ols_vhosts_flash }}</div>
                        @endif
                        @if ($ols_vhosts_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_vhosts_error }}</pre>
                            </div>
                        @endif

                        @if (! $ols_vhosts_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadOlsVhostsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading config…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadOlsVhostsConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($ols_vhosts_loaded && ! empty($ols_vhosts_form))
                        <form wire:submit.prevent="saveOlsVhostsConfig" class="space-y-4">
                            @foreach ($ols_vhosts_form as $vhostName => $values)
                                @php $identity = $ols_vhosts_identity[$vhostName] ?? []; @endphp
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.ols-vhost-expanded:'.$server->id.':'.$vhostName),
                                        init() {
                                            try {
                                                const saved = window.localStorage?.getItem(this.storageKey);
                                                if (saved === '1') this.expanded = true;
                                            } catch (e) {}
                                        },
                                        toggle() {
                                            this.expanded = !this.expanded;
                                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                                        },
                                    }"
                                    x-init="init()"
                                    wire:key="ols-vhost-{{ $vhostName }}"
                                >
                                    <button
                                        type="button"
                                        x-on:click="toggle()"
                                        class="group flex w-full items-start gap-3 text-left"
                                        x-bind:aria-expanded="expanded.toString()"
                                    >
                                        <x-heroicon-o-chevron-down
                                            class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                            x-bind:class="expanded ? '' : '-rotate-90'"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $vhostName }}</span>
                                                @if (! empty($identity['unreadable']))
                                                    <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-700">unreadable</span>
                                                @endif
                                                @if (! empty($identity['domains']))
                                                    <span class="text-[11px] text-brand-mist">{{ implode(', ', array_slice($identity['domains'], 0, 3)) }}@if (count($identity['domains']) > 3) +{{ count($identity['domains']) - 3 }} @endif</span>
                                                @endif
                                            </span>
                                            @if (! empty($identity['conf_path']))
                                                <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ $identity['conf_path'] }}</span>
                                            @endif
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        @if (! empty($identity['unreadable']))
                                            <div class="rounded-md bg-rose-50/60 px-3 py-2 text-[11px] text-rose-900">
                                                {{ __('Could not read this vhost\'s vhconf.conf. Defaults shown — saving will create the file or overwrite it.') }}
                                            </div>
                                        @endif
                                        @if (! empty($identity['vh_root']))
                                            <p class="text-[11px] text-brand-mist">
                                                <span class="font-semibold">{{ __('vhRoot') }}</span>
                                                <span class="font-mono">{{ $identity['vh_root'] }}</span>
                                            </p>
                                        @endif

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($vhostParams as $paramKey => $meta)
                                                @if ($meta['type'] === 'list')
                                                    @continue
                                                @endif
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                value="1"
                                                                wire:model.live="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                                @checked(($values[$paramKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                            />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input
                                                            type="number"
                                                            wire:model.lazy="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input
                                                            type="text"
                                                            wire:model.lazy="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>

                                        @foreach ($vhostParams as $paramKey => $meta)
                                            @if ($meta['type'] !== 'list')
                                                @continue
                                            @endif
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <textarea
                                                    rows="2"
                                                    wire:model.lazy="ols_vhosts_form.{{ $vhostName }}.{{ $paramKey }}"
                                                    placeholder="index.php, index.html"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                ></textarea>
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsVhostsConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsVhostsConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsVhostsConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 OPENLITESPEED — LISTENERS CONFIG. One collapsible card per
                 listener block. Identity (name/address/secure) is fixed at
                 create-time; TLS + protocol tunables are editable. The
                 dply-managed "Default" listener is rebuilt on switch, so
                 we surface the relevant edits but block removal.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'listeners' && $isActive && $engineHasFullControls($key))
                @php $listenerParams = \App\Services\Servers\OpenLiteSpeedListenersConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="ols-listeners-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenLiteSpeed listeners') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Bind addresses + TLS termination defined in /usr/local/lsws/conf/httpd_config.conf. dply manages the "Default" :80 listener; add custom listeners for HTTPS or alt-port admin endpoints. Save validates and reloads; a failed validate auto-restores.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddOlsListenerForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Add listener') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadOlsListenersConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadOlsListenersConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadOlsListenersConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                    </span>
                                    <span wire:loading wire:target="loadOlsListenersConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($ols_listeners_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $ols_listeners_flash }}</div>
                        @endif
                        @if ($ols_listeners_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_listeners_error }}</pre>
                            </div>
                        @endif

                        @if ($ols_listeners_show_add)
                            <form
                                wire:submit.prevent="submitAddOlsListener"
                                class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new listener') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Reserved name "Default" is owned by dply. For HTTPS listeners, point keyFile/certFile at an existing cert on disk.') }}</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_listeners_new.name"
                                            placeholder="HTTPS"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Address') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_listeners_new.address"
                                            placeholder="*:443"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('e.g. `*:443`, `127.0.0.1:8080`, `0.0.0.0:7080`.') }}</span>
                                    </label>
                                    <label class="block sm:col-span-2">
                                        <span class="inline-flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                value="1"
                                                wire:model.live="ols_listeners_new.secure"
                                                @checked(($ols_listeners_new['secure'] ?? '0') === '1')
                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                            />
                                            <span class="text-sm font-medium text-brand-ink">{{ __('TLS / HTTPS listener') }}</span>
                                        </span>
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('When on, OLS terminates TLS using keyFile + certFile below.') }}</span>
                                    </label>
                                    @if (($ols_listeners_new['secure'] ?? '0') === '1')
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Private key path') }}</span>
                                            <input
                                                type="text"
                                                wire:model.lazy="ols_listeners_new.keyFile"
                                                placeholder="/etc/letsencrypt/live/example.com/privkey.pem"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            />
                                        </label>
                                        <label class="block">
                                            <span class="block text-xs font-medium text-brand-ink">{{ __('Certificate path') }}</span>
                                            <input
                                                type="text"
                                                wire:model.lazy="ols_listeners_new.certFile"
                                                placeholder="/etc/letsencrypt/live/example.com/fullchain.pem"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            />
                                        </label>
                                    @endif
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="cancelAddOlsListenerForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submitAddOlsListener"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="submitAddOlsListener" class="inline-flex">
                                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                        </span>
                                        <span wire:loading wire:target="submitAddOlsListener" class="inline-flex">
                                            <x-spinner variant="cream" class="h-3.5 w-3.5" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if (! $ols_listeners_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadOlsListenersConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading config…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadOlsListenersConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($ols_listeners_loaded && ! empty($ols_listeners_form))
                        <form wire:submit.prevent="saveOlsListenersConfig" class="space-y-4">
                            @foreach ($ols_listeners_form as $listenerName => $values)
                                @php
                                    $identity = $ols_listeners_identity[$listenerName] ?? [];
                                    $isSecure = ($identity['secure'] ?? '0') === '1';
                                    $isManaged = in_array($listenerName, \App\Services\Servers\OpenLiteSpeedListenersConfig::MANAGED_NAMES, true);
                                    $mapEntries = $ols_listeners_maps[$listenerName] ?? [];
                                @endphp
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.ols-listener-expanded:'.$server->id.':'.$listenerName),
                                        init() {
                                            try {
                                                const saved = window.localStorage?.getItem(this.storageKey);
                                                if (saved === '1') this.expanded = true;
                                            } catch (e) {}
                                        },
                                        toggle() {
                                            this.expanded = !this.expanded;
                                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                                        },
                                    }"
                                    x-init="init()"
                                    wire:key="ols-listener-{{ $listenerName }}"
                                >
                                    <button
                                        type="button"
                                        x-on:click="toggle()"
                                        class="group flex w-full items-start gap-3 text-left"
                                        x-bind:aria-expanded="expanded.toString()"
                                    >
                                        <x-heroicon-o-chevron-down
                                            class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                            x-bind:class="expanded ? '' : '-rotate-90'"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $listenerName }}</span>
                                                @if ($isSecure)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">
                                                        <x-heroicon-o-lock-closed class="h-3 w-3" /> TLS
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">HTTP</span>
                                                @endif
                                                @if ($isManaged)
                                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">dply</span>
                                                @endif
                                            </span>
                                            @if (! empty($identity['address']))
                                                <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ $identity['address'] }}</span>
                                            @endif
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        @if (! $isManaged)
                                            <div class="flex items-center justify-end">
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('removeOlsListener', ['{{ $listenerName }}'], @js(__('Remove listener: :name', ['name' => $listenerName])), @js(__('Remove the `:name` listener? Sites mapped to this listener stop serving immediately on the bound port.', ['name' => $listenerName])), @js(__('Remove')), true)"
                                                    @disabled($isDeployer || $actionInFlight)
                                                    class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                                    {{ __('Remove') }}
                                                </button>
                                            </div>
                                        @else
                                            <p class="rounded-md bg-amber-50/60 px-3 py-2 text-[11px] text-amber-900">
                                                {{ __('Managed by dply — the switch flow / provisioner re-emits this listener on reconcile. Edits to tunables persist between reconciles; removal is blocked.') }}
                                            </p>
                                        @endif

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($listenerParams as $paramKey => $meta)
                                                @php
                                                    // Skip TLS-only directives on plain HTTP listeners — they
                                                    // wouldn't apply and would just confuse the operator.
                                                    $tlsOnly = in_array($paramKey, ['keyFile', 'certFile', 'certChain', 'sslProtocol', 'enableSpdy', 'enableQuic', 'enableStapling', 'clientVerify'], true);
                                                @endphp
                                                @if ($tlsOnly && ! $isSecure)
                                                    @continue
                                                @endif
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                value="1"
                                                                wire:model.live="ols_listeners_form.{{ $listenerName }}.{{ $paramKey }}"
                                                                @checked(($values[$paramKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                            />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input
                                                            type="number"
                                                            wire:model.lazy="ols_listeners_form.{{ $listenerName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input
                                                            type="text"
                                                            wire:model.lazy="ols_listeners_form.{{ $listenerName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>

                                        @if (! empty($mapEntries))
                                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Vhost mappings (read-only — managed by site provisioning)') }}</p>
                                                <ul class="mt-2 space-y-1 font-mono text-[11px] text-brand-ink">
                                                    @foreach ($mapEntries as $mapEntry)
                                                        <li>{{ $mapEntry }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsListenersConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsListenersConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsListenersConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 OPENLITESPEED — EXTAPPS CONFIG. One collapsible card per
                 extprocessor block. Identity (name/type/address/path) is
                 read-only; tunables (maxConns, env, etc.) are editable.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'extapps' && $isActive && $engineHasFullControls($key))
                @php $extAppsParams = \App\Services\Servers\OpenLiteSpeedExtAppsConfig::PARAMS; @endphp
                <div class="space-y-4 mb-6" wire:key="ols-extapps-config">
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('OpenLiteSpeed external apps') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Per-extprocessor settings (LSAPI / FastCGI / proxy workers) in /usr/local/lsws/conf/httpd_config.conf. Save validates and reloads; a failed validate auto-restores.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="openAddOlsExtAppForm"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Add ExtApp') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="loadOlsExtAppsConfig"
                                    wire:loading.attr="disabled"
                                    wire:target="loadOlsExtAppsConfig"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="loadOlsExtAppsConfig" class="inline-flex">
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                    </span>
                                    <span wire:loading wire:target="loadOlsExtAppsConfig" class="inline-flex">
                                        <x-spinner class="h-3.5 w-3.5" />
                                    </span>
                                    {{ __('Reload from server') }}
                                </button>
                            </div>
                        </div>

                        @if ($ols_extapps_show_add)
                            <form
                                wire:submit.prevent="submitAddOlsExtApp"
                                class="mt-5 rounded-xl border border-brand-forest/30 bg-brand-sand/30 p-4 sm:p-5"
                            >
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Add a new extprocessor') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Identity (name + type + address) is fixed once written. dply-managed `lsphp*` names are reserved.') }}</p>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Name') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_extapps_new_app.name"
                                            placeholder="my-app"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Letters, digits, and `_ . -` only.') }}</span>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Type') }}</span>
                                        <select
                                            wire:model.live="ols_extapps_new_app.type"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        >
                                            @foreach (\App\Services\Servers\OpenLiteSpeedExtAppsConfig::COMMON_TYPES as $tKey => $tLabel)
                                                <option value="{{ $tKey }}">{{ $tKey }} — {{ __($tLabel) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Address') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_extapps_new_app.address"
                                            placeholder="uds://tmp/lshttpd/my-app.sock"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            required
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Unix socket or `host:port`. e.g. uds://tmp/lshttpd/my-app.sock or 127.0.0.1:9000.') }}</span>
                                    </label>
                                    <label class="block">
                                        <span class="block text-xs font-medium text-brand-ink">{{ __('Binary path (optional)') }}</span>
                                        <input
                                            type="text"
                                            wire:model.lazy="ols_extapps_new_app.path"
                                            placeholder="/usr/local/lsws/lsphp83/bin/lsphp"
                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        />
                                        <span class="mt-1 block text-[11px] text-brand-mist">{{ __('Required for lsapi/fcgi when OLS spawns the worker. Leave blank for proxy.') }}</span>
                                    </label>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-3">
                                    <button
                                        type="button"
                                        wire:click="cancelAddOlsExtAppForm"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submitAddOlsExtApp"
                                        @disabled($actionInFlight)
                                        class="inline-flex items-center gap-2 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <span wire:loading.remove wire:target="submitAddOlsExtApp" class="inline-flex">
                                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                        </span>
                                        <span wire:loading wire:target="submitAddOlsExtApp" class="inline-flex">
                                            <x-spinner variant="cream" class="h-3.5 w-3.5" />
                                        </span>
                                        {{ __('Create and reload') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if ($ols_extapps_flash)
                            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $ols_extapps_flash }}</div>
                        @endif
                        @if ($ols_extapps_error)
                            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_extapps_error }}</pre>
                            </div>
                        @endif

                        @if (! $ols_extapps_loaded)
                            <p class="mt-5 text-sm text-brand-moss">
                                <span wire:loading wire:target="loadOlsExtAppsConfig" class="inline-flex items-center gap-2">
                                    <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading config…') }}
                                </span>
                                <span wire:loading.remove wire:target="loadOlsExtAppsConfig">
                                    {{ __('Click "Reload from server" to fetch current values.') }}
                                </span>
                            </p>
                        @endif
                    </div>

                    @if ($ols_extapps_loaded && ! empty($ols_extapps_form))
                        <form wire:submit.prevent="saveOlsExtAppsConfig" class="space-y-4">
                            @foreach ($ols_extapps_form as $appName => $values)
                                @php $identity = $ols_extapps_identity[$appName] ?? []; @endphp
                                <div
                                    class="{{ $card }} p-5 sm:p-6"
                                    x-data="{
                                        expanded: false,
                                        storageKey: @js('dply.ols-extapp-expanded:'.$server->id.':'.$appName),
                                        init() {
                                            try {
                                                const saved = window.localStorage?.getItem(this.storageKey);
                                                if (saved === '1') this.expanded = true;
                                            } catch (e) {}
                                        },
                                        toggle() {
                                            this.expanded = !this.expanded;
                                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                                        },
                                    }"
                                    x-init="init()"
                                    wire:key="ols-extapp-{{ $appName }}"
                                >
                                    <button
                                        type="button"
                                        x-on:click="toggle()"
                                        class="group flex w-full items-start gap-3 text-left"
                                        x-bind:aria-expanded="expanded.toString()"
                                    >
                                        <x-heroicon-o-chevron-down
                                            class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                            x-bind:class="expanded ? '' : '-rotate-90'"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ $appName }}</span>
                                                @if (! empty($identity['type']))
                                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $identity['type'] }}</span>
                                                @endif
                                            </span>
                                            @if (! empty($identity['address']))
                                                <span class="mt-0.5 block truncate text-[11px] font-mono text-brand-mist">{{ $identity['address'] }}</span>
                                            @endif
                                        </span>
                                    </button>

                                    <div x-show="expanded" x-cloak class="mt-5 space-y-5">
                                        @if (! empty($identity['path']))
                                            <p class="text-[11px] text-brand-mist">
                                                <span class="font-semibold">{{ __('Binary path') }}</span>
                                                <span class="font-mono">{{ $identity['path'] }}</span>
                                            </p>
                                        @endif

                                        @if (! preg_match('/^lsphp\d+$/', $appName))
                                            <div class="flex items-center justify-end">
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('removeOlsExtApp', ['{{ $appName }}'], @js(__('Remove ExtApp: :name', ['name' => $appName])), @js(__('Remove the `:name` extprocessor block? Any vhost still referencing it will fail to load on next reload.', ['name' => $appName])), @js(__('Remove')), true)"
                                                    @disabled($isDeployer || $actionInFlight)
                                                    class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50/30 px-2.5 py-1 text-[11px] font-medium text-rose-800 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                                    {{ __('Remove') }}
                                                </button>
                                            </div>
                                        @else
                                            <p class="rounded-md bg-brand-sand/30 px-3 py-2 text-[11px] text-brand-mist">
                                                {{ __('Managed by dply. Adjust this PHP version via the PHP workspace; remove the PHP version there to delete this block.') }}
                                            </p>
                                        @endif

                                        <div class="grid gap-5 sm:grid-cols-2">
                                            @foreach ($extAppsParams as $paramKey => $meta)
                                                @if ($meta['type'] === 'lines')
                                                    @continue
                                                @endif
                                                <label class="block">
                                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                    @if ($meta['type'] === 'bool')
                                                        <span class="mt-1 inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                value="1"
                                                                wire:model.live="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                                @checked(($values[$paramKey] ?? '0') === '1')
                                                                class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                            />
                                                            <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                        </span>
                                                    @elseif ($meta['type'] === 'int')
                                                        <input
                                                            type="number"
                                                            wire:model.lazy="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @else
                                                        <input
                                                            type="text"
                                                            wire:model.lazy="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                            class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>

                                        @foreach ($extAppsParams as $paramKey => $meta)
                                            @if ($meta['type'] !== 'lines')
                                                @continue
                                            @endif
                                            <label class="block">
                                                <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                                <textarea
                                                    rows="3"
                                                    wire:model.lazy="ols_extapps_form.{{ $appName }}.{{ $paramKey }}"
                                                    placeholder="{{ __('KEY=VALUE per line') }}"
                                                    class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                                ></textarea>
                                                <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsExtAppsConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsExtAppsConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsExtAppsConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            {{-- =============================================================
                 OPENLITESPEED — CACHE MODULE CONFIG. Adjustable common
                 LSCache directives. The form sits above the live-state
                 table on the same sub-tab. Auto-loads on first arrival;
                 manual reload via the inline button.
                 ============================================================= --}}
            @if ($key === 'openlitespeed' && $engine_subtab === 'cache' && $isActive && $engineHasFullControls($key))
                @php $olsParams = \App\Services\Servers\OpenLiteSpeedCacheModuleConfig::PARAMS; @endphp
                <div
                    class="{{ $card }} p-6 sm:p-8 mb-6"
                    wire:key="ols-cache-config"
                    x-data="{
                        expanded: true,
                        storageKey: @js('dply.ols-cache-expanded:'.$server->id),
                        init() {
                            try {
                                const saved = window.localStorage?.getItem(this.storageKey);
                                if (saved === '0') this.expanded = false;
                            } catch (e) {}
                        },
                        toggle() {
                            this.expanded = !this.expanded;
                            try { window.localStorage?.setItem(this.storageKey, this.expanded ? '1' : '0'); } catch (e) {}
                        },
                    }"
                    x-init="init()"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <button
                            type="button"
                            x-on:click="toggle()"
                            class="group flex min-w-0 flex-1 items-start gap-3 text-left"
                            x-bind:aria-expanded="expanded.toString()"
                        >
                            <x-heroicon-o-chevron-down
                                class="mt-1 h-4 w-4 shrink-0 text-brand-moss transition-transform"
                                x-bind:class="expanded ? '' : '-rotate-90'"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('OpenLiteSpeed cache module') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Server-level LSCache settings written into /usr/local/lsws/conf/httpd_config.conf. Save validates with `lshttpd -t` and reloads the daemon; a failed validate auto-restores the previous file.') }}
                                </p>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="loadOlsCacheConfig"
                            wire:loading.attr="disabled"
                            wire:target="loadOlsCacheConfig"
                            x-show="expanded"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="loadOlsCacheConfig" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="loadOlsCacheConfig" class="inline-flex">
                                <x-spinner class="h-3.5 w-3.5" />
                            </span>
                            {{ __('Reload from server') }}
                        </button>
                    </div>

                    <div x-show="expanded" x-cloak>
                    @if ($ols_cache_flash)
                        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">
                            {{ $ols_cache_flash }}
                        </div>
                    @endif
                    @if ($ols_cache_error)
                        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">
                            <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ $ols_cache_error }}</pre>
                        </div>
                    @endif

                    @if (! $ols_cache_loaded)
                        <p class="mt-5 text-sm text-brand-moss">
                            <span wire:loading wire:target="loadOlsCacheConfig" class="inline-flex items-center gap-2">
                                <x-spinner class="h-3.5 w-3.5" /> {{ __('Reading config…') }}
                            </span>
                            <span wire:loading.remove wire:target="loadOlsCacheConfig">
                                {{ __('Click "Reload from server" to fetch current values.') }}
                            </span>
                        </p>
                    @else
                        <form wire:submit.prevent="saveOlsCacheConfig" class="mt-6 space-y-6">
                            <div class="grid gap-5 sm:grid-cols-2">
                                @foreach ($olsParams as $paramKey => $meta)
                                    @if ($meta['type'] === 'lines')
                                        @continue
                                    @endif
                                    <label class="block">
                                        <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                        @if ($meta['type'] === 'bool')
                                            <span class="mt-1 inline-flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    value="1"
                                                    wire:model.live="ols_cache_form.{{ $paramKey }}"
                                                    @checked(($ols_cache_form[$paramKey] ?? '0') === '1')
                                                    class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest"
                                                />
                                                <span class="text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                            </span>
                                        @else
                                            <input
                                                type="number"
                                                min="0"
                                                wire:model.lazy="ols_cache_form.{{ $paramKey }}"
                                                class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white text-sm font-medium text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                            />
                                            <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>

                            @foreach ($olsParams as $paramKey => $meta)
                                @if ($meta['type'] !== 'lines')
                                    @continue
                                @endif
                                <label class="block">
                                    <span class="block text-sm font-medium text-brand-ink">{{ __($meta['label']) }}</span>
                                    <textarea
                                        rows="3"
                                        wire:model.lazy="ols_cache_form.{{ $paramKey }}"
                                        placeholder="{{ __('One per line') }}"
                                        class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                    ></textarea>
                                    <span class="mt-1 block text-xs text-brand-moss">{{ __($meta['help']) }}</span>
                                </label>
                            @endforeach

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-4">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="saveOlsCacheConfig"
                                    @disabled($isDeployer || $actionInFlight)
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="saveOlsCacheConfig" class="inline-flex">
                                        <x-heroicon-o-check class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="saveOlsCacheConfig" class="inline-flex">
                                        <x-spinner variant="cream" class="h-4 w-4" />
                                    </span>
                                    {{ __('Save and reload OpenLiteSpeed') }}
                                </button>
                            </div>
                        </form>
                    @endif
                    </div>
                </div>
            @endif

            @if ($isLiveStateView)
                @php
                    $liveStatePayload = data_get($server->meta ?? [], 'webserver_live_state.'.$key);
                    $liveState = \App\Services\Servers\LiveState\EngineLiveState::fromArray($liveStatePayload);
                    $liveUnits = $liveState?->units ?? [];
                    $liveCapturedAt = $liveState?->capturedAt;
                    $engineLabel = $info['label'] ?? ucfirst($key);
                    $subtabTitle = match ($engine_subtab) {
                        'vhosts' => __('Vhosts'),
                        'listeners' => __('Listeners'),
                        'extapps' => __('External Apps'),
                        'cache' => __('Cache'),
                        'routers' => __('Routers'),
                        'services' => __('Services'),
                        'middlewares' => __('Middlewares'),
                        'providers' => __('Providers'),
                        'frontends' => __('Frontends'),
                        'backends' => __('Backends'),
                        'ssl' => __('SSL'),
                        'runtime' => __('Runtime'),
                        default => $engine_subtab,
                    };
                    $subtabDescription = match ($key.'/'.$engine_subtab) {
                        'openlitespeed/vhosts' => __('Configured virtual hosts parsed from /usr/local/lsws/conf/vhosts/. Per-vhost PHP processor + SSL state + document root.'),
                        'openlitespeed/listeners' => __('Listener blocks from httpd_config.conf — which port serves which vhosts.'),
                        'openlitespeed/extapps' => __('External LSAPI / proxy app processors referenced by vhosts. Marks missing lsphpXX binaries.'),
                        'openlitespeed/cache' => __('LSCache hit counts and hit-rate per .rtreport file.'),
                        'traefik/routers' => __('HTTP routers from /api/http/routers — rule, service, middleware chain, entry-points, status, provider.'),
                        'traefik/services' => __('HTTP services from /api/http/services — load balancer servers + server status.'),
                        'traefik/middlewares' => __('HTTP middlewares from /api/http/middlewares — basicAuth, headers, redirects, rate limits, etc.'),
                        'traefik/providers' => __('Config providers contributing routers/services (file, docker, kubernetes, etc.).'),
                        'haproxy/frontends' => __('Per-frontend stats from `show stat` — current sessions, total, rate, 2xx/5xx counts.'),
                        'haproxy/backends' => __('Per-backend rollup with member servers + per-server health check status.'),
                        'haproxy/ssl' => __('Loaded SSL certificate paths from `show ssl cert`.'),
                        'haproxy/runtime' => __('Runtime info from `show info` + memory pool summary from `show pools`.'),
                        default => '',
                    };
                @endphp
                <div class="{{ $card }} p-6 sm:p-8" wire:key="livestate-{{ $key }}-{{ $engine_subtab }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">
                                {{ $engineLabel }} — {{ $subtabTitle }}
                            </h3>
                            @if ($subtabDescription !== '')
                                <p class="mt-1 text-sm text-brand-moss">{{ $subtabDescription }}</p>
                            @endif
                            @if ($liveCapturedAt)
                                <p class="mt-1 text-[11px] tabular-nums text-brand-mist">
                                    {{ __('As of :time', ['time' => $liveCapturedAt->diffForHumans()]) }}
                                </p>
                            @endif
                        </div>
                        <button
                            type="button"
                            wire:click="refreshEngineLiveState"
                            wire:loading.attr="disabled"
                            wire:target="refreshEngineLiveState"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="refreshEngineLiveState" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="refreshEngineLiveState" class="inline-flex">
                                <x-spinner variant="forest" size="sm" />
                            </span>
                            {{ __('Refresh now') }}
                        </button>
                    </div>

                    @php
                        $rows = $liveUnits[$engine_subtab] ?? [];
                        // For the vhosts table, resolve each row name back to a Site so
                        // we can render a "Manage on site" link (PHP version, SSL, env, …
                        // all live there, not here). Names follow the
                        // `dply-<site_id>-<slug>` pattern emitted by Site::nginxConfigBasename().
                        $sitesByVhostName = [];
                        if ($engine_subtab === 'vhosts' && ! empty($rows) && $key === 'openlitespeed') {
                            $ids = [];
                            foreach ($rows as $row) {
                                if (preg_match('/^dply-([0-9a-z]+)-/i', (string) ($row['name'] ?? ''), $idMatch) === 1) {
                                    $ids[] = $idMatch[1];
                                }
                            }
                            if ($ids !== []) {
                                $sitesByVhostName = \App\Models\Site::query()
                                    ->whereIn('id', array_unique($ids))
                                    ->get(['id', 'slug'])
                                    ->keyBy(fn ($s) => 'dply-'.$s->id.'-'.$s->slug)
                                    ->all();
                            }
                        }
                    @endphp
                    @if (empty($rows))
                        <div class="mt-5 rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-10 text-center text-sm text-brand-moss">
                            <x-heroicon-o-signal-slash class="mx-auto h-5 w-5 text-brand-mist" />
                            <p class="mt-2">{{ __('No data yet — click "Refresh now" to probe the server.') }}</p>
                        </div>
                    @else
                        <div class="mt-5 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-brand-sand/30 text-[11px] uppercase tracking-wide text-brand-mist">
                                    <tr>
                                        @switch($engine_subtab)
                                            @case('vhosts')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Domains') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Doc root') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('PHP') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('SSL') }}</th>
                                                <th class="px-4 py-2 font-medium text-right">{{ __('Manage') }}</th>
                                                @break
                                            @case('listeners')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Address') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Secure') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Mapped vhosts') }}</th>
                                                @break
                                            @case('extapps')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Type') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Path') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('PHP') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('cache')
                                                <th class="px-4 py-2 font-medium">{{ __('Source') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Public hits') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Private hits') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Static hits') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Hit rate') }}</th>
                                                @break
                                            @case('routers')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Rule') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Service') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Middlewares') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('services')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Type') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Servers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Provider') }}</th>
                                                @break
                                            @case('middlewares')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Type') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Provider') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('providers')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Routers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Services') }}</th>
                                                @break
                                            @case('frontends')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Sess (cur/max/tot)') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Rate') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('2xx / 5xx') }}</th>
                                                @break
                                            @case('backends')
                                                <th class="px-4 py-2 font-medium">{{ __('Backend') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Servers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Sess (cur/tot)') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('5xx') }}</th>
                                                @break
                                            @case('ssl')
                                                <th class="px-4 py-2 font-medium">{{ __('Path') }}</th>
                                                @break
                                            @case('runtime')
                                                <th class="px-4 py-2 font-medium">{{ __('Version') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Uptime') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Conns (cur/cum)') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Cum requests') }}</th>
                                                @break
                                        @endswitch
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach ($rows as $row)
                                        <tr>
                                            @switch($engine_subtab)
                                                @case('vhosts')
                                                    @php $vhSite = $sitesByVhostName[(string) ($row['name'] ?? '')] ?? null; @endphp
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['domains'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['doc_root'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['php_version'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @if (! empty($row['ssl']))
                                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('on') }}</span>
                                                        @else
                                                            <span class="text-brand-mist">—</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-2 text-right">
                                                        @if ($vhSite)
                                                            <a
                                                                href="{{ route('sites.show', ['server' => $server, 'site' => $vhSite->id]) }}"
                                                                class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                                title="{{ __('PHP version, SSL, env, and other per-site settings are managed in the Site workspace.') }}"
                                                            >
                                                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                                                                {{ __('Open site') }}
                                                            </a>
                                                        @else
                                                            <span class="text-brand-mist text-[11px]">—</span>
                                                        @endif
                                                    </td>
                                                    @break
                                                @case('listeners')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['address'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ ! empty($row['secure']) ? __('yes') : __('no') }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['vhosts'] ?? []) ?: '—' }}</td>
                                                    @break
                                                @case('extapps')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['type'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['path'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['php_version'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @if (! empty($row['installed']))
                                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('installed') }}</span>
                                                        @else
                                                            <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700">{{ __('missing') }}</span>
                                                        @endif
                                                    </td>
                                                    @break
                                                @case('cache')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['source'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['public_hits'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['private_hits'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['static_hits'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((float) ($row['hit_rate_pct'] ?? 0), 2) }}%</td>
                                                    @break
                                                @case('routers')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['rule'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['service'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['middlewares'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $st = strtoupper((string) ($row['status'] ?? '')); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $st === 'ENABLED',
                                                            'bg-amber-50 text-amber-700 ring-amber-200' => $st === 'WARNING',
                                                            'bg-rose-50 text-rose-700 ring-rose-200' => $st === 'DISABLED' || $st === 'ERROR',
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! in_array($st, ['ENABLED', 'WARNING', 'DISABLED', 'ERROR'], true),
                                                        ])>{{ $st ?: '—' }}</span>
                                                    </td>
                                                    @break
                                                @case('services')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['type'] ?? 'loadBalancer' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ implode(', ', $row['servers'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['provider'] ?? '—' }}</td>
                                                    @break
                                                @case('middlewares')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['type'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['provider'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @break
                                                @case('providers')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['router_count'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['service_count'] ?? 0)) }}</td>
                                                    @break
                                                @case('frontends')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $st = strtoupper((string) ($row['status'] ?? '')); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $st === 'OPEN' || $st === 'UP',
                                                            'bg-rose-50 text-rose-700 ring-rose-200' => $st === 'DOWN' || $st === 'STOP',
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! in_array($st, ['OPEN', 'UP', 'DOWN', 'STOP'], true),
                                                        ])>{{ $st ?: '—' }}</span>
                                                    </td>
                                                    <td class="px-4 py-2 font-mono tabular-nums text-xs text-brand-moss">{{ ($row['sessions_current'] ?? 0).' / '.($row['sessions_max'] ?? 0).' / '.number_format((int) ($row['sessions_total'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ $row['rate'] ?? 0 }}/s</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['hrsp_2xx'] ?? 0)) }} / <span class="text-rose-700">{{ number_format((int) ($row['hrsp_5xx'] ?? 0)) }}</span></td>
                                                    @break
                                                @case('backends')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $st = strtoupper((string) ($row['status'] ?? '')); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $st === 'UP',
                                                            'bg-rose-50 text-rose-700 ring-rose-200' => str_contains($st, 'DOWN'),
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $st === '' || (! in_array($st, ['UP'], true) && ! str_contains($st, 'DOWN')),
                                                        ])>{{ $st ?: '—' }}</span>
                                                    </td>
                                                    <td class="px-4 py-2 text-[11px] text-brand-moss">
                                                        @foreach (($row['servers'] ?? []) as $srv)
                                                            <div class="inline-flex items-center gap-1 mr-2">
                                                                <span class="font-mono">{{ $srv['name'] }}</span>
                                                                <span @class([
                                                                    'text-[10px]',
                                                                    'text-emerald-700' => strtoupper($srv['status'] ?? '') === 'UP',
                                                                    'text-rose-700' => str_contains(strtoupper($srv['status'] ?? ''), 'DOWN'),
                                                                ])>{{ $srv['status'] ?? '' }}</span>
                                                            </div>
                                                        @endforeach
                                                        @if (empty($row['servers']))—@endif
                                                    </td>
                                                    <td class="px-4 py-2 font-mono tabular-nums text-xs text-brand-moss">{{ ($row['sessions_current'] ?? 0).' / '.number_format((int) ($row['sessions_total'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs text-rose-700">{{ number_format((int) ($row['hrsp_5xx'] ?? 0)) }}</td>
                                                    @break
                                                @case('ssl')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['path'] ?? '—' }}</td>
                                                    @break
                                                @case('runtime')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['version'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['uptime_sec'] ?? 0)) }}s</td>
                                                    <td class="px-4 py-2 font-mono tabular-nums text-xs">{{ ($row['current_conns'] ?? 0).' / '.number_format((int) ($row['cum_conns'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['cum_req'] ?? 0)) }}</td>
                                                    @break
                                            @endswitch
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            @if ($engine_subtab === 'info')
                {{-- Engine info card — license, maintainer, wire protocol, best-for,
                     homepage + docs links. Reuses the partial built for caches so the
                     visual treatment stays consistent across workspaces. --}}
                @php $engineInfo = \App\Support\Servers\WebserverEngineInfo::for($key); @endphp
                @include('livewire.servers.partials.cache-engine-info-card', [
                    'info' => $engineInfo,
                    'row' => $isActive ? true : null,
                    'card' => $card,
                ])
            @endif
        </x-server-workspace-tab-panel>
    @endforeach

    {{-- =====================================================================
         ADVANCED — PHP-FPM, TLS / certbot, switch history.
         ===================================================================== --}}
    <x-server-workspace-tab-panel
        id="ws-panel-advanced"
        labelled-by="ws-tab-advanced"
        :hidden="$workspace_tab !== 'advanced'"
        panel-class="space-y-6"
    >
        {{-- PHP-FPM --}}
        @if (! empty($phpFpm['versions']))
            <div class="{{ $card }} p-6 sm:p-8">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('PHP-FPM') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Detected installations under /etc/php/. Default is set in server meta and used by deploys and the per-row PHP-FPM actions.') }}
                </p>

                <div class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Version') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Default') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Pools') }}</th>
                                @if ($opsReady && ! $isDeployer)
                                    <th class="px-4 py-2 font-semibold text-right">{{ __('Actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($phpFpm['versions'] as $row)
                                @php $rowPill = $statePill($row['active'] ?? null); @endphp
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['version'] }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $rowPill['classes'] }}">
                                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $rowPill['dot'] }}"></span>
                                            {{ $rowPill['label'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-xs">
                                        @if ($row['version'] === $defaultPhp)
                                            <span class="font-medium text-brand-forest">★ {{ __('Default') }}</span>
                                        @else
                                            <span class="text-brand-mist">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['pools_count'] }}</td>
                                    @if ($opsReady && ! $isDeployer)
                                        <td class="px-4 py-2 text-right">
                                            <div class="inline-flex flex-wrap justify-end gap-1.5">
                                                @if (! empty($serviceActions['restart_php_fpm']))
                                                    @php $a = $serviceActions['restart_php_fpm']; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['restart_php_fpm'], @js($a['label']), @js($a['confirm']), @js($a['label']), false)"
                                                        class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >{{ __('Restart') }}</button>
                                                @endif
                                                @if (! empty($serviceActions['reload_php_fpm']))
                                                    @php $a = $serviceActions['reload_php_fpm']; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['reload_php_fpm'], @js($a['label']), @js($a['confirm']), @js($a['label']), false)"
                                                        class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >{{ __('Reload') }}</button>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-brand-mist">{{ __('Restart and Reload act on the default PHP version (set in server meta). Per-version targeting is on the roadmap.') }}</p>
            </div>
        @endif

        {{-- TLS / certbot --}}
        @if (! empty($certbot['present']))
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('TLS / certbot') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Certificates managed by certbot on this server. Dry-run before renewing if you’re unsure.') }}</p>
                    </div>
                    @if ($opsReady && ! $isDeployer)
                        <div class="flex shrink-0 flex-wrap gap-2">
                            @foreach (['certbot_renew_dry_run', 'certbot_renew_all'] as $cbKey)
                                @if (! empty($serviceActions[$cbKey]))
                                    @php $a = $serviceActions[$cbKey]; @endphp
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $cbKey }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $cbKey === 'certbot_renew_all' ? 'true' : 'false' }})"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >{{ $a['label'] }}</button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                @if (! empty($certs))
                    <div class="mt-5 overflow-hidden rounded-xl border border-brand-ink/10">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/30 text-left text-[11px] uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-4 py-2 font-semibold">{{ __('Domains') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('Expires') }}</th>
                                    <th class="px-4 py-2 font-semibold">{{ __('Days remaining') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5 bg-white">
                                @foreach ($certs as $cert)
                                    @php
                                        $days = $cert['valid'];
                                        $tone = $days === null
                                            ? 'text-brand-moss'
                                            : ($days < 0 ? 'text-red-700 font-semibold' : ($days < 14 ? 'text-red-700 font-semibold' : ($days < 30 ? 'text-amber-700 font-medium' : 'text-brand-ink')));
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-2 text-xs">
                                            <div class="font-medium text-brand-ink">{{ $cert['name'] }}</div>
                                            @if ($cert['domains'])
                                                <div class="font-mono text-[11px] text-brand-moss">{{ $cert['domains'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $cert['expiry'] ?: '—' }}</td>
                                        <td class="px-4 py-2 text-xs {{ $tone }}">
                                            @if ($days === null) — @elseif ($days < 0) {{ __('Invalid') }} @else {{ trans_choice(':n day|:n days', $days, ['n' => $days]) }} @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-4 text-sm text-brand-moss">{{ __('certbot is installed but no certificates are managed yet.') }}</p>
                @endif
            </div>
        @endif

        {{-- Switch history --}}
        @if ($recentSwitches->isNotEmpty())
            <div class="{{ $card }} p-6 sm:p-8">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Switch history') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Recent webserver switches on this server.') }}</p>

                <ul class="mt-4 divide-y divide-brand-ink/8 overflow-hidden rounded-xl border border-brand-ink/10">
                    @foreach ($recentSwitches as $event)
                        @php
                            $payload = is_array($event->payload) ? $event->payload : [];
                            $isSuccess = $event->result_status === \App\Models\ServerWebserverAuditEvent::RESULT_SUCCESS;
                            $isRollback = $event->action === \App\Models\ServerWebserverAuditEvent::ACTION_ROLLBACK;
                            $statusClasses = $isSuccess
                                ? 'bg-brand-sage/15 text-brand-forest ring-brand-sage/30'
                                : 'bg-rose-50 text-rose-800 ring-rose-200';
                            $sitesCount = (int) ($payload['sites_affected'] ?? 0);
                            $durationMs = (int) ($payload['duration_ms'] ?? 0);
                        @endphp
                        <li class="bg-white px-4 py-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 font-mono text-xs text-brand-ink">
                                        {{ $payload['from'] ?? '—' }}
                                        <x-heroicon-o-arrow-right class="h-3 w-3 text-brand-mist" />
                                        {{ $payload['to'] ?? '—' }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusClasses }}">
                                        @if ($isRollback)
                                            {{ __('rolled back') }}
                                        @elseif ($isSuccess)
                                            {{ __('success') }}
                                        @else
                                            {{ __('failed') }}
                                        @endif
                                    </span>
                                    @if ($sitesCount > 0)
                                        <span class="inline-flex items-center rounded-full bg-brand-ink/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                            {{ trans_choice(':n site|:n sites', $sitesCount, ['n' => $sitesCount]) }}
                                        </span>
                                    @endif
                                    @if (! empty($payload['tls_opt_in']))
                                        <span class="inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('TLS handover') }}</span>
                                    @endif
                                </div>
                                <div class="text-right text-xs text-brand-moss">
                                    <p>{{ optional($event->user)->name ?? __('system') }}</p>
                                    <p class="text-brand-mist">{{ $event->created_at?->diffForHumans() }}</p>
                                </div>
                            </div>
                            @if (! $isSuccess && ! empty($payload['reason']))
                                <p class="mt-2 break-words font-mono text-[11px] text-rose-800">{{ $payload['reason'] }}</p>
                            @endif
                            @if ($durationMs > 0)
                                <p class="mt-1 text-[10px] text-brand-mist">
                                    {{ __('Duration:') }} <span class="font-mono">{{ $durationMs < 1000 ? $durationMs.' ms' : round($durationMs / 1000, 1).' s' }}</span>
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (empty($phpFpm['versions']) && empty($certbot['present']) && $recentSwitches->isEmpty())
            <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-white px-6 py-8 text-center text-sm text-brand-moss">
                <p>{{ __('Nothing to show here yet. PHP-FPM versions, certbot certificates, and switch history will appear once the server has any to report.') }}</p>
            </div>
        @endif
    </x-server-workspace-tab-panel>

    </div>

    {{-- Cascade confirmation modal — opened by openSwitchWebserver() from any
         tab. Lives outside the tab panels so the same modal serves all of
         them. Modal cleanup (body scroll lock) is handled by the x-modal
         component's destroy() hook. --}}
    @if ($switch_plan !== null)
        <x-modal
            name="webserver-switch-modal"
            maxWidth="2xl"
            overlayClass="bg-brand-ink/40"
            panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        >
            <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Confirm switch') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Switch webserver?') }}</h2>
                <div class="mt-3 inline-flex flex-wrap items-center gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 font-mono text-sm text-brand-ink">
                    <span>{{ $switch_plan['from'] }}</span>
                    <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0 text-brand-mist" />
                    <span>{{ $switch_plan['to'] }}</span>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                @if ($switch_plan['blocker'] !== null)
                    <div class="rounded-xl border border-rose-200 bg-rose-50/70 px-4 py-3">
                        <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-900">
                            <x-heroicon-m-no-symbol class="h-3.5 w-3.5" />
                            {{ __('Cannot switch') }}
                        </p>
                        <p class="mt-2 text-sm leading-relaxed text-rose-900">{{ $switch_plan['blocker']['label'] }}</p>
                    </div>
                @else
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Always applied') }}</p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($switch_plan['auto'] as $row)
                                <li class="flex items-start gap-2 text-sm text-brand-ink">
                                    <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                    <span>{{ $row['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if (! empty($switch_plan['optIn']))
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Opt in') }}</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($switch_plan['optIn'] as $row)
                                    @php
                                        $wireModel = match ($row['key']) {
                                            'tls_to_caddy' => 'switch_tls_to_caddy',
                                            default => null,
                                        };
                                    @endphp
                                    @if ($wireModel)
                                        <li class="flex items-start gap-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                                            <input id="switch-optin-{{ $row['key'] }}" type="checkbox" wire:model="{{ $wireModel }}" class="mt-0.5 h-4 w-4 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                                            <label for="switch-optin-{{ $row['key'] }}" class="text-sm leading-relaxed text-brand-ink">{{ $row['label'] }}</label>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Estimated timing') }}</p>
                        <ul class="mt-2 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                            @foreach ($switch_plan['downtime'] as $phase)
                                @php
                                    $secs = max(1, (int) round($phase['estimate_ms'] / 1000));
                                    $secLabel = $phase['estimate_ms'] < 1000
                                        ? trans_choice(':n ms|:n ms', $phase['estimate_ms'], ['n' => $phase['estimate_ms']])
                                        : trans_choice(':n second|:n seconds', $secs, ['n' => $secs]);
                                @endphp
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span class="text-brand-ink">{{ $phase['label'] }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="font-mono text-[11px] text-brand-moss">~{{ $secLabel }}</span>
                                        @if ($phase['blocking'])
                                            <span class="inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-rose-200">{{ __('downtime') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('live') }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if (! empty($switch_plan['manual']))
                        <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3">
                            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-900">
                                <x-heroicon-m-information-circle class="h-3.5 w-3.5" />
                                {{ __('Cannot be fixed from here') }}
                            </p>
                            <ul class="mt-2 space-y-1 text-sm text-amber-900">
                                @foreach ($switch_plan['manual'] as $line)
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1.5 inline-block h-1 w-1 shrink-0 rounded-full bg-amber-700"></span>
                                        <span>{{ $line }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </div>

            <div class="shrink-0 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelSwitchWebserver">{{ __('Cancel') }}</x-secondary-button>
                @if ($switch_plan['blocker'] === null)
                    <x-primary-button type="button" wire:click="confirmSwitchWebserver" wire:loading.attr="disabled" wire:target="confirmSwitchWebserver" class="inline-flex items-center gap-2">
                        <span wire:loading wire:target="confirmSwitchWebserver" class="inline-flex">
                            <x-spinner variant="cream" size="sm" />
                        </span>
                        {{ __('Switch to :to', ['to' => $switch_plan['to']]) }}
                    </x-primary-button>
                @endif
            </div>
        </x-modal>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
