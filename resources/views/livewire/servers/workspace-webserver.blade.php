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
    {{-- The legacy "Command output" block (workspace-flashes with `command_output`)
         is deliberately omitted on this workspace — output for runAllowlistedAction
         calls now lands in the manage_action ConsoleAction banner rendered below
         (same partial the webserver_switch flow uses). --}}
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
        >
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Overview') }}
            </span>
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
            >
                <span class="inline-flex items-center gap-2">
                    <x-dynamic-component :component="$info['icon']" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ $info['label'] }}
                    @if ($isActiveEngine)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ $isEdgeProxyTab ? __('Edge') : __('Active') }}</span>
                    @elseif (! $isEdgeProxyTab && $preflight->isBlocked($server, $key))
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">{{ __('Unavailable') }}</span>
                    @endif
                </span>
            </x-server-workspace-tab>
        @endforeach
        <x-server-workspace-tab
            id="ws-tab-advanced"
            :active="$workspace_tab === 'advanced'"
            wire:click="setWorkspaceTab('advanced')"
        >
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Advanced') }}
            </span>
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
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <div class="flex items-center gap-2">
                            <x-dynamic-component :component="$activeInfo['icon']" class="h-5 w-5 shrink-0 text-brand-forest" />
                            <h3 class="text-base font-semibold text-brand-ink">{{ $activeInfo['label'] }}</h3>
                        </div>
                        @if ($activeVersion !== '')
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $activeVersion }}</p>
                        @endif
                    </div>
                    @if ($activeUnit !== null)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $activePill['classes'] }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $activePill['dot'] }}"></span>
                            {{ $activePill['label'] }}
                        </span>
                    @endif
                </div>

                @if ($opsReady && ! $isDeployer && ! empty($activeLifecycleGroups))
                    {{-- Grouped lifecycle controls (Health / Service / Boot) so
                         start/stop/enable/disable don't visually merge with the
                         test/reload/restart "health" row. Mirrors the layout on
                         the per-engine Overview sub-tab. --}}
                    <div class="mt-6 space-y-3">
                        @foreach ($activeLifecycleGroups as $groupKey => $group)
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="w-16 shrink-0 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $group['label'] }}</span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($group['rows'] as [$actionKey, $dangerous])
                                        @if (! empty($serviceActions[$actionKey]))
                                            @php $action = $serviceActions[$actionKey]; @endphp
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), {{ $dangerous ? 'true' : 'false' }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openConfirmActionModal,runAllowlistedAction"
                                                @class([
                                                    'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40 disabled:opacity-60',
                                                    'border-brand-ink/15 bg-white text-brand-ink' => ! $dangerous,
                                                    'border-rose-200 bg-white text-rose-800 hover:bg-rose-50' => $dangerous,
                                                ])
                                            >
                                                <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                                {{ $action['label'] }}
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if (! empty($activeCliTools))
                        {{-- Tools row — read-only per-engine CLI helpers. Same
                             buttons that live on the engine Tools sub-tab, kept
                             here so the operator doesn't have to navigate away
                             for a one-shot `caddy version` or `nginx -T`. --}}
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <span class="w-16 shrink-0 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Tools') }}</span>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($activeCliTools as [$actionKey, $dangerous])
                                    @if (! empty($serviceActions[$actionKey]))
                                        @php $action = $serviceActions[$actionKey]; @endphp
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), {{ $dangerous ? 'true' : 'false' }})"
                                            wire:loading.attr="disabled"
                                            wire:target="openConfirmActionModal,runAllowlistedAction"
                                            @class([
                                                'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40 disabled:opacity-60',
                                                'border-brand-ink/15 bg-white text-brand-ink' => ! $dangerous,
                                                'border-rose-200 bg-white text-rose-800 hover:bg-rose-50' => $dangerous,
                                            ])
                                            title="{{ $action['description'] ?? '' }}"
                                        >
                                            <x-heroicon-o-command-line class="h-4 w-4 opacity-80" aria-hidden="true" />
                                            {{ $action['label'] }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
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
                                @disabled($isDeployer || ! $opsReady || $isBlocked)
                                @class([
                                    'mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition disabled:opacity-60',
                                    'bg-brand-forest text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90' => ! $isBlocked,
                                    'cursor-not-allowed bg-brand-sand/40 text-brand-mist' => $isBlocked,
                                ])
                                title="{{ $isBlocked ? __('Unavailable — see preflight blocker') : '' }}"
                            >
                                <span class="inline-flex items-center gap-1.5" wire:loading.remove wire:target="openSwitchWebserver">
                                    @if ($isBlocked)
                                        <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
                                        {{ __('Unavailable') }}
                                    @else
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                        {{ __('Switch to :name', ['name' => $info['label']]) }}
                                    @endif
                                </span>
                                <span class="inline-flex items-center gap-1.5" wire:loading wire:target="openSwitchWebserver">
                                    <x-spinner variant="cream" size="sm" />
                                    {{ __('Preparing…') }}
                                </span>
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
                                @disabled($isDeployer || ! $opsReady || $inflightEdge)
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
                                @disabled($isDeployer || ! $opsReady || $inflightEdge || $inflightSwitch)
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
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-presentation-chart-line class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Overview') }}
                    </span>
                </x-server-workspace-tab>
                @if ($hasControls)
                    <x-server-workspace-tab
                        :id="'ws-subtab-'.$key.'-tools'"
                        :active="$engine_subtab === 'tools'"
                        wire:click="setEngineSubtab('tools')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <x-heroicon-o-command-line class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Tools') }}
                        </span>
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        :id="'ws-subtab-'.$key.'-logs'"
                        :active="$engine_subtab === 'logs'"
                        wire:click="setEngineSubtab('logs')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <x-heroicon-o-document-text class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Logs') }}
                        </span>
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        :id="'ws-subtab-'.$key.'-config'"
                        :active="$engine_subtab === 'config'"
                        wire:click="setEngineSubtab('config')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Config') }}
                        </span>
                    </x-server-workspace-tab>
                @endif
                <x-server-workspace-tab
                    :id="'ws-subtab-'.$key.'-info'"
                    :active="$engine_subtab === 'info'"
                    wire:click="setEngineSubtab('info')"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-information-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Info') }}
                    </span>
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            @if ($engine_subtab === 'overview')
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <div class="flex items-center gap-2">
                            <x-dynamic-component :component="$info['icon']" class="h-5 w-5 shrink-0 text-brand-forest" />
                            <h3 class="text-base font-semibold text-brand-ink">{{ $info['label'] }}</h3>
                        </div>
                        @if ($version !== '')
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $version }}</p>
                        @endif
                        @if (! $isActive)
                            <p class="mt-2 text-sm text-brand-moss">
                                {{ __('Not the active webserver on this server.') }}
                            </p>
                        @endif
                    </div>
                    @if ($isActive && $unit !== null)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $pill['classes'] }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                            {{ $pill['label'] }}
                        </span>
                    @endif
                </div>

                @if ($isActive)
                    @if ($opsReady && ! $isDeployer)
                        @php $lifecycleGroups = $lifecycleGroupsFor($key); @endphp
                        @if (! empty($lifecycleGroups))
                            <div class="mt-6 space-y-3">
                                @foreach ($lifecycleGroups as $groupKey => $group)
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="w-16 shrink-0 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $group['label'] }}</span>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($group['rows'] as [$actionKey, $dangerous])
                                                @if (! empty($serviceActions[$actionKey]))
                                                    @php $action = $serviceActions[$actionKey]; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), {{ $dangerous ? 'true' : 'false' }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openConfirmActionModal,runAllowlistedAction"
                                                        @class([
                                                            'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium shadow-sm hover:bg-brand-sand/40 disabled:opacity-60',
                                                            'border-brand-ink/15 bg-white text-brand-ink' => ! $dangerous,
                                                            'border-rose-200 bg-white text-rose-800 hover:bg-rose-50' => $dangerous,
                                                        ])
                                                    >
                                                        <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                                        {{ $action['label'] }}
                                                    </button>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
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
                                @disabled($isDeployer || ! $opsReady || $isBlocked)
                                @class([
                                    'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:opacity-60',
                                    'bg-brand-forest text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90' => ! $isBlocked,
                                    'cursor-not-allowed bg-brand-sand/40 text-brand-mist' => $isBlocked,
                                ])
                            >
                                <span class="inline-flex items-center gap-2" wire:loading.remove wire:target="openSwitchWebserver">
                                    @if ($isBlocked)
                                        <x-heroicon-o-no-symbol class="h-4 w-4" />
                                        {{ __('Unavailable') }}
                                    @else
                                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                                        {{ __('Switch to :name', ['name' => $info['label']]) }}
                                    @endif
                                </span>
                                <span class="inline-flex items-center gap-2" wire:loading wire:target="openSwitchWebserver">
                                    <x-spinner variant="cream" size="sm" />
                                    {{ __('Preparing…') }}
                                </span>
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
            @if ($engine_subtab === 'tools' && $isActive && $engineHasFullControls($key))
                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="max-w-2xl">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine — diagnostics & tools', ['engine' => $info['label']]) }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Read-only CLI helpers for inspecting how :engine is built and configured. Output appears below the page in the action result panel.', ['engine' => $info['label']]) }}
                        </p>
                    </div>

                    @if (! $opsReady || $isDeployer)
                        <p class="mt-4 text-sm text-brand-moss">{{ __('Tools require ready ops access and a non-deployer role.') }}</p>
                    @else
                        @php $tools = $cliToolsFor($key); @endphp
                        <div class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($tools as [$actionKey, $dangerous])
                                @if (! empty($serviceActions[$actionKey]))
                                    @php $action = $serviceActions[$actionKey]; @endphp
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), {{ $dangerous ? 'true' : 'false' }})"
                                        wire:loading.attr="disabled"
                                        wire:target="openConfirmActionModal,runAllowlistedAction"
                                        @class([
                                            'flex items-start gap-3 rounded-lg border px-3 py-2.5 text-left text-sm hover:bg-brand-sand/40 disabled:opacity-60',
                                            'border-brand-ink/15 bg-white text-brand-ink' => ! $dangerous,
                                            'border-rose-200 bg-white text-rose-800 hover:bg-rose-50' => $dangerous,
                                        ])
                                    >
                                        <x-heroicon-o-command-line class="mt-0.5 h-4 w-4 shrink-0 opacity-80" aria-hidden="true" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium">{{ $action['label'] }}</span>
                                            <span class="mt-0.5 block text-[11px] {{ $dangerous ? 'text-rose-700' : 'text-brand-moss' }}">{{ $action['description'] ?? '' }}</span>
                                        </span>
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

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
                                <select wire:change="refreshWebserverLog(null, $event.target.value)" class="rounded-md border border-brand-ink/15 bg-white px-1.5 py-0.5 text-[11px] font-medium text-brand-ink">
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
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="max-w-2xl">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine config editor', ['engine' => $info['label']]) }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Edit the main config or any per-site fragment. Saving snapshots the live file to _dply_backups/ first, then atomically replaces it and runs the engine validator.') }}</p>
                        </div>
                        <button type="button" wire:click="validateWebserverConfig" class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                            <x-heroicon-o-shield-check class="h-3.5 w-3.5" />
                            {{ __('Validate on-disk config') }}
                        </button>
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
                                            <p class="truncate font-mono text-xs text-brand-moss">{{ $config_selected_path }}</p>
                                            @if ($config_truncated_on_load)
                                                <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-amber-200">
                                                    <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                                                    {{ __('Truncated on load — saving is disabled') }}
                                                </p>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button type="button" wire:click="loadWebserverConfig(@js($config_selected_path))" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">
                                                <x-heroicon-o-arrow-path class="h-3 w-3" />
                                                {{ __('Reload') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="saveWebserverConfig"
                                                @disabled($config_truncated_on_load)
                                                class="inline-flex items-center gap-1 rounded-md border border-brand-forest bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-50"
                                            >
                                                <x-heroicon-o-cloud-arrow-up class="h-3 w-3" />
                                                {{ __('Save + validate') }}
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

                                    {{-- Backups --}}
                                    @if (! empty($config_backups))
                                        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white">
                                            <div class="flex items-center justify-between border-b border-brand-ink/10 px-3 py-2">
                                                <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Backups') }}</span>
                                                <span class="text-[10px] text-brand-mist">{{ __(':n kept — newest first', ['n' => count($config_backups)]) }}</span>
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
                    <x-primary-button type="button" wire:click="confirmSwitchWebserver" wire:loading.attr="disabled" wire:target="confirmSwitchWebserver">
                        <span wire:loading.remove wire:target="confirmSwitchWebserver">{{ __('Switch to :to', ['to' => $switch_plan['to']]) }}</span>
                        <span wire:loading wire:target="confirmSwitchWebserver">{{ __('Queueing…') }}</span>
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
