        @php
            $activeInfo = $webserverCatalog[$activeWebserver] ?? null;
            $activeUnit = $activeInfo !== null ? $unitFor($activeInfo['systemd']) : null;
            $activePill = $statePill($activeUnit['active_state'] ?? null);
            $activeVersion = $versionFor($activeWebserver);
            $activeLifecycleGroups = $lifecycleGroupsFor($activeWebserver);
            $activeCliTools = $cliToolsFor($activeWebserver);
        @endphp

        @if ($activeInfo !== null)
            <div class="{{ $card }}">
                {{-- Engine head — dense, like every other panel head in the
                     workspace: icon + engine, version as the count pill, the
                     systemd state pill riding the actions slot. --}}
                <x-workspace-panel-head
                    dense
                    :icon="$activeInfo['icon']"
                    :title="$activeInfo['label']"
                    :count="$activeVersion !== '' ? $activeVersion : null"
                    :note="__('Active on port :port. Lifecycle actions below act on this engine.', ['port' => 80])"
                    class="border-b border-brand-ink/10"
                >
                    @if ($activeUnit !== null)
                        <x-slot:actions>
                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-[11px] font-medium ring-1 ring-brand-ink/10 {{ $activePill['classes'] }}">
                                <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $activePill['dot'] }}"></span>
                                {{ $activePill['label'] }}
                            </span>
                        </x-slot:actions>
                    @endif
                </x-workspace-panel-head>

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
                            <div class="bg-white px-4 py-3 sm:px-5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ $header['title'] }}</p>
                                        @if ($header['sub'] !== '')
                                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ $header['sub'] }}</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($visibleRows as [$actionKey, $dangerous])
                                            @if (! empty($serviceActions[$actionKey]))
                                                @php $action = $serviceActions[$actionKey]; @endphp
                                                @include('livewire.servers.partials.webserver._service-action-button', [
                                                    'actionKey' => $actionKey,
                                                    'dangerous' => $dangerous,
                                                    'action' => $action,
                                                    'actionInFlight' => $actionInFlight,
                                                    'variant' => 'lifecycle',
                                                ])
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
                            <div class="bg-brand-sand/15 px-4 py-3 sm:px-5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Tools') }}</p>
                                        <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Read-only diagnostics — version, config dumps, module list, etc.') }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($activeCliTools as [$actionKey, $dangerous])
                                            @if (! empty($serviceActions[$actionKey]))
                                                @php $action = $serviceActions[$actionKey]; @endphp
                                                @include('livewire.servers.partials.webserver._service-action-button', [
                                                    'actionKey' => $actionKey,
                                                    'dangerous' => $dangerous,
                                                    'action' => $action,
                                                    'actionInFlight' => $actionInFlight,
                                                    'variant' => 'tools',
                                                ])
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

        <div class="grid gap-2 border-b border-brand-ink/10 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
            <button
                type="button"
                wire:click="setWorkspaceTab('change')"
                class="group flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 text-left transition hover:border-brand-forest/30 hover:bg-brand-sand/30"
            >
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest ring-1 ring-brand-ink/10">
                    <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Change webserver') }}</span>
                    <span class="mt-0.5 block text-[12px] leading-5 text-brand-moss">{{ __('Switch nginx, Caddy, Apache, or OpenLiteSpeed on port :80.', ['port' => 80]) }}</span>
                </span>
            </button>
            <a
                href="{{ route('servers.edge-proxy', $server) }}"
                wire:navigate
                class="group flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 transition hover:border-brand-forest/30 hover:bg-brand-sand/30"
            >
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest ring-1 ring-brand-ink/10">
                    <x-heroicon-o-arrow-path-rounded-square class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Edge proxy') }}</span>
                    <span class="mt-0.5 block text-[12px] leading-5 text-brand-moss">
                        @if ($activeEdgeProxy !== null)
                            {{ __(':name is routing :80 — open controls or remove.', ['name' => $edgeProxyCatalog[$activeEdgeProxy]['label'] ?? ucfirst($activeEdgeProxy), 'port' => 80]) }}
                        @else
                            {{ __('Optional Traefik, HAProxy, or more in front of port :80.', ['port' => 80]) }}
                        @endif
                    </span>
                </span>
            </a>
            <button
                type="button"
                wire:click="setWorkspaceTab('health')"
                class="group flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 text-left transition hover:border-brand-forest/30 hover:bg-brand-sand/30 sm:col-span-2"
            >
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest ring-1 ring-brand-ink/10">
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Health checks') }}</span>
                    <span class="mt-0.5 block text-[12px] leading-5 text-brand-moss">{{ __('TLS inventory, site smoke tests, and config drift against dply templates.') }}</span>
                </span>
            </button>
        </div>

        @if ($activeInfo !== null)
            <div class="{{ $card }} px-4 py-2.5 sm:px-5">
                <p class="text-xs text-brand-moss">
                    {{ __('Deep config, logs, and live-state inspectors for :engine live on the :engine tab.', ['engine' => $activeInfo['label']]) }}
                    <button type="button" wire:click="setWorkspaceTab('{{ $activeWebserver }}')" class="font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:text-brand-forest/80">
                        {{ __('Open :engine workspace', ['engine' => $activeInfo['label']]) }}
                    </button>
                </p>
            </div>
        @endif
