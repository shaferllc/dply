            @if (($optimisticEngineSubtabs ?? false) || $engine_subtab === 'overview')
            <div
                @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'overview'" x-cloak @endif
                class="space-y-4 mb-6"
            >
            <div class="{{ $card }} overflow-hidden">
                {{-- Header — engine icon + name + version + status pill.
                     Matches the redesigned Overview-tab panel for consistency. --}}
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-dynamic-component :component="$info['icon']" class="h-5 w-5 text-brand-forest" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Engine') }}</p>
                            <h3 class="text-lg font-semibold text-brand-ink">{{ $info['label'] }}</h3>
                            @if ($version !== '')
                                <p class="font-mono text-[11px] text-brand-mist">{{ $version }}</p>
                            @endif
                            @if (! $isActive)
                                @php
                                    $inactiveEngineHint = app(\App\Support\Servers\SystemdServiceStandbyReasonResolver::class)
                                        ->inactiveEngineHint($server, $key, $isEdgeProxyPanel);
                                @endphp
                                <p class="mt-0.5 text-[12px] text-brand-moss">
                                    {{ $inactiveEngineHint ?? ($isEdgeProxyPanel
                                        ? __('Not the active edge proxy on this server.')
                                        : __('Not the active webserver on this server.')) }}
                                </p>
                            @endif
                            @if (! $isActive && ! $isEdgeProxyPanel)
                                @php
                                    // Short engine elevator pitch shown on non-active panels so the
                                    // operator knows what switching to this engine actually gets them.
                                    // Kept here (rather than in $info) so the catalog stays a thin
                                    // identity record and the copy is colocated with where it renders.
                                    $engineBlurb = match ($key) {
                                        'nginx' => __('Mature HTTP server + reverse proxy. Excellent static-file performance, predictable config, very low memory footprint. Default for most production deployments.'),
                                        'caddy' => __('Automatic HTTPS out of the box, simple Caddyfile syntax, HTTP/3 by default. Great for opinionated setups where you want sensible defaults over fine-grained tuning.'),
                                        'apache' => __('Battle-tested with the broadest module catalog and per-directory `.htaccess` support. Higher per-request footprint than nginx but unbeatable compatibility with legacy stacks.'),
                                        'openlitespeed' => __('LSAPI for the fastest PHP execution, built-in LSCache module with per-vhost cache rules, and a familiar Apache-style config. The standard pick for WordPress-heavy hosting.'),
                                        default => '',
                                    };
                                @endphp
                                @if ($engineBlurb !== '')
                                    <p class="mt-2 max-w-prose text-[12px] leading-snug text-brand-moss">{{ $engineBlurb }}</p>
                                @endif
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
                    @endif
                @else
                    {{-- Non-active engine panel: blocker (if any) + switch CTA. Wrap
                         both in the same px-6/sm:px-8 + py rhythm the active-engine
                         lifecycle rows use, so the button doesn't sit flush to the
                         card edge and the blocker box gets the same gutter. --}}
                    <div class="space-y-4 bg-white px-6 py-5 sm:px-8">
                        @if ($isBlocked && $blockerReason)
                            <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                                <p class="font-semibold">{{ __('Switching to :name is currently unavailable.', ['name' => $info['label']]) }}</p>
                                <p class="mt-1">{{ $blockerReason }}</p>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-3">
                            @if ($inflightSwitch)
                                <div class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-4 py-2 text-sm font-semibold text-brand-mist">
                                    <x-spinner variant="forest" size="sm" />
                                    <span>{{ __('Switching in progress…') }}</span>
                                </div>
                            @else
                                @php $switchActionTarget = "openSwitchWebserver('{$key}')"; @endphp
                                <button
                                    type="button"
                                    wire:click="openSwitchWebserver('{{ $key }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $switchActionTarget }}"
                                    @disabled($isDeployer || ! $opsReady || $isBlocked || $actionInFlight)
                                    @class([
                                        'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:cursor-wait disabled:opacity-60',
                                        'bg-brand-forest text-brand-cream shadow-sm shadow-brand-forest/20 hover:bg-brand-forest/90' => ! $isBlocked,
                                        'cursor-not-allowed bg-brand-sand/40 text-brand-mist' => $isBlocked,
                                    ])
                                >
                                    <span wire:loading.remove wire:target="{{ $switchActionTarget }}" class="inline-flex">
                                        @if ($isBlocked)
                                            <x-heroicon-o-no-symbol class="h-4 w-4" />
                                        @else
                                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="{{ $switchActionTarget }}" class="inline-flex">
                                        <x-spinner variant="cream" size="sm" />
                                    </span>
                                    <span wire:loading.remove wire:target="{{ $switchActionTarget }}">
                                        @if ($isBlocked)
                                            {{ __('Unavailable') }}
                                        @else
                                            {{ __('Switch to :name', ['name' => $info['label']]) }}
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="{{ $switchActionTarget }}">
                                        {{ __('Opening…') }}
                                    </span>
                                </button>
                                <p class="text-[11px] text-brand-mist sm:max-w-xs">
                                    {{ __('Switching rebinds :80 and rewrites every site\'s vhost config for the new engine. dply runs the cutover atomically; existing sites stay up.') }}
                                </p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            @if (($hasControls ?? false) && in_array($key, ['openlitespeed', 'nginx', 'apache'], true))
                @php
                    $engineCapabilities = match ($key) {
                        'openlitespeed' => [
                            ['icon' => 'heroicon-o-bolt', 'title' => __('WordPress & LSCache'), 'body' => __('Server-level cache module with per-site rules in the Sites workspace.'), 'subtab' => 'cache'],
                            ['icon' => 'heroicon-o-cpu-chip', 'title' => __('PHP LSAPI'), 'body' => __('Native lsphp handlers — fastest PHP execution without FPM overhead.'), 'subtab' => 'extapps'],
                            ['icon' => 'heroicon-o-shield-check', 'title' => __('Security'), 'body' => __('ModSecurity WAF and rate limits via the Modules tab.'), 'subtab' => 'modules'],
                            ['icon' => 'heroicon-o-server-stack', 'title' => __('Vhosts & listeners'), 'body' => __('Inspect virtual hosts, listeners, and hostname maps.'), 'subtab' => 'vhosts'],
                        ],
                        'nginx' => [
                            ['icon' => 'heroicon-o-bolt', 'title' => __('FastCGI page cache'), 'body' => __('Shared FastCGI/proxy cache zones — RunCloud-style PHP page caching at the edge.'), 'subtab' => 'cache'],
                            ['icon' => 'heroicon-o-server', 'title' => __('Upstreams & proxy'), 'body' => __('Reverse-proxy backends and load-balanced upstream pools.'), 'subtab' => 'upstreams'],
                            ['icon' => 'heroicon-o-shield-check', 'title' => __('Security modules'), 'body' => __('WAF, rate limiting, and TLS modules via the Modules tab.'), 'subtab' => 'modules'],
                            ['icon' => 'heroicon-o-server-stack', 'title' => __('Hosts & certs'), 'body' => __('Custom server blocks, hostname inventory, and certificate expiry.'), 'subtab' => 'hosts'],
                        ],
                        'apache' => [
                            ['icon' => 'heroicon-o-bolt', 'title' => __('Browser & disk cache'), 'body' => __('mod_expires for static assets; optional mod_cache disk storage.'), 'subtab' => 'cache'],
                            ['icon' => 'heroicon-o-puzzle-piece', 'title' => __('Modules & .htaccess'), 'body' => __('Broad module catalog with per-directory overrides when Apache is the edge.'), 'subtab' => 'modules'],
                            ['icon' => 'heroicon-o-cpu-chip', 'title' => __('Event MPM workers'), 'body' => __('Tune MPM workers and global keep-alive settings.'), 'subtab' => 'workers'],
                            ['icon' => 'heroicon-o-server-stack', 'title' => __('Vhosts & certs'), 'body' => __('Custom vhosts, apachectl -S map, and SSL inventory.'), 'subtab' => 'vhosts'],
                        ],
                        default => [],
                    };
                @endphp
                @if ($engineCapabilities !== [])
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($engineCapabilities as $capability)
                        <li>
                            <button
                                type="button"
                                wire:click="setEngineSubtab(@js($capability['subtab']))"
                                class="flex h-full w-full gap-3 rounded-xl border border-brand-ink/8 bg-white p-3.5 text-left shadow-sm ring-1 ring-brand-ink/[0.03] transition hover:border-brand-sage/40 hover:bg-brand-sand/20 sm:p-4"
                            >
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/35 text-brand-forest ring-1 ring-brand-ink/8">
                                    <x-dynamic-component :component="$capability['icon']" class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-brand-ink">{{ $capability['title'] }}</span>
                                    <span class="mt-0.5 block text-[13px] leading-5 text-brand-moss">{{ $capability['body'] }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @endif
            @endif

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
                <div class="{{ $card }}" wire:key="health-{{ $key }}-{{ $engine_metrics_range }}">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Health') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine — recent health', ['engine' => $info['label']]) }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Live counters from the dply metrics agent. Charts show min/max band, line is the bucket average.') }}</p>
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

                    <div class="px-6 py-6 sm:px-7">
                    @if ($latestBlock === null)
                        <div class="mt-5 rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-10 text-center text-sm text-brand-moss">
                            <x-heroicon-o-signal-slash class="mx-auto h-5 w-5 text-brand-mist" />
                            <p class="mt-2">{{ __('No health data yet. The agent will start posting :engine metrics on the next push.', ['engine' => $info['label']]) }}</p>
                            @if ($key === 'caddy')
                                <p class="mt-1 text-[11px] text-brand-mist">
                                    {{ __('Charts need the Monitor tab agent installed and pushing samples. Caddy is scraped from the admin API :endpoint — do not set :admin_off in the global Caddyfile.', ['endpoint' => '/metrics (:2019)', 'admin_off' => 'admin off']) }}
                                </p>
                                <p class="mt-2">
                                    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="text-xs font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:text-brand-forest/80">
                                        {{ __('Open Monitor → install or verify guest push') }}
                                    </a>
                                </p>
                            @elseif (in_array($key, ['nginx', 'apache'], true))
                                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Existing servers may need a one-shot config backfill before the stats endpoint is reachable.') }}</p>
                            @else
                                <p class="mt-1 text-[11px] text-brand-mist">
                                    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:text-brand-forest/80">
                                        {{ __('Open Monitor') }}
                                    </a>
                                    {{ __('to install or verify the metrics agent.') }}
                                </p>
                            @endif
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
                </div>
            @endif
            </div>
            @endif
