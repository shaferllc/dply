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

        {{-- =================================================================
             CROSS-ENGINE TLS DASHBOARD. Single SSH sweep across every
             known cert path (Let's Encrypt, Caddy local CA, per-engine
             ssl dirs) with openssl-parsed expiry, sorted soonest-first.
             Cached 60s on the service side; the Rescan button forces a
             fresh probe.
             ================================================================= --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-8">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('TLS certificates on this server') }}</h3>
                    <p class="mt-0.5 text-[12px] text-brand-moss">
                        {{ __('Server-cert inventory across Let\'s Encrypt + Caddy local CA + every per-engine ssl directory, sorted by expiry. CA bundles and OS trust-store certs are filtered out.') }}
                        @if ($tls_certs_scanned_at_iso)
                            <span class="ml-1 text-brand-mist">·
                                {{ __('Scanned :time', ['time' => \Illuminate\Support\Carbon::parse($tls_certs_scanned_at_iso)->diffForHumans()]) }}
                            </span>
                        @endif
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="refreshTlsCertsDashboard"
                    wire:loading.attr="disabled"
                    wire:target="refreshTlsCertsDashboard,loadTlsCertsDashboard"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="refreshTlsCertsDashboard,loadTlsCertsDashboard" class="inline-flex">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                    </span>
                    <span wire:loading wire:target="refreshTlsCertsDashboard,loadTlsCertsDashboard" class="inline-flex">
                        <x-spinner class="h-3.5 w-3.5" />
                    </span>
                    {{ __('Rescan') }}
                </button>
            </div>

            @if ($tls_certs_error)
                <div class="border-b border-rose-200 bg-rose-50/70 px-6 py-3 text-sm text-rose-900 sm:px-8">
                    {{ $tls_certs_error }}
                </div>
            @endif

            @if (! $tls_certs_loaded)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <span wire:loading wire:target="loadTlsCertsDashboard,refreshTlsCertsDashboard" class="inline-flex items-center gap-2">
                        <x-spinner class="h-3.5 w-3.5" /> {{ __('Scanning certs…') }}
                    </span>
                    <span wire:loading.remove wire:target="loadTlsCertsDashboard,refreshTlsCertsDashboard">
                        {{ __('Click "Rescan" to run the SSH sweep.') }}
                    </span>
                </div>
            @elseif ($tls_certs_unreadable)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    {{ __('Could not run the cert scan over SSH. Check that the deploy user has passwordless sudo for `find` + `openssl`.') }}
                </div>
            @elseif (empty($tls_certs))
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <x-heroicon-o-shield-check class="mx-auto h-6 w-6 text-brand-mist" />
                    <p class="mt-2">{{ __('No server certificates found under the scanned paths.') }}</p>
                </div>
            @else
                @php
                    $urgencyCounts = ['expired' => 0, 'danger' => 0, 'warn' => 0, 'ok' => 0, 'unknown' => 0];
                    foreach ($tls_certs as $c) {
                        $u = (string) ($c['urgency'] ?? 'unknown');
                        $urgencyCounts[$u] = ($urgencyCounts[$u] ?? 0) + 1;
                    }
                @endphp
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-6 py-3 text-[11px] sm:px-8">
                    <span class="text-brand-moss">{{ __(':n cert(s)', ['n' => count($tls_certs)]) }}</span>
                    @if ($urgencyCounts['expired'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-800">
                            <x-heroicon-o-x-circle class="h-3 w-3" /> {{ $urgencyCounts['expired'] }} {{ __('expired') }}
                        </span>
                    @endif
                    @if ($urgencyCounts['danger'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700">
                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ $urgencyCounts['danger'] }} {{ __('< 14d') }}
                        </span>
                    @endif
                    @if ($urgencyCounts['warn'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">
                            <x-heroicon-o-clock class="h-3 w-3" /> {{ $urgencyCounts['warn'] }} {{ __('< 60d') }}
                        </span>
                    @endif
                    @if ($urgencyCounts['ok'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                            <x-heroicon-o-check-circle class="h-3 w-3" /> {{ $urgencyCounts['ok'] }} {{ __('healthy') }}
                        </span>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-brand-sand/20 text-[11px] uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-6 py-2 font-medium sm:px-8">{{ __('Path') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Subject') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Issuer') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Engine') }}</th>
                                <th class="px-4 py-2 font-medium text-right">{{ __('Expires') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($tls_certs as $cert)
                                @php
                                    $urgency = (string) ($cert['urgency'] ?? 'unknown');
                                    $days = $cert['days_until_expiry'] ?? null;
                                @endphp
                                <tr>
                                    <td class="break-all px-6 py-2 font-mono text-[11px] text-brand-ink sm:px-8">{{ $cert['path'] }}</td>
                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $cert['subject'] ?: '—' }}</td>
                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $cert['issuer'] ?: '—' }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $cert['engine_hint'] ?? 'other' }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        @if ($cert['error'])
                                            <span class="text-[11px] text-rose-700" title="{{ $cert['error'] }}">—</span>
                                        @else
                                            <span @class([
                                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1',
                                                'bg-rose-100 text-rose-900 ring-rose-200' => $urgency === 'expired',
                                                'bg-rose-50 text-rose-700 ring-rose-200' => $urgency === 'danger',
                                                'bg-amber-50 text-amber-800 ring-amber-200' => $urgency === 'warn',
                                                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $urgency === 'ok',
                                                'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $urgency === 'unknown',
                                            ])>
                                                @if ($urgency === 'expired')
                                                    {{ __('expired :n d ago', ['n' => abs((int) $days)]) }}
                                                @elseif ($days !== null)
                                                    {{ __(':n d', ['n' => (int) $days]) }}
                                                @else
                                                    —
                                                @endif
                                            </span>
                                            @if (! empty($cert['not_after']))
                                                <p class="mt-0.5 text-[10px] text-brand-mist tabular-nums">{{ $cert['not_after'] }}</p>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        {{-- =================================================================
             SITE SMOKE TEST. Operator-triggered loopback curl through the
             active webserver for every Site on this server. Surfaces
             routing problems (404 / 502), TLS gaps (HTTPS down while HTTP
             redirects), or full-on outages (both schemes errored).
             ================================================================= --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-8">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Site smoke test') }}</h3>
                    <p class="mt-0.5 text-[12px] text-brand-moss">
                        {{ __('Curls every Site\'s primary hostname through 127.0.0.1 (HTTP + HTTPS with --resolve so SNI matches). Sorted worst-first.') }}
                        @if ($smoke_scanned_at_iso)
                            <span class="ml-1 text-brand-mist">·
                                {{ __('Ran :time', ['time' => \Illuminate\Support\Carbon::parse($smoke_scanned_at_iso)->diffForHumans()]) }}
                            </span>
                        @endif
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="runSmokeTest"
                    wire:loading.attr="disabled"
                    wire:target="runSmokeTest"
                    @disabled($isDeployer || ! $opsReady || $actionInFlight)
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="runSmokeTest" class="inline-flex">
                        <x-heroicon-o-bolt class="h-3.5 w-3.5" />
                    </span>
                    <span wire:loading wire:target="runSmokeTest" class="inline-flex">
                        <x-spinner variant="cream" class="h-3.5 w-3.5" />
                    </span>
                    {{ __('Run smoke test') }}
                </button>
            </div>

            @if ($smoke_error)
                <div class="border-b border-rose-200 bg-rose-50/70 px-6 py-3 text-sm text-rose-900 sm:px-8">
                    {{ $smoke_error }}
                </div>
            @endif

            @if (! $smoke_loaded)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <span wire:loading wire:target="runSmokeTest" class="inline-flex items-center gap-2">
                        <x-spinner class="h-3.5 w-3.5" /> {{ __('Probing sites…') }}
                    </span>
                    <span wire:loading.remove wire:target="runSmokeTest">
                        <x-heroicon-o-bolt class="mx-auto h-6 w-6 text-brand-mist" />
                        <p class="mt-2">{{ __('Click "Run smoke test" to probe every site.') }}</p>
                    </span>
                </div>
            @elseif ($smoke_total_sites === 0)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <x-heroicon-o-folder-open class="mx-auto h-6 w-6 text-brand-mist" />
                    <p class="mt-2">{{ __('No sites on this server yet.') }}</p>
                </div>
            @else
                @php
                    $smokeCounts = ['down' => 0, 'error' => 0, 'warn' => 0, 'ok' => 0, 'unknown' => 0];
                    foreach ($smoke_results as $r) {
                        $u = (string) ($r['urgency'] ?? 'unknown');
                        $smokeCounts[$u] = ($smokeCounts[$u] ?? 0) + 1;
                    }
                @endphp
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-6 py-3 text-[11px] sm:px-8">
                    <span class="text-brand-moss">{{ __(':probed of :total probed', ['probed' => $smoke_probed, 'total' => $smoke_total_sites]) }}@if ($smoke_truncated) <span class="text-amber-700">{{ __('(truncated)') }}</span>@endif</span>
                    @if ($smokeCounts['down'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-800">
                            <x-heroicon-o-x-circle class="h-3 w-3" /> {{ $smokeCounts['down'] }} {{ __('down') }}
                        </span>
                    @endif
                    @if ($smokeCounts['error'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700">
                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ $smokeCounts['error'] }} {{ __('5xx') }}
                        </span>
                    @endif
                    @if ($smokeCounts['warn'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">
                            <x-heroicon-o-question-mark-circle class="h-3 w-3" /> {{ $smokeCounts['warn'] }} {{ __('warn') }}
                        </span>
                    @endif
                    @if ($smokeCounts['ok'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                            <x-heroicon-o-check-circle class="h-3 w-3" /> {{ $smokeCounts['ok'] }} {{ __('ok') }}
                        </span>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-brand-sand/20 text-[11px] uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-6 py-2 font-medium sm:px-8">{{ __('Site') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Hostname') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('HTTP') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('HTTPS') }}</th>
                                <th class="px-4 py-2 font-medium text-right">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($smoke_results as $r)
                                @php
                                    $urgency = (string) ($r['urgency'] ?? 'unknown');
                                    $httpStatus = $r['http_status'] ?? null;
                                    $httpsStatus = $r['https_status'] ?? null;
                                    $httpClass = $httpStatus === null
                                        ? 'text-rose-700'
                                        : ($httpStatus >= 500 ? 'text-rose-700' : ($httpStatus >= 400 ? 'text-amber-700' : ($httpStatus >= 300 ? 'text-brand-moss' : 'text-emerald-700')));
                                    $httpsClass = $httpsStatus === null
                                        ? 'text-rose-700'
                                        : ($httpsStatus >= 500 ? 'text-rose-700' : ($httpsStatus >= 400 ? 'text-amber-700' : ($httpsStatus >= 300 ? 'text-brand-moss' : 'text-emerald-700')));
                                @endphp
                                <tr>
                                    <td class="px-6 py-2 sm:px-8">
                                        <a
                                            href="{{ route('sites.show', ['server' => $server, 'site' => $r['site_id']]) }}"
                                            class="font-medium text-brand-ink hover:underline"
                                        >{{ $r['site_name'] }}</a>
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $r['hostname'] }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        <span class="font-mono {{ $httpClass }}">{{ $httpStatus ?? '—' }}</span>
                                        @if (isset($r['http_time_ms']))
                                            <span class="ml-1 text-[10px] text-brand-mist tabular-nums">{{ $r['http_time_ms'] }}ms</span>
                                        @endif
                                        @if (! empty($r['http_error']))
                                            <p class="mt-0.5 text-[10px] text-rose-700">{{ $r['http_error'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-xs">
                                        <span class="font-mono {{ $httpsClass }}">{{ $httpsStatus ?? '—' }}</span>
                                        @if (isset($r['https_time_ms']))
                                            <span class="ml-1 text-[10px] text-brand-mist tabular-nums">{{ $r['https_time_ms'] }}ms</span>
                                        @endif
                                        @if (! empty($r['https_status']) && empty($r['https_tls_ok']))
                                            <span class="ml-1 inline-flex items-center gap-0.5 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800" title="TLS verification failed (-k accepted the cert anyway)">
                                                <x-heroicon-o-shield-exclamation class="h-3 w-3" /> tls
                                            </span>
                                        @endif
                                        @if (! empty($r['https_error']))
                                            <p class="mt-0.5 text-[10px] text-rose-700">{{ $r['https_error'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1',
                                            'bg-rose-100 text-rose-900 ring-rose-200' => $urgency === 'down',
                                            'bg-rose-50 text-rose-700 ring-rose-200' => $urgency === 'error',
                                            'bg-amber-50 text-amber-800 ring-amber-200' => $urgency === 'warn',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $urgency === 'ok',
                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $urgency === 'unknown',
                                        ])>{{ $urgency }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        {{-- =================================================================
             CONFIG DRIFT DETECTOR. Compares each per-site config on disk
             against what dply's per-site provisioner would emit right now.
             Drift here means edits that'll get clobbered on the next Site
             Apply / webserver switch.
             ================================================================= --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-8">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Config drift') }}</h3>
                    <p class="mt-0.5 text-[12px] text-brand-moss">
                        {{ __('Compares each Site\'s on-disk webserver config against the canonical content dply\'s provisioner would emit. Drifted entries are what the next Site Apply would rewrite.') }}
                        @if ($drift_scanned_at_iso)
                            <span class="ml-1 text-brand-mist">·
                                {{ __('Checked :time', ['time' => \Illuminate\Support\Carbon::parse($drift_scanned_at_iso)->diffForHumans()]) }}
                            </span>
                        @endif
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="refreshDriftDetector"
                    wire:loading.attr="disabled"
                    wire:target="refreshDriftDetector,loadDriftDetector"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="refreshDriftDetector,loadDriftDetector" class="inline-flex">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                    </span>
                    <span wire:loading wire:target="refreshDriftDetector,loadDriftDetector" class="inline-flex">
                        <x-spinner class="h-3.5 w-3.5" />
                    </span>
                    {{ __('Recheck') }}
                </button>
            </div>

            @if ($drift_error)
                <div class="border-b border-rose-200 bg-rose-50/70 px-6 py-3 text-sm text-rose-900 sm:px-8">
                    {{ $drift_error }}
                </div>
            @endif

            @if (! $drift_loaded)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <span wire:loading wire:target="loadDriftDetector,refreshDriftDetector" class="inline-flex items-center gap-2">
                        <x-spinner class="h-3.5 w-3.5" /> {{ __('Comparing on-disk vs provisioner output…') }}
                    </span>
                    <span wire:loading.remove wire:target="loadDriftDetector,refreshDriftDetector">
                        {{ __('Click "Recheck" to run the comparison.') }}
                    </span>
                </div>
            @elseif ($drift_unsupported)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <x-heroicon-o-information-circle class="mx-auto h-6 w-6 text-brand-mist" />
                    <p class="mt-2">{{ __('Drift detection is only supported for nginx / Caddy / Apache / OpenLiteSpeed. The active engine (:engine) has no per-site builder dply can diff against.', ['engine' => $drift_engine ?? 'none']) }}</p>
                </div>
            @elseif ($drift_total_sites === 0)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <x-heroicon-o-folder-open class="mx-auto h-6 w-6 text-brand-mist" />
                    <p class="mt-2">{{ __('No sites on this server yet — no configs to compare.') }}</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-6 py-3 text-[11px] sm:px-8">
                    <span class="text-brand-moss">{{ __(':total sites compared (:engine)', ['total' => count($drift_results), 'engine' => $drift_engine]) }}@if ($drift_truncated) <span class="text-amber-700">{{ __('(truncated to first 60)') }}</span>@endif</span>
                    @if ($drift_count > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">
                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ $drift_count }} {{ __('drifted') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                            <x-heroicon-o-check-circle class="h-3 w-3" /> {{ __('all in sync') }}
                        </span>
                    @endif
                </div>

                <div class="divide-y divide-brand-ink/5">
                    @foreach ($drift_results as $row)
                        @php
                            $hasError = ! empty($row['error']);
                            $drifted = ! empty($row['drifted']);
                        @endphp
                        <div
                            class="px-6 py-3 sm:px-8"
                            x-data="{ open: false }"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a
                                            href="{{ route('sites.show', ['server' => $server, 'site' => $row['site_id']]) }}"
                                            class="font-medium text-brand-ink hover:underline"
                                        >{{ $row['site_name'] }}</a>
                                        @if ($hasError)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700">
                                                <x-heroicon-o-x-circle class="h-3 w-3" /> {{ $row['error'] }}
                                            </span>
                                        @elseif ($drifted)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                                <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ __('drifted') }}
                                            </span>
                                            <span class="text-[11px] tabular-nums text-emerald-700">+{{ $row['added'] }}</span>
                                            <span class="text-[11px] tabular-nums text-rose-700">-{{ $row['removed'] }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                                <x-heroicon-o-check-circle class="h-3 w-3" /> {{ __('in sync') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 break-all font-mono text-[11px] text-brand-mist">{{ $row['path'] }}</p>
                                </div>
                                @if ($drifted && ! $hasError)
                                    <button
                                        type="button"
                                        x-on:click="open = !open"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        <span x-text="open ? @js(__('Hide diff')) : @js(__('Show diff'))"></span>
                                        <x-heroicon-o-chevron-down class="h-3 w-3 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                                    </button>
                                @endif
                            </div>

                            @if ($drifted && ! $hasError)
                                <pre
                                    x-show="open" x-cloak
                                    class="mt-3 max-h-96 overflow-auto rounded-lg bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100"
                                >{{ $row['diff'] }}</pre>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
