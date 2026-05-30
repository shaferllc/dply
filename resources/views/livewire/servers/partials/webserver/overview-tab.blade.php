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

        <div class="grid gap-3 sm:grid-cols-2">
            <button
                type="button"
                wire:click="setWorkspaceTab('change')"
                class="group {{ $card }} flex items-start gap-3 p-5 text-left transition hover:border-brand-forest/25 hover:shadow-md sm:p-6"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Change webserver') }}</span>
                    <span class="mt-1 block text-[13px] leading-5 text-brand-moss">{{ __('Switch engines or add an edge proxy in front of port :80.', ['port' => 80]) }}</span>
                </span>
            </button>
            <button
                type="button"
                wire:click="setWorkspaceTab('health')"
                class="group {{ $card }} flex items-start gap-3 p-5 text-left transition hover:border-brand-forest/25 hover:shadow-md sm:p-6"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Health checks') }}</span>
                    <span class="mt-1 block text-[13px] leading-5 text-brand-moss">{{ __('TLS inventory, site smoke tests, and config drift against dply templates.') }}</span>
                </span>
            </button>
        </div>

        @if ($activeInfo !== null)
            <div class="{{ $card }} p-5 sm:p-6">
                <p class="text-sm text-brand-moss">
                    {{ __('Deep config, logs, and live-state inspectors for :engine live on the :engine tab.', ['engine' => $activeInfo['label']]) }}
                    <button type="button" wire:click="setWorkspaceTab('{{ $activeWebserver }}')" class="font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:text-brand-forest/80">
                        {{ __('Open :engine workspace', ['engine' => $activeInfo['label']]) }}
                    </button>
                </p>
            </div>
        @endif
