{{-- Resources tab — a Cloudflare-style topology / flow chart of the site's
     resource bindings. The Site node anchors the left; group "hubs" sit in the
     middle; individual resource nodes fan out on the right. Curved SVG edges
     (hand-drawn in an Alpine component, recomputed on resize) connect them, with
     an animated "flow" dash on attached resources and a dim dashed edge on the
     ones still available to add. Clicking a node opens the shared
     site-binding-modal (or the Logs editor for logging) — same actions as the
     old card stack, just laid out as a graph. VM sites only. --}}
@php
    use App\Support\Sites\BindingReachability;
    use App\Support\Sites\SiteBindingCatalog;
    use Illuminate\Support\Carbon;
    $hubBindings = $site->bindings; // HasMany collection
    $hubGroups = SiteBindingCatalog::grouped('vm', $hubBindings);
    $networkedAttached = $hubBindings->filter(fn ($b) => BindingReachability::isNetworked($b->type))->count();
    $provisionTypes = ['database', 'redis', 'storage'];
    $configTypes = ['cache', 'queue', 'session', 'mail', 'broadcasting', 'error_tracking', 'ai', 'captcha', 'sms', 'search', 'payments', 'oauth'];
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
    // A type renders as a card when it's attached (or is publication, which is
    // pure runtime-managed state). Everything else is "available" and lives only
    // in the single global "Add resource" dropdown until attached. Shared by the
    // header dropdown and each group column so they stay in lockstep.
    $isShownAsCard = function ($t) {
        if ($t['type'] === 'storage') {
            return ($t['bindings'] ?? collect())->isNotEmpty();
        }
        if ($t['type'] === 'publication') {
            return true;
        }
        return $t['attached'];
    };

    // Only draw a group pathway (hub + column + its trunk edge) once it actually
    // has a card to show. Empty pathways stay hidden until you add a resource to
    // them from the global "Add resource" dropdown, which then makes them appear.
    $visibleGroups = array_filter(
        $hubGroups,
        fn ($g) => collect($g['types'])->contains(fn ($t) => $isShownAsCard($t))
    );
    $groupCount = count($visibleGroups);

    // Available types grouped by their (human) category label, so the global
    // dropdown shows which column each pick will land in. Spans every group —
    // even hidden ones — so a hidden pathway is still reachable from here.
    $availableByGroup = [];
    foreach ($hubGroups as $g) {
        foreach ($g['types'] as $t) {
            if (! $isShownAsCard($t)) {
                $availableByGroup[$g['label']][] = $t;
            }
        }
    }
    $availableCount = array_sum(array_map('count', $availableByGroup));

    // Attached worker SERVER pool(s) get their own graph column on the right, so the
    // scalable background fleet shows as an attached resource. Scoped to pools that
    // scale THIS site's own server; see Site::attachedWorkerPools(). Widen the grid
    // by one column when present.
    $workerPools = $site->attachedWorkerPools();
    $mapCols = $groupCount + ($workerPools->isNotEmpty() ? 1 : 0);

    // Inbound routing — the "front door" node above the site. Requests reach the
    // site through its routing (domains/redirects/SSL) before fanning out to the
    // backing resources below, so it anchors the top of the graph. Derived live
    // from the existing Routing tab's data; no binding row, see the Routing
    // section (route sites.show?section=routing).
    $routingPrimary = $site->primaryDomain();
    $routingDomainCount = $site->domains->count();
    $routingRedirectCount = $site->redirects->count();
    $routingActive = $routingDomainCount > 0;
@endphp

<div class="space-y-5">
    {{-- Header: title, count chip, legend --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Resource map') }}</h2>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Everything wired into this site. Click a node to attach, provision or configure it.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            {{-- One global "Add resource" dropdown. Every unattached type lives
                 here, grouped by category so you can see which column it lands in;
                 picking one runs the same attach path and it pops out as a card in
                 its proper group below. --}}
            @if ($availableCount > 0)
                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = ! open" :aria-expanded="open"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                        <x-heroicon-o-plus class="h-4 w-4" />
                        {{ __('Add resource') }}
                        <span class="rounded-full bg-brand-cream/20 px-1.5 py-0 text-[10px] font-semibold">{{ $availableCount }}</span>
                        <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition x-on:click.outside="open = false"
                        class="absolute right-0 z-30 mt-1 max-h-[28rem] w-80 overflow-y-auto rounded-xl border border-brand-ink/10 bg-white py-1.5 text-left shadow-xl">
                        @foreach ($availableByGroup as $groupLabel => $items)
                            <p class="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $groupLabel }}</p>
                            @foreach ($items as $at)
                                @php
                                    $atType = $at['type'];
                                    $atRuntimeUrl = match ($atType) {
                                        'logging' => $sectionUrl('logs'),
                                        'scheduler' => route('sites.schedule', ['server' => $server, 'site' => $site]),
                                        'workers' => route('sites.daemons', ['server' => $server, 'site' => $site]),
                                        default => null,
                                    };
                                @endphp
                                @if ($atRuntimeUrl)
                                    <a href="{{ $atRuntimeUrl }}" wire:navigate
                                        class="flex items-start gap-2.5 px-3 py-2 transition hover:bg-brand-sand/40">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-sand/50 text-brand-moss">
                                            <x-dynamic-component :component="$at['icon']" class="h-4 w-4" />
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-brand-ink">{{ $at['label'] }}</span>
                                            <span class="block truncate text-[11px] leading-snug text-brand-moss">{{ $at['purpose'] }}</span>
                                        </span>
                                    </a>
                                @else
                                    <button type="button" wire:click="openBindingModal('{{ $atType }}', 'attach')" @click="open = false"
                                        class="flex w-full items-start gap-2.5 px-3 py-2 text-left transition hover:bg-brand-sand/40">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-sand/50 text-brand-moss">
                                            <x-dynamic-component :component="$at['icon']" class="h-4 w-4" />
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-brand-ink">{{ $at['label'] }}</span>
                                            <span class="block truncate text-[11px] leading-snug text-brand-moss">{{ $at['purpose'] }}</span>
                                        </span>
                                    </button>
                                @endif
                            @endforeach
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($networkedAttached > 0)
                <button type="button" wire:click="validateReachability" wire:loading.attr="disabled" wire:target="validateReachability"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                    <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="validateReachability" />
                    {{ __('Validate reachability') }}
                </button>
            @endif
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
    <div class="dply-card overflow-x-auto bg-linear-to-br from-white to-brand-cream/30 p-6 sm:p-8" style="zoom: .95;">
        <div
            x-data="{
                w: 0, h: 0, _ro: null, _zoom: 1,
                boot() {
                    this.$nextTick(() => this.compute());
                    this._ro = new ResizeObserver(() => this.compute());
                    this._ro.observe(this.$el);
                    setTimeout(() => this.compute(), 150);
                    setTimeout(() => this.compute(), 600);
                    // Redraw after Livewire DOM updates (modal open, attach/detach)
                    // so the bus recolors and survives any re-render.
                    if (window.Livewire && window.Livewire.hook) {
                        const redraw = () => this.$nextTick(() => this.compute());
                        ['morph.updated', 'morphed', 'commit'].forEach((h) => {
                            try { window.Livewire.hook(h, redraw); } catch (e) {}
                        });
                    }
                },
                point(rect, wrap, side) {
                    const z = this._zoom || 1;
                    const x = side === 'left' ? rect.left : side === 'right' ? rect.right : rect.left + rect.width / 2;
                    const y = side === 'top' ? rect.top : side === 'bottom' ? rect.bottom : rect.top + rect.height / 2;
                    // getBoundingClientRect is scaled by the ancestor css `zoom`;
                    // divide back to the svg's intrinsic (unzoomed) user space.
                    return { x: (x - wrap.left) / z, y: (y - wrap.top) / z };
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
                    // Detect cumulative ancestor `zoom` (rendered width / layout width).
                    this._zoom = el.offsetWidth ? (wrap.width / el.offsetWidth) : 1;

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

                    // Inbound routing (bottom) flows down into the site (top) —
                    // the front door of the request path. Animates only when the
                    // site has a public domain.
                    const routing = this.$refs.routing;
                    if (routing) {
                        const rBottom = this.point(routing.getBoundingClientRect(), wrap, 'bottom');
                        const sTop = this.point(site.getBoundingClientRect(), wrap, 'top');
                        const rKind = {{ $routingActive ? "'trunk'" : "'idle'" }};
                        addEdge(this.curve(rBottom, DOWN, sTop, UP), rKind, i++);
                        addDot(sTop, rKind);
                    }

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
                    // Each hub (bottom) curves into its resource nodes (left side).
                    el.querySelectorAll('[data-resource-node]').forEach((node) => {
                        const from = hubBottom[node.dataset.group];
                        if (! from) return;
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
            style="grid-template-columns: repeat({{ $mapCols }}, minmax(0, 1fr)); min-width: {{ $mapCols * 300 }}px; background-image: radial-gradient(circle, rgba(31,77,51,0.07) 1px, transparent 1.6px); background-size: 22px 22px; background-position: -1px -1px;"
        >
            {{-- Curved connector edges (behind the nodes) --}}
            <svg class="pointer-events-none absolute inset-0 z-0 overflow-visible" :width="w" :height="h" x-ref="svg">
                <defs>
                    <filter id="dplyGlow" x="-60%" y="-60%" width="220%" height="220%">
                        <feGaussianBlur stdDeviation="2.2" result="b" />
                        <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
                    </filter>
                </defs>

                {{-- connectors are drawn here imperatively (see compute()).
                     wire:ignore keeps Livewire's morph from wiping the JS-drawn
                     children when a modal opens / the component re-renders. --}}
                <g x-ref="layer" wire:ignore></g>
            </svg>

            {{-- Inbound routing node — the front door above the site. Reuses the
                 existing Routing tab (domains / redirects / SSL) for all config. --}}
            <div class="relative z-10 flex justify-center" style="grid-column: 1 / -1; grid-row: 1;">
                <div x-ref="routing" @class([
                    'w-56 rounded-2xl border bg-white p-4 shadow-sm transition',
                    'border-brand-forest/25 ring-1 ring-brand-forest/5' => $routingActive,
                    'border-dashed border-brand-ink/15' => ! $routingActive,
                ])>
                    <div class="flex items-center gap-2.5">
                        <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $routingActive ? 'bg-brand-forest/10 text-brand-forest' : 'bg-brand-sand/50 text-brand-moss' }}">
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" />
                            @if ($routingActive)
                                <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white"></span>
                            @endif
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ __('Routing') }}</p>
                            <p class="text-[11px] font-medium uppercase tracking-wide text-brand-mist">{{ __('Inbound') }}</p>
                        </div>
                    </div>
                    <div class="mt-3 border-t border-brand-ink/10 pt-2.5">
                        @if ($routingActive)
                            <p class="truncate font-mono text-[11px] font-medium text-brand-moss">{{ $routingPrimary?->hostname ?? __('Configured') }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-mist">
                                {{ trans_choice(':n domain|:n domains', $routingDomainCount, ['n' => $routingDomainCount]) }}@if ($routingRedirectCount > 0) · {{ trans_choice(':n redirect|:n redirects', $routingRedirectCount, ['n' => $routingRedirectCount]) }}@endif
                            </p>
                        @else
                            <p class="text-[11px] text-brand-moss">{{ __('No public domain yet') }}</p>
                        @endif
                        <a href="{{ $sectionUrl('routing') }}" wire:navigate class="mt-2 inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5 text-brand-forest" /> {{ __('Manage routing') }}
                        </a>
                    </div>
                </div>
            </div>

            {{-- Site node (anchors the whole graph, top-centered) --}}
            <div class="relative z-10 flex justify-center" style="grid-column: 1 / -1; grid-row: 2;">
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

            {{-- One row per visible group: hub pill (col 2) + resource nodes (col 3).
                 Groups with nothing attached are hidden until a resource lands. --}}
            @foreach ($visibleGroups as $groupKey => $group)
                @php
                    $col = $loop->iteration;
                    $gAttached = collect($group['types'])->where('attached', true)->count();

                    // Only render the resources that are actually present as cards
                    // (anything attached, plus publication which is purely
                    // runtime-managed state). Unattached types are added from the
                    // single global "Add resource" dropdown in the header and pop
                    // out here once attached — no wall of empty ghost cards.
                    $cardTypes = collect($group['types'])->filter($isShownAsCard)->values();
                @endphp

                {{-- Group hub --}}
                <div class="relative z-10 flex justify-center" style="grid-column: {{ $col }}; grid-row: 3;">
                    <div data-hub="{{ $groupKey }}" class="w-44 rounded-xl border border-brand-ink/10 bg-white/90 px-3.5 py-2.5 text-center shadow-sm backdrop-blur">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-ink">{{ $group['label'] }}</p>
                        <p class="mt-0.5 text-[11px] font-medium text-brand-mist">{{ trans_choice('{0}nothing attached|{1}:count attached|[2,*]:count attached', $gAttached, ['count' => $gAttached]) }}</p>
                    </div>
                </div>

                {{-- Resource nodes for this group (left gutter leaves room for the branch curves) --}}
                <div class="relative z-10 flex flex-col gap-3 pl-9" style="grid-column: {{ $col }}; grid-row: 4;">
                    @foreach ($cardTypes as $t)
                        @if ($t['type'] === 'storage')
                            @include('livewire.sites.settings.partials._resource-storage-card', ['t' => $t])
                            @continue
                        @endif
                        @php
                            $type = $t['type'];
                            // Multi-instance types (database, …) render one node per
                            // attached instance; single types render their one node.
                            $isMulti = \App\Models\SiteBinding::isMultiInstance($type);
                            $instances = $isMulti ? collect($t['bindings'] ?? []) : collect([$t['binding']]);
                        @endphp
                        @foreach ($instances as $binding)
                        @php
                            $attached = $binding instanceof \App\Models\SiteBinding;
                            $envKeys = $attached && is_array($binding->injected_env) ? array_keys($binding->injected_env) : [];
                            $canProvision = in_array($type, $provisionTypes, true);
                            $canConfig = in_array($type, $configTypes, true);
                            $isLogging = $type === 'logging';
                            $needsRedis = in_array('redis', $t['needs'] ?? [], true)
                                && ! $hubBindings->contains(fn ($b) => $b->type === 'redis');
                            $hasAction = $isLogging || $canProvision || $canConfig;
                            $conn = $attached && is_array($binding->config) ? ($binding->config['connectivity'] ?? null) : null;
                            $reachTarget = $attached ? BindingReachability::target($binding) : null;
                            $isUnreachable = $conn !== null && ! ($conn['ok'] ?? false);

                            // Human-readable explanation of THIS tile's current state, surfaced as an
                            // HTML tooltip on the status dot + badge so a hover tells you what's wrong.
                            $cfg = $attached && is_array($binding->config) ? $binding->config : [];

                            // Managed DBs inject their connection vars at provision-complete time,
                            // but those only reach the running app at the next deploy. A managed
                            // binding "needs redeploy" until last_deploy_at catches up to when the
                            // connection became ready.
                            $managedReadyAt = ($cfg['managed'] ?? false) && filled($cfg['connection_ready_at'] ?? null)
                                ? \Illuminate\Support\Carbon::parse($cfg['connection_ready_at'])
                                : null;
                            $needsRedeploy = $managedReadyAt !== null
                                && $attached && $binding->status === 'configured'
                                && ($site->last_deploy_at === null || $site->last_deploy_at->lt($managedReadyAt));
                            $reasonMap = [
                                'drivers_reference_redis_without_connection' => __('Cache, queue, or session is set to redis, but no Redis connection is configured yet — attach Redis.'),
                                's3_disk_without_bucket' => __('The filesystem disk is S3-compatible, but AWS_BUCKET is not set.'),
                                'bucket_without_keys' => __('A bucket or URL is set, but the AWS access keys are missing.'),
                                'incomplete_object_storage_env' => __('Object storage configuration is incomplete.'),
                            ];
                            $statusHint = match (true) {
                                $attached && filled($binding->last_error ?? null) => (string) $binding->last_error,
                                $isUnreachable && filled($conn['detail'] ?? null) => (string) $conn['detail'],
                                $isUnreachable => __('The server could not open a connection to this resource.'),
                                filled($cfg['reason'] ?? null) && isset($reasonMap[$cfg['reason']]) => $reasonMap[$cfg['reason']],
                                filled($cfg['reason'] ?? null) => \Illuminate\Support\Str::headline((string) $cfg['reason']),
                                // Managed databases provision asynchronously: the cluster takes a few
                                // minutes, then its connection vars land on the binding. They apply at
                                // the next deploy, so a configured managed DB prompts a redeploy.
                                $attached && ($cfg['managed'] ?? false) && $binding->status === 'provisioning' => __('Provisioning the managed cluster — this takes a few minutes.'),
                                $needsRedeploy => __('Connection ready — redeploy to apply the connection variables.'),
                                $attached && $binding->status === 'configured' => __('Configured and ready.'),
                                $attached && $binding->status === 'pending' => __('Attached, but not fully configured yet.'),
                                $attached => \Illuminate\Support\Str::headline((string) $binding->status),
                                default => __('Not attached yet.'),
                            };
                        @endphp
                        <div
                            wire:key="res-{{ $type }}-{{ $attached ? $binding->id : 'new' }}"
                            data-resource-node="{{ $type }}{{ $attached ? '-'.$binding->id : '' }}"
                            data-group="{{ $groupKey }}"
                            data-attached="{{ $attached ? '1' : '0' }}"
                            x-data="{ open: false }"
                            @class([
                                'group/node relative w-full rounded-xl border bg-white p-3 pr-9 shadow-sm transition',
                                'border-brand-forest/30 ring-1 ring-brand-forest/10' => $attached,
                                'border-brand-ink/10 border-dashed hover:border-brand-forest/40 hover:shadow-md' => ! $attached,
                            ])
                        >
                            {{-- corner controls: expand details + (multi-instance) edit + detach --}}
                            <div class="absolute right-1.5 top-1.5 flex items-center gap-0.5">
                                @if ($attached && $envKeys !== [])
                                    <button type="button" @click="open = ! open" :aria-expanded="open" title="{{ __('Details') }}"
                                        class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink">
                                        <svg class="h-4 w-4 transition-transform duration-200" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                                    </button>
                                @endif
                                @if ($attached && $isMulti)
                                    <button type="button" title="{{ __('Edit') }}"
                                        wire:click="openBindingModal('{{ $type }}', 'attach', @js((string) $binding->id))"
                                        class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink">
                                        <x-heroicon-o-pencil-square class="h-4 w-4" />
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
                                        <span title="{{ $statusHint }}" class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white {{ $statusDot[$binding->status] ?? 'bg-brand-moss' }}"></span>
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
                                            <span title="{{ $statusHint }}" class="cursor-help rounded-full px-1.5 py-0 text-[9px] font-semibold uppercase tracking-wide {{ $statusBadge[$binding->status] ?? 'bg-brand-sand/60 text-brand-moss' }}">{{ $binding->status }}</span>
                                            @if ($needsRedeploy)
                                                <a href="{{ $sectionUrl('deploy') }}" wire:navigate
                                                    title="{{ __('The connection variables apply at the next deploy.') }}"
                                                    class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0 text-[9px] font-semibold uppercase tracking-wide text-amber-800 hover:bg-amber-200">
                                                    <x-heroicon-o-arrow-path class="h-2.5 w-2.5" /> {{ __('Redeploy to apply') }}
                                                </a>
                                            @endif
                                        </div>
                                        @if ($conn !== null)
                                            @php
                                                $reachOk = (bool) ($conn['ok'] ?? false);
                                                $reachWhen = ! empty($conn['checked_at']) ? Carbon::parse($conn['checked_at'])->diffForHumans(short: true) : null;
                                            @endphp
                                            <span class="mt-1 inline-flex w-fit items-center gap-1 rounded-full px-1.5 py-0.5 text-[9px] font-semibold {{ $reachOk ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}" title="{{ $conn['detail'] ?? ($reachOk ? __('Reachable from the server') : '') }}">
                                                <span class="h-1.5 w-1.5 rounded-full {{ $reachOk ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                                {{ $reachOk ? __('Reachable') : __('Unreachable') }}@if ($reachWhen) · {{ $reachWhen }}@endif
                                            </span>
                                        @elseif ($reachTarget)
                                            <span class="mt-1 inline-flex w-fit items-center gap-1 rounded-full bg-brand-sand/50 px-1.5 py-0.5 text-[9px] font-medium text-brand-moss">
                                                <span class="h-1.5 w-1.5 rounded-full bg-brand-mist"></span>{{ __('Not checked') }}
                                            </span>
                                        @endif
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
                                            @foreach ($binding->injected_env as $k => $v)
                                                {{-- Hover/click a chip to reveal its injected value (masked by
                                                     default), copy it, or jump to Environment to override it. --}}
                                                <div
                                                    x-data="{ pop: false, show: false, copied: false,
                                                        async copyVal() { try { await navigator.clipboard.writeText(@js((string) $v)); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }"
                                                    @mouseenter="pop = true" @mouseleave="pop = false"
                                                    class="relative"
                                                >
                                                    <button type="button" @click="pop = ! pop"
                                                        class="rounded bg-white px-1.5 py-0.5 font-mono text-[10px] text-brand-moss shadow-sm hover:bg-brand-sand/40">{{ $k }}</button>
                                                    <div x-show="pop" x-cloak x-transition.opacity
                                                        class="absolute left-0 top-full z-30 mt-1 w-64 rounded-lg border border-brand-ink/10 bg-white p-2 text-left shadow-xl">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="truncate font-mono text-[10px] font-semibold text-brand-ink">{{ $k }}</span>
                                                            <div class="flex shrink-0 items-center gap-2 text-[10px] font-semibold">
                                                                <button type="button" @click="show = ! show" class="text-brand-sage hover:underline"><span x-show="! show">{{ __('Show') }}</span><span x-show="show" x-cloak>{{ __('Hide') }}</span></button>
                                                                <button type="button" @click="copyVal()" class="text-brand-sage hover:underline"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span></button>
                                                            </div>
                                                        </div>
                                                        <p class="mt-1 break-all rounded bg-brand-cream/50 px-2 py-1 font-mono text-[10px] text-brand-ink">
                                                            <span x-show="show" x-cloak>{{ ((string) $v) === '' ? '(empty)' : $v }}</span>
                                                            <span x-show="! show">••••••••••</span>
                                                        </p>
                                                        <a href="{{ $sectionUrl('environment') }}" wire:navigate class="mt-1.5 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-forest hover:underline">
                                                            <x-heroicon-o-pencil-square class="h-3 w-3" /> {{ __('Override in Environment') }}
                                                        </a>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                        @if ($isMulti && filled($cfg['connection_snippet'] ?? null))
                                            {{-- A named (secondary) connection needs a matching block in
                                                 the app's config/database.php — hand over the exact array. --}}
                                            <div class="mt-2" x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(@js((string) ($cfg['connection_snippet'] ?? ''))); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }">
                                                <div class="flex items-center justify-between">
                                                    <p class="text-[9px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add to config/database.php → connections') }}</p>
                                                    <button type="button" @click="copy()" class="text-[10px] font-semibold text-brand-sage hover:underline"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span></button>
                                                </div>
                                                <pre class="mt-1 overflow-x-auto rounded bg-brand-ink/90 p-2 font-mono text-[10px] leading-relaxed text-brand-cream">{{ $cfg['connection_snippet'] }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            {{-- Actions --}}
                            <div class="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-brand-ink/10 pt-2.5">
                                @if ($isUnreachable && method_exists($this, 'fixBindingConnectivity'))
                                    {{-- Highlighted remediation when the server can't reach the resource.
                                         database/redis get the server-side auto-fix modal; logging links to
                                         the Logs editor; everything else opens the reconfigure modal. --}}
                                    @if (in_array($type, ['database', 'redis'], true))
                                        <button type="button" wire:click="startFixBinding(@js((string) $binding->id))" x-on:click="$dispatch('open-modal', 'fix-binding-modal')"
                                            title="{{ __('Fix the private-network connectivity for this resource.') }}"
                                            class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-white px-2 py-1 text-[11px] font-semibold text-rose-700 shadow-sm hover:bg-rose-50">
                                            <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" /> {{ __('Fix') }}
                                        </button>
                                    @elseif ($isLogging)
                                        <a href="{{ $sectionUrl('logs') }}" wire:navigate
                                            class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-white px-2 py-1 text-[11px] font-semibold text-rose-700 shadow-sm hover:bg-rose-50">
                                            <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" /> {{ __('Fix') }}
                                        </a>
                                    @else
                                        <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')"
                                            title="{{ __('Re-enter the endpoint / credentials for this resource.') }}"
                                            class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-white px-2 py-1 text-[11px] font-semibold text-rose-700 shadow-sm hover:bg-rose-50">
                                            <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" /> {{ __('Fix') }}
                                        </button>
                                    @endif
                                @endif
                                {{-- Test the live binding: mail sends a real test email through the
                                     transport; database/redis/cache/queue/session probe the connection
                                     from the server (cache/queue/session resolve to their engine). --}}
                                @if ($attached && method_exists($this, 'seedQueuedConsoleAction'))
                                    @if ($type === 'mail' && method_exists($this, 'sendBindingTestEmail'))
                                        {{-- Like the variables-list "Send test": pop a recipient field so
                                             the operator can pick who gets it (blank → their own email). --}}
                                        <div class="relative" x-data="{ open: false }" wire:key="cardmailtest-{{ md5((string) $binding->id) }}">
                                            <button type="button" x-on:click="open = !open"
                                                title="{{ __('Send a test email through this transport.') }}"
                                                class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                                <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 text-brand-forest" /> {{ __('Test') }}
                                            </button>
                                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition class="absolute left-0 z-20 mt-1 w-72 rounded-xl border border-brand-ink/10 bg-white p-3 text-left shadow-lg">
                                                <x-input-label for="cardmailtest_to_{{ md5((string) $binding->id) }}" :value="__('Send test email to')" />
                                                <input id="cardmailtest_to_{{ md5((string) $binding->id) }}" type="email" wire:model="mailTestRecipient" placeholder="{{ auth()->user()?->email }}" class="dply-input mt-1 text-sm" />
                                                <button type="button" wire:click="sendBindingTestEmail(@js((string) $binding->id))" wire:loading.attr="disabled" wire:target="sendBindingTestEmail" x-on:click="open = false" class="mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-60">
                                                    <x-heroicon-o-paper-airplane class="h-4 w-4" /> {{ __('Send test email') }}
                                                </button>
                                                <p class="mt-1.5 text-[11px] text-brand-moss">{{ __('Sent from the site\'s server. The site must be deployed.') }}</p>
                                            </div>
                                        </div>
                                    @elseif (in_array($type, ['database', 'redis', 'cache', 'queue', 'session'], true) && method_exists($this, 'verifyBinding'))
                                        <button type="button" wire:click="verifyBinding(@js((string) $binding->id))" wire:loading.attr="disabled" wire:target="verifyBinding"
                                            title="{{ __('Probe this connection from the server now.') }}"
                                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                            <x-heroicon-o-signal class="h-3.5 w-3.5 text-brand-forest" /> {{ __('Test') }}
                                        </button>
                                    @elseif ($type === 'broadcasting' && method_exists($this, 'testBroadcastingBinding'))
                                        {{-- Managed apps publish a test event to the relay; BYO falls back
                                             to the server-side TCP probe (handled in the method). --}}
                                        <button type="button" wire:click="testBroadcastingBinding(@js((string) $binding->id))" wire:loading.attr="disabled" wire:target="testBroadcastingBinding"
                                            title="{{ __('Publish a test event to the relay now.') }}"
                                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                            <x-heroicon-o-signal class="h-3.5 w-3.5 text-brand-forest" /> {{ __('Test') }}
                                        </button>
                                    @endif
                                @endif
                                @if ($attached && method_exists($this, 'openBindingInfoModal'))
                                    <button type="button" wire:click="openBindingInfoModal(@js((string) $binding->id))"
                                        title="{{ __('View this connection\'s details (injected variables + reachability).') }}"
                                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-information-circle class="h-3.5 w-3.5" /> {{ __('Info') }}
                                    </button>
                                @endif
                                {{-- Jump from a managed broadcasting binding to the relay app's own page
                                     (credentials, live stats, connected sites, tier). --}}
                                @if ($type === 'broadcasting' && $attached && $binding->target_type === 'realtime_app' && (auth()->user()?->can('view', $site->organization) ?? false))
                                    <a href="{{ route('organizations.realtime.show', [$site->organization, $binding->target_id]) }}" wire:navigate
                                        title="{{ __('Manage the relay app (credentials, stats, tier).') }}"
                                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 text-brand-forest" /> {{ __('Manage app') }}
                                    </a>
                                @endif
                                @if ($isLogging)
                                    <a href="{{ $sectionUrl('logs') }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" /> {{ $attached ? __('Edit') : __('Configure') }}
                                    </a>
                                @elseif ($canProvision)
                                    @if ($attached && $isMulti)
                                        {{-- Multi-instance (database): per-instance Edit lives in the
                                             corner and "Add another" sits below the list, so no Replace. --}}
                                    @elseif ($attached)
                                        {{-- Already wired up: one binding per type, so attach/provision
                                             both *replace* it. Offer a single "Replace…" that opens the
                                             modal (where you can re-link an existing one or spin up a new). --}}
                                        <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" /> {{ __('Replace…') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-link class="h-3.5 w-3.5" /> {{ __('Attach') }}
                                        </button>
                                        <button type="button" wire:click="openBindingModal('{{ $type }}', 'provision')" class="inline-flex items-center gap-1 rounded-md bg-brand-forest px-2 py-1 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                                            <x-heroicon-o-plus class="h-3.5 w-3.5" /> {{ __('Provision') }}
                                        </button>
                                    @endif
                                @elseif ($canConfig)
                                    @if ($attached && $isMulti)
                                        {{-- Multi-instance: per-instance Edit lives in the corner and
                                             "Add another" sits below; the id-less Configure button here
                                             would open a fresh form, so it's suppressed. --}}
                                    @else
                                        <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" /> {{ $attached ? __('Edit') : __('Configure') }}
                                        </button>
                                    @endif
                                @else
                                    @php
                                        $runtimeUrl = match ($type) {
                                            'scheduler' => route('sites.schedule', ['server' => $server, 'site' => $site]),
                                            'workers' => route('sites.daemons', ['server' => $server, 'site' => $site]),
                                            default => null,
                                        };
                                    @endphp
                                    @if ($runtimeUrl)
                                        <a href="{{ $runtimeUrl }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" /> {{ __('Configure') }}
                                        </a>
                                    @elseif ($type === 'publication')
                                        {{-- Runtime-owned: the deploy runtime fills in the publication
                                             target (url/service/port); there's nothing to configure. --}}
                                        @php
                                            $pub = is_array(data_get($site->runtimeTarget(), 'publication')) ? data_get($site->runtimeTarget(), 'publication') : [];
                                            $pubUrl = $pub['url'] ?? $pub['hostname'] ?? null;
                                            $pubHref = $pubUrl ? (\Illuminate\Support\Str::startsWith($pubUrl, ['http://', 'https://']) ? $pubUrl : 'https://'.$pubUrl) : null;
                                        @endphp
                                        @if ($pubHref)
                                            <span class="inline-flex min-w-0 items-center gap-1.5 text-[11px] font-medium">
                                                <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500"></span>
                                                <span class="shrink-0 text-brand-mist">{{ __('Published') }}</span>
                                                <a href="{{ $pubHref }}" target="_blank" rel="noopener" class="truncate font-mono text-brand-forest hover:underline">{{ $pubUrl }}</a>
                                            </span>
                                        @else
                                            <span title="{{ __('Set automatically on deploy') }}" class="inline-flex items-center gap-1 rounded-full bg-brand-sand/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                                <x-heroicon-o-cpu-chip class="h-3 w-3" /> {{ __('Managed by the runtime') }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-[11px] italic text-brand-mist">{{ $attached ? __('Active') : __('Not configured') }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                        @endforeach
                        @if ($isMulti)
                            {{-- Add another instance of this multi-instance type (e.g.
                                 a second database / connection). It lands in this same
                                 column once attached. --}}
                            <button type="button" wire:click="openBindingModal('{{ $type }}', 'attach')"
                                class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-brand-ink/20 bg-white/60 px-3 py-2 text-[11px] font-semibold text-brand-moss shadow-sm transition hover:border-brand-forest/40 hover:text-brand-ink hover:shadow-md">
                                <x-heroicon-o-plus class="h-3.5 w-3.5 text-brand-forest" />
                                {{ __('Add another :label', ['label' => \Illuminate\Support\Str::lower($t['label'])]) }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endforeach

            {{-- Worker SERVER pool(s) as their own attached column. Edges auto-wire via
                 data-hub="workers" (trunk from the site) and data-group="workers"
                 (branch into each pool node), the same mechanism the binding groups use. --}}
            @if ($workerPools->isNotEmpty())
                @php $wcol = $groupCount + 1; @endphp
                <div class="relative z-10 flex justify-center" style="grid-column: {{ $wcol }}; grid-row: 3;">
                    <div data-hub="workers" class="w-44 rounded-xl border border-violet-200 bg-white/90 px-3.5 py-2.5 text-center shadow-sm backdrop-blur">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-violet-800">{{ __('Workers') }}</p>
                        <p class="mt-0.5 text-[11px] font-medium text-brand-mist">{{ trans_choice(':n pool|:n pools', $workerPools->count(), ['n' => $workerPools->count()]) }} {{ __('attached') }}</p>
                    </div>
                </div>
                <div class="relative z-10 flex flex-col gap-3 pl-9" style="grid-column: {{ $wcol }}; grid-row: 4;">
                    @foreach ($workerPools as $pool)
                        @php $primary = $pool->primaryServer; @endphp
                        <div
                            data-resource-node="worker-pool-{{ $pool->id }}"
                            data-group="workers"
                            data-attached="1"
                            class="relative w-full rounded-xl border border-violet-200 bg-white p-3 shadow-sm"
                        >
                            <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-violet-500 ring-2 ring-white"></span>
                            <div class="flex items-center gap-2">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-700">
                                    <x-heroicon-o-square-3-stack-3d class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $pool->name ?: __('Worker pool') }}</p>
                                    <p class="text-[11px] text-brand-moss">{{ trans_choice(':n server|:n servers', $pool->servers->count(), ['n' => $pool->servers->count()]) }} · {{ $pool->status }}</p>
                                </div>
                            </div>
                            <div class="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-brand-ink/10 pt-2.5">
                                @if ($primary)
                                    <a href="{{ route('servers.worker-pool', ['server' => $primary]) }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5" /> {{ __('Scale') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Shared site-binding-modal (modal-only — we render our own graph above). --}}
    @include('livewire.sites.settings.partials.environment.resources', ['bindingModalOnly' => true])

    {{-- Fix-unreachable modal (auto-fix in place / re-point for database & redis). --}}
    @include('livewire.sites.settings.partials.environment.fix-binding-modal')

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
