            {{-- Per-engine sub-tab strip — Overview (state + lifecycle buttons),
                 Tools (CLI diagnostics like `caddy fmt`/`nginx -T`/`apachectl -M`),
                 Logs (live tail of access + error), Config (in-app editor with
                 validate / save / backup / restore), Info (description, license,
                 docs links). Tools/Logs/Config are only shown for active engines
                 with full controls (nginx / caddy / apache) since the others
                 don't yet have backing config layouts.
                 Note: per-panel state vars ($isEdgeProxyPanel, $isActive,
                 $unit, $pill, $version, $actionTriad, $isBlocked,
                 $blockerReason, $hasControls) are set in the dispatcher
                 (engine-panel.blade.php) so every sub-partial sees them. --}}
            <x-server-workspace-tablist :aria-label="__(':engine workspace sections', ['engine' => $info['label']])" scroll class="w-full">
                <x-server-workspace-tab
                    :id="'ws-subtab-'.$key.'-overview'"
                    :active="$engine_subtab === 'overview'"
                    :subtab-key="($optimisticEngineSubtabs ?? false) ? 'overview' : null"
                    :wire-click="($optimisticEngineSubtabs ?? false) ? null : 'setEngineSubtab(\'overview\')'"
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
                        :subtab-key="($optimisticEngineSubtabs ?? false) ? 'logs' : null"
                        :wire-click="($optimisticEngineSubtabs ?? false) ? null : 'setEngineSubtab(\'logs\')'"
                        icon="heroicon-o-document-text"
                    >
                        {{ __('Logs') }}
                    </x-server-workspace-tab>
                    {{-- Config editor lives inline in each engine's own tab
                         (reuses the shared _config.blade.php panel) rather than
                         navigating out to the standalone configuration page. --}}
                    <x-server-workspace-tab
                        :id="'ws-subtab-'.$key.'-config'"
                        :active="$engine_subtab === 'config'"
                        :subtab-key="($optimisticEngineSubtabs ?? false) ? 'config' : null"
                        :wire-click="($optimisticEngineSubtabs ?? false) ? null : 'setEngineSubtab(\'config\')'"
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
                            'modules' => ['label' => __('Modules'), 'icon' => 'heroicon-o-puzzle-piece'],
                            'cache' => ['label' => __('Cache'), 'icon' => 'heroicon-o-bolt'],
                        ],
                        'caddy' => [
                            'routes' => ['label' => __('Routes'), 'icon' => 'heroicon-o-arrow-path-rounded-square'],
                            'upstreams' => ['label' => __('Upstreams'), 'icon' => 'heroicon-o-server'],
                            'certs' => ['label' => __('Certs'), 'icon' => 'heroicon-o-lock-closed'],
                            'snippets' => ['label' => __('Snippets'), 'icon' => 'heroicon-o-code-bracket-square'],
                            'modules' => ['label' => __('Modules'), 'icon' => 'heroicon-o-puzzle-piece'],
                            'admin' => ['label' => __('Admin'), 'icon' => 'heroicon-o-cpu-chip'],
                        ],
                        'nginx' => [
                            'hosts' => ['label' => __('Hosts'), 'icon' => 'heroicon-o-server-stack'],
                            'upstreams' => ['label' => __('Upstreams'), 'icon' => 'heroicon-o-server'],
                            'certs' => ['label' => __('Certs'), 'icon' => 'heroicon-o-lock-closed'],
                            'modules' => ['label' => __('Modules'), 'icon' => 'heroicon-o-puzzle-piece'],
                            'cache' => ['label' => __('Cache'), 'icon' => 'heroicon-o-bolt'],
                            'workers' => ['label' => __('Workers'), 'icon' => 'heroicon-o-cpu-chip'],
                        ],
                        'apache' => [
                            'vhosts' => ['label' => __('Vhosts'), 'icon' => 'heroicon-o-server-stack'],
                            'modules' => ['label' => __('Modules'), 'icon' => 'heroicon-o-puzzle-piece'],
                            'cache' => ['label' => __('Cache'), 'icon' => 'heroicon-o-bolt'],
                            'certs' => ['label' => __('Certs'), 'icon' => 'heroicon-o-lock-closed'],
                            'workers' => ['label' => __('Workers'), 'icon' => 'heroicon-o-cpu-chip'],
                        ],
                        'traefik' => [
                            'routers' => ['label' => __('Routers'), 'icon' => 'heroicon-o-arrow-path-rounded-square'],
                            'services' => ['label' => __('Services'), 'icon' => 'heroicon-o-server'],
                            'middlewares' => ['label' => __('Middlewares'), 'icon' => 'heroicon-o-shield-check'],
                            'entrypoints' => ['label' => __('Entry points'), 'icon' => 'heroicon-o-signal'],
                            'tcprouters' => ['label' => __('TCP routers'), 'icon' => 'heroicon-o-arrows-right-left'],
                            'tcpservices' => ['label' => __('TCP services'), 'icon' => 'heroicon-o-server-stack'],
                            'udprouters' => ['label' => __('UDP routers'), 'icon' => 'heroicon-o-bolt'],
                            'udpservices' => ['label' => __('UDP services'), 'icon' => 'heroicon-o-server-stack'],
                            'tls' => ['label' => __('TLS'), 'icon' => 'heroicon-o-lock-closed'],
                            'providers' => ['label' => __('Providers'), 'icon' => 'heroicon-o-cube'],
                            'static' => ['label' => __('Static'), 'icon' => 'heroicon-o-cog-6-tooth'],
                            'dynamic' => ['label' => __('Dynamic'), 'icon' => 'heroicon-o-document-duplicate'],
                        ],
                        'haproxy' => [
                            'frontends' => ['label' => __('Frontends'), 'icon' => 'heroicon-o-arrow-path-rounded-square'],
                            'backends' => ['label' => __('Backends'), 'icon' => 'heroicon-o-server-stack'],
                            'ssl' => ['label' => __('SSL'), 'icon' => 'heroicon-o-lock-closed'],
                            'runtime' => ['label' => __('Runtime'), 'icon' => 'heroicon-o-cpu-chip'],
                        ],
                        'envoy' => [
                            'listeners' => ['label' => __('Listeners'), 'icon' => 'heroicon-o-signal'],
                            'virtualhosts' => ['label' => __('Virtual hosts'), 'icon' => 'heroicon-o-server-stack'],
                            'clusters' => ['label' => __('Clusters'), 'icon' => 'heroicon-o-server'],
                            'stats' => ['label' => __('Stats'), 'icon' => 'heroicon-o-chart-bar'],
                            'runtime' => ['label' => __('Runtime'), 'icon' => 'heroicon-o-cpu-chip'],
                            'static' => ['label' => __('Static'), 'icon' => 'heroicon-o-cog-6-tooth'],
                        ],
                        'openresty' => [
                            'servers' => ['label' => __('Servers'), 'icon' => 'heroicon-o-server-stack'],
                            'upstreams' => ['label' => __('Upstreams'), 'icon' => 'heroicon-o-server'],
                            'runtime' => ['label' => __('Runtime'), 'icon' => 'heroicon-o-cpu-chip'],
                            'static' => ['label' => __('Static'), 'icon' => 'heroicon-o-cog-6-tooth'],
                        ],
                        default => [],
                    };
                @endphp
                @if (($isActive || $isEdgeProxyPanel) && $liveStateSubTabs !== [])
                    @foreach ($liveStateSubTabs as $stKey => $stInfo)
                        <x-server-workspace-tab
                            :id="'ws-subtab-'.$key.'-'.$stKey"
                            :active="$engine_subtab === $stKey"
                            :subtab-key="($optimisticEngineSubtabs ?? false) ? $stKey : null"
                            :wire-click="($optimisticEngineSubtabs ?? false) ? null : 'setEngineSubtab(\''.$stKey.'\')'"
                            :icon="$stInfo['icon']"
                        >
                            {{ $stInfo['label'] }}
                            @if (in_array($stKey, $comingSoonSubtabKeys ?? [], true))
                                {{-- These tabs stay clickable (roadmap teaser) but aren't backed
                                     by a real panel yet — flag them like the webserver picker. --}}
                                <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ __('Soon') }}
                                </span>
                            @endif
                        </x-server-workspace-tab>
                    @endforeach
                @endif
                <x-server-workspace-tab
                    :id="'ws-subtab-'.$key.'-info'"
                    :active="$engine_subtab === 'info'"
                    :subtab-key="($optimisticEngineSubtabs ?? false) ? 'info' : null"
                    :wire-click="($optimisticEngineSubtabs ?? false) ? null : 'setEngineSubtab(\'info\')'"
                    icon="heroicon-o-information-circle"
                >
                    {{ __('Info') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
