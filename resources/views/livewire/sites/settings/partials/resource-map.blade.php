{{-- Resources tab — a Cloudflare-style topology / flow chart of the site's
     resource bindings. The Site node anchors the left; group "hubs" sit in the
     middle; individual resource nodes fan out on the right. Curved SVG edges
     (hand-drawn in an Alpine component, recomputed on resize) connect them, with
     an animated "flow" dash on attached resources and a dim dashed edge on the
     ones still available to add. Clicking a node opens the shared
     site-binding-modal (or the Logs editor for logging) — same actions as the
     old card stack, just laid out as a graph. VM sites only. --}}
@php
    use App\Support\Sites\SiteBindingCatalog;
    $hubBindings = $site->bindings; // HasMany collection
    $hubGroups = SiteBindingCatalog::grouped('vm', $hubBindings);
    $provisionTypes = ['database', 'redis', 'storage'];
    $configTypes = ['cache', 'queue', 'session', 'mail', 'broadcasting'];
    $statusBadge = [
        'configured' => 'bg-emerald-100 text-emerald-800',
        'pending' => 'bg-amber-100 text-amber-900',
        'provisioning' => 'bg-sky-100 text-sky-800',
        'error' => 'bg-rose-100 text-rose-800',
    ];
    $statusDot = [
        'configured' => 'bg-emerald-500',
        'pending' => 'bg-amber-500',
        'provisioning' => 'bg-sky-500',
        'error' => 'bg-rose-500',
    ];
    $sectionUrl = fn (string $s) => route('sites.show', ['server' => $server, 'site' => $site, 'section' => $s]);

    // Roll-up counts for the header chip.
    $totalTypes = 0;
    $attachedTypes = 0;
    foreach ($hubGroups as $g) {
        foreach ($g['types'] as $t) {
            $totalTypes++;
            $attachedTypes += $t['attached'] ? 1 : 0;
        }
    }
    $groupCount = count($hubGroups);
@endphp

<div class="space-y-5">
    {{-- Header: title, count chip, legend --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Resource map') }}</h2>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Everything wired into this site. Click a node to attach, provision or configure it.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-forest/10 px-3 py-1 text-xs font-semibold text-brand-forest">
                <span class="h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                {{ $attachedTypes }}/{{ $totalTypes }} {{ __('configured') }}
            </span>
            <div class="hidden items-center gap-3 text-[11px] font-medium text-brand-mist sm:flex">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-brand-forest"></span>{{ __('Attached') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full border border-dashed border-brand-ink/30"></span>{{ __('Available') }}</span>
            </div>
        </div>
    </div>

    {{-- The graph. Horizontally scrollable on narrow screens so the topology
         keeps its shape instead of collapsing. --}}
    <div class="dply-card overflow-x-auto bg-gradient-to-br from-white to-brand-cream/30 p-6 sm:p-8">
        <div
            x-data="{
                w: 0, h: 0, _ro: null,
                boot() {
                    this.$nextTick(() => this.compute());
                    this._ro = new ResizeObserver(() => this.compute());
                    this._ro.observe(this.$el);
                    setTimeout(() => this.compute(), 150);
                    setTimeout(() => this.compute(), 600);
                },
                point(rect, wrap, side) {
                    const x = side === 'left' ? rect.left : side === 'right' ? rect.right : rect.left + rect.width / 2;
                    const y = side === 'top' ? rect.top : side === 'bottom' ? rect.bottom : rect.top + rect.height / 2;
                    return { x: x - wrap.left, y: y - wrap.top };
                },
                curve(a, da, b, db) {
                    const c = Math.max(34, Math.hypot(b.x - a.x, b.y - a.y) * 0.45);
                    const c1 = { x: a.x + da.x * c, y: a.y + da.y * c };
                    const c2 = { x: b.x + db.x * c, y: b.y + db.y * c };
                    return `M ${a.x} ${a.y} C ${c1.x} ${c1.y}, ${c2.x} ${c2.y}, ${b.x} ${b.y}`;
                },
                compute() {
                    const el = this.$el; const site = this.$refs.site; const layer = this.$refs.layer;
                    if (!el || !site || !layer) return;
                    const wrap = el.getBoundingClientRect();
                    this.w = el.offsetWidth; this.h = el.offsetHeight;

                    // Build the connectors imperatively in the SVG namespace — an
                    // Alpine x-for inside <svg> would create HTML-namespaced nodes
                    // that never paint, which is why the lines were invisible.
                    const NS = 'http://www.w3.org/2000/svg';
                    const FOREST = 'var(--color-brand-forest)', INK = 'var(--color-brand-ink)';
                    const mk = (t, attrs, styles) => {
                        const e = document.createElementNS(NS, t);
                        for (const k in attrs) e.setAttribute(k, attrs[k]);
                        if (styles) Object.assign(e.style, styles);
                        return e;
                    };
                    const DOWN = { x: 0, y: 1 }, UP = { x: 0, y: -1 }, LEFT = { x: -1, y: 0 };
                    const frag = document.createDocumentFragment();
                    let i = 0;
                    const addEdge = (d, kind, n) => {
                        const base = mk('path', { d, fill: 'none', 'stroke-width': kind === 'trunk' ? 2.5 : 2, 'stroke-linecap': 'round', 'class': kind === 'idle' ? 'dply-edge-idle' : 'dply-edge-flow' },
                            { stroke: kind === 'idle' ? INK : FOREST, opacity: kind === 'idle' ? 0.16 : (kind === 'trunk' ? 0.7 : 0.55) });
                        frag.appendChild(base);
                        if (kind !== 'idle') {
                            frag.appendChild(mk('path', { d, fill: 'none', pathLength: '100', 'stroke-width': 3.5, 'stroke-linecap': 'round', filter: 'url(#dplyGlow)', 'class': 'dply-pulse' },
                                { stroke: '#6ee7b7', animationDelay: (n * 0.22) + 's' }));
                        }
                    };
                    const addDot = (p, kind) => frag.appendChild(mk('circle', { cx: p.x, cy: p.y, r: 3.5 },
                        { fill: kind === 'idle' ? INK : FOREST, opacity: kind === 'idle' ? 0.3 : 1 }));

                    // Site (bottom) fans down to each group hub (top).
                    const start = this.point(site.getBoundingClientRect(), wrap, 'bottom');
                    const hubBottom = {};
                    el.querySelectorAll('[data-hub]').forEach((hub) => {
                        const r = hub.getBoundingClientRect();
                        const top = this.point(r, wrap, 'top');
                        addEdge(this.curve(start, DOWN, top, UP), 'trunk', i++);
                        addDot(top, 'trunk');
                        hubBottom[hub.dataset.hub] = this.point(r, wrap, 'bottom');
                    });
                    // Each hub (bottom) branches into its resource nodes (left side).
                    el.querySelectorAll('[data-resource-node]').forEach((node) => {
                        const from = hubBottom[node.dataset.group];
                        if (!from) return;
                        const to = this.point(node.getBoundingClientRect(), wrap, 'left');
                        const kind = node.dataset.attached === '1' ? 'attached' : 'idle';
                        addEdge(this.curve(from, DOWN, to, LEFT), kind, i++);
                        addDot(to, kind);
                    });
                    // Source node where every trunk originates.
                    frag.appendChild(mk('circle', { cx: start.x, cy: start.y, r: 9 }, { fill: FOREST, opacity: 0.15 }));
                    frag.appendChild(mk('circle', { cx: start.x, cy: start.y, r: 4 }, { fill: FOREST }));

                    layer.replaceChildren(frag);
                },
            }"
            x-init="boot()"
            class="relative mx-auto grid items-start gap-x-2 gap-y-14"
            style="grid-template-columns: repeat({{ $groupCount }}, minmax(0, 1fr)); min-width: {{ $groupCount * 300 }}px; background-image: radial-gradient(circle, rgba(31,77,51,0.07) 1px, transparent 1.6px); background-size: 22px 22px; background-position: -1px -1px;"
        >
            {{-- Curved connector edges (behind the nodes) --}}
            <svg class="pointer-events-none absolute inset-0 z-0 overflow-visible" :width="w" :height="h" x-ref="svg">
                <defs>
                    <filter id="dplyGlow" x="-60%" y="-60%" width="220%" height="220%">
                        <feGaussianBlur stdDeviation="2.2" result="b" />
                        <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
                    </filter>
                </defs>

                {{-- connectors are drawn here imperatively (see compute()) --}}
                <g x-ref="layer"></g>
            </svg>

            {{-- Site node (anchors the whole graph, top-centered) --}}
            <div class="relative z-10 flex justify-center" style="grid-column: 1 / -1; grid-row: 1;">
                <div x-ref="site" class="w-56 rounded-2xl border border-brand-forest/25 bg-white p-4 shadow-md ring-1 ring-brand-forest/5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-forest text-brand-cream">
                            <x-heroicon-o-globe-alt class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $site->domain ?? $site->name ?? __('This site') }}</p>
                            <p class="text-[11px] font-medium uppercase tracking-wide text-brand-mist">{{ __('Site') }}</p>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center gap-1.5 border-t border-brand-ink/10 pt-2.5 text-[11px] text-brand-moss">
                        <x-heroicon-o-server class="h-3.5 w-3.5 text-brand-mist" />
                        <span class="truncate">{{ $server->name }}</span>
                    </div>
                </div>
            </div>

            {{-- One row per group: hub pill (col 2) + resource nodes (col 3) --}}
            @foreach ($hubGroups as $groupKey => $group)
                @php
                    $col = $loop->iteration;
                    $gAttached = collect($group['types'])->where('attached', true)->count();
                    $gTotal = count($group['types']);
                @endphp

                {{-- Group hub --}}
                <div class="relative z-10 flex justify-center" style="grid-column: {{ $col }}; grid-row: 2;">
                    <div data-hub="{{ $groupKey }}" class="w-44 rounded-xl border border-brand-ink/10 bg-white/90 px-3.5 py-2.5 text-center shadow-sm backdrop-blur">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-ink">{{ $group['label'] }}</p>
                        <p class="mt-0.5 text-[11px] font-medium text-brand-mist">{{ $gAttached }}/{{ $gTotal }} {{ __('attached') }}</p>
                    </div>
                </div>

                {{-- Resource nodes for this group (left gutter leaves room for the branch curves) --}}
                <div class="relative z-10 flex flex-col gap-3 pl-9" style="grid-column: {{ $col }}; grid-row: 3;">
                    @foreach ($group['types'] as $t)
                        @php
                            $type = $t['type'];
                            $binding = $t['binding'];
                            $attached = $t['attached'];
                            $envKeys = $attached && is_array($binding->injected_env) ? array_keys($binding->injected_env) : [];
                            $canProvision = in_array($type, $provisionTypes, true);
                            $canConfig = in_array($type, $configTypes, true);
                            $isLogging = $type === 'logging';
                            $needsRedis = in_array('redis', $t['needs'] ?? [], true)
                                && ! $hubBindings->contains(fn ($b) => $b->type === 'redis');
                            $hasAction = $isLogging || $canProvision || $canConfig;
                        @endphp
                        <div
                            wire:key="res-{{ $type }}"
                            data-resource-node="{{ $type }}"
                            data-group="{{ $groupKey }}"
                            data-attached="{{ $attached ? '1' : '0' }}"
                            x-data="{ open: false }"
                            @class([
                                'group/node relative w-full rounded-xl border bg-white p-3 pr-9 shadow-sm transition',
                                'border-brand-forest/30 ring-1 ring-brand-forest/10' => $attached,
                                'border-brand-ink/10 border-dashed hover:border-brand-forest/40 hover:shadow-md' => ! $attached,
                            ])
                        >
                            {{-- corner controls: expand details + detach --}}
                            <div class="absolute right-1.5 top-1.5 flex items-center gap-0.5">
                                @if ($attached && $envKeys !== [])
                                    <button type="button" @click="open = ! open" :aria-expanded="open" title="{{ __('Details') }}"
                                        class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink">
                                        <svg class="h-4 w-4 transition-transform duration-200" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                    </button>
                                @endif
                                @if ($attached && ! $isLogging)
                                    <button type="button" title="{{ __('Detach') }}"
                                        wire:click="openConfirmActionModal('detachBinding', @js([(string) $binding->id]), @js(__('Detach :label?', ['label' => $t['label']])), @js(__('Remove this resource binding? Its injected variables will no longer be applied at deploy.')), @js(__('Detach')), true)"
                                        class="rounded-md p-1 text-brand-mist hover:bg-rose-50 hover:text-rose-600">
                                        <x-heroicon-o-x-mark class="h-4 w-4" />
                                    </button>
                                @endif
                            </div>

                            <div class="flex items-start gap-2.5">
                                <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $attached ? 'bg-brand-forest/10 text-brand-forest' : 'bg-brand-sand/50 text-brand-moss' }}">
                                    <x-dynamic-component :component="$t['icon']" class="h-5 w-5" />
                                    @if ($attached)
                                        <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white {{ $statusDot[$binding->status] ?? 'bg-brand-moss' }}"></span>
                                    @endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5">
                                        <h3 class="truncate text-sm font-semibold text-brand-ink">{{ $t['label'] }}</h3>
                                        @if ($needsRedis)
                                            <span title="{{ __('Attach Redis first to use the redis driver') }}" class="inline-flex shrink-0 items-center text-amber-500">
                                                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" />
                                            </span>
                                        @endif
                                    </div>
                                    @if ($attached)
                                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            <span class="truncate font-mono text-[11px] font-medium text-brand-moss">{{ $binding->name ?: $type }}</span>
                                            <span class="rounded-full px-1.5 py-0 text-[9px] font-semibold uppercase tracking-wide {{ $statusBadge[$binding->status] ?? 'bg-brand-sand/60 text-brand-moss' }}">{{ $binding->status }}</span>
                                        </div>
                                    @else
                                        <p class="mt-0.5 line-clamp-2 text-[11px] leading-snug text-brand-moss">{{ $t['purpose'] }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- injected variables: collapsed summary, expands to full list --}}
                            @if ($attached && $envKeys !== [])
                                <div class="mt-2">
                                    <p x-show="! open" class="truncate font-mono text-[10px] text-brand-mist">{{ collect($envKeys)->take(4)->implode(' · ') }}{{ count($envKeys) > 4 ? ' · +'.(count($envKeys) - 4) : '' }}</p>
                                    <div x-show="open" x-cloak class="rounded-lg bg-brand-cream/40 p-2">
                                        <p class="mb-1 text-[9px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Injected variables') }} ({{ count($envKeys) }})</p>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($envKeys as $k)
                                                <span class="rounded bg-white px-1.5 py-0.5 font-mono text-[10px] text-brand-moss shadow-sm">{{ $k }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Actions --}}
                            <div class="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-brand-ink/10 pt-2.5">
                                @if ($isLogging)
                                    <a href="{{ $sectionUrl('logs') }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" /> {{ $attached ? __('Edit') : __('Configure') }}
                                    </a>
                                @elseif ($canProvision)
                                    <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-link class="h-3.5 w-3.5" /> {{ __('Attach') }}
                                    </button>
                                    <button type="button" wire:click="openBindingModal('{{ $type }}', 'provision')" class="inline-flex items-center gap-1 rounded-md bg-brand-forest px-2 py-1 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                                        <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Provision') }}
                                    </button>
                                @elseif ($canConfig)
                                    <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" /> {{ $attached ? __('Edit') : __('Configure') }}
                                    </button>
                                @else
                                    <span class="text-[11px] italic text-brand-mist">{{ $attached ? __('Active') : __('Not configured') }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    {{-- Shared site-binding-modal (modal-only — we render our own graph above). --}}
    @include('livewire.sites.settings.partials.environment.resources', ['bindingModalOnly' => true])

    @verbatim
        <style>
            [x-cloak] { display: none !important; }
            @keyframes dplyEdgeFlow { to { stroke-dashoffset: -28; } }
            @keyframes dplyPulse { from { stroke-dashoffset: 0; } to { stroke-dashoffset: -100; } }
            .dply-edge-flow { stroke-dasharray: 5 7; animation: dplyEdgeFlow 1.1s linear infinite; }
            .dply-edge-idle { stroke-dasharray: 4 7; }
            .dply-pulse { stroke-dasharray: 1.5 98.5; animation: dplyPulse 2.6s linear infinite; }
            @media (prefers-reduced-motion: reduce) {
                .dply-edge-flow, .dply-pulse { animation: none; }
            }
        </style>
    @endverbatim
</div>
