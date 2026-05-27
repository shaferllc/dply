{{-- Cloud create — canvas view. Cards are absolutely positioned and draggable.
     SVG connector paths are computed at runtime from real card edges (via
     ResizeObserver + Alpine state), so the lines always line up — even after
     the user drags a card or expands an inline form. All wire:model bindings
     are shared with the form view; flipping either way keeps state in sync.
     Buttons are type="button" so dragging/clicking never submits the form. --}}
@php
    $sizeTierLabels = [
        'small' => 'Small · 1 vCPU',
        'medium' => 'Medium · 1 vCPU',
        'large' => 'Large · 2 vCPU',
        'xlarge' => 'XLarge · 4 vCPU',
        'small-pro' => 'Small Pro · 1 vCPU',
        'medium-pro' => 'Medium Pro · 2 vCPU',
        'large-pro' => 'Large Pro · 4 vCPU',
        'xlarge-pro' => 'XLarge Pro · 8 vCPU',
    ];
    $sizeLabel = $sizeTierLabels[$size_tier] ?? ucfirst($size_tier);
    $regionLabel = collect($regions)->firstWhere('slug', $region)['label'] ?? $region;
    $appLabel = trim($name) !== '' ? $name : __('Untitled app');
    $sourceLabel = $mode === 'source'
        ? ($repo !== '' ? $repo : __('GitHub repo (not set)'))
        : ($image !== '' ? $image : __('Image (not set)'));
    $engineLabels = [
        'postgres' => __('Postgres'),
        'mysql' => __('MySQL'),
        'redis' => __('Redis'),
    ];
@endphp

<div
    class="relative overflow-hidden rounded-3xl border border-brand-ink/10 bg-brand-cream/30 shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900/60"
    style="background-image: radial-gradient(circle, rgba(15, 23, 42, 0.08) 1px, transparent 1px); background-size: 20px 20px; background-position: 10px 10px;"
    x-data="{
        // Initial positions (left, top in px) relative to the canvas-inner box.
        // Per-database positions are seeded from PHP — each $databases entry
        // gets stacked vertically in the right column so newly-added rows
        // land below existing ones.
        cards: {
            network:  { x: 0,    y: 0   },
            domains:  { x: 0,    y: 280 },
            region:   { x: 280,  y: 0   },
            {{-- Cards default to open; strides account for the open body
                 height plus a 20px gap so the initial layout doesn't
                 overlap. pushOverlappers re-stacks on later expansion. --}}
            @php $rightColumnYCursor = 0; @endphp
            @foreach ($databases as $db)
                '{{ $db['_id'] }}': { x: 620, y: {{ $rightColumnYCursor }} },
                @php $rightColumnYCursor += 400; @endphp
            @endforeach
            @foreach ($buckets as $bk)
                '{{ $bk['_id'] }}': { x: 620, y: {{ $rightColumnYCursor }} },
                @php $rightColumnYCursor += 320; @endphp
            @endforeach
        },
        // Measured sizes — populated by ResizeObserver after first render. We
        // seed with reasonable fallbacks so paths render usefully even before
        // the observer kicks in.
        sizes: {
            network:  { w: 260, h: 260 },
            domains:  { w: 260, h: 160 },
            region:   { w: 320, h: 420 },
            @foreach ($databases as $db)
                '{{ $db['_id'] }}': { w: 260, h: 140 },
            @endforeach
            @foreach ($buckets as $bk)
                '{{ $bk['_id'] }}': { w: 260, h: 120 },
            @endforeach
        },
        // Snap to a 20px grid — the dotted background dots are literally
        // the snap targets. Auto-layout uses this for tidy y rounding.
        gridSize: 20,
        // Total content height in canvas-coords. canvas-inner's min-height
        // is bound to this so the viewport always grows to fit every card —
        // no clipping, no internal scroll. Page scrolls vertically if the
        // canvas runs taller than the window.
        contentH() {
            let maxBottom = 600;
            for (const id of Object.keys(this.cards)) {
                const c = this.cards[id];
                if (! c) continue;
                const el = this.$refs['card_' + id];
                const liveH = el ? el.offsetHeight : 0;
                const seedH = this.sizes[id]?.h || 140;
                const h = Math.max(liveH, seedH);
                if (c.y + h > maxBottom) maxBottom = c.y + h;
            }
            return maxBottom + 40;
        },
        // Measured canvas-inner dimensions, used to size the SVG layer
        // explicitly. Without this, an SVG sized via `h-full w-full +
        // inset-0` on a parent that only has `min-*` dimensions can end
        // up with an implicit viewport that doesn't match the pixel
        // coordinate system the paths use — endpoints float a few dozen
        // pixels off the card edges.
        canvasInnerW: 900,
        canvasInnerH: 600,
        // ResizeObserver instances per card, kept around for cleanup.
        observers: [],

        // Wire-bound dynamic nodes (databases, buckets) arrive via a
        // Livewire dispatch after their server-side row is created. The
        // payload's `id` is the row's _id; we stack the new card at the
        // bottom of the right column and scroll it into view.
        registerNode(id) {
            if (! id || this.cards[id]) return;
            let maxBottom = 0;
            for (const key of Object.keys(this.cards)) {
                const other = this.cards[key];
                if (! other) continue;
                if (Math.abs(other.x - 620) > 50) continue;
                const bottom = other.y + (this.sizes[key]?.h || 140);
                if (bottom > maxBottom) maxBottom = bottom;
            }
            const nextY = Math.round((maxBottom + 20) / this.gridSize) * this.gridSize;
            this.cards[id] = { x: 620, y: nextY };
            this.sizes[id] = { w: 260, h: 140 };
            this.$nextTick(() => {
                const el = this.$refs['card_' + id];
                if (el) {
                    const ro = new ResizeObserver(() => this.measureOne(id));
                    ro.observe(el);
                    this.observers.push(ro);
                    this.measureOne(id);
                }
                this.scrollNodeIntoView(id);
            });
        },

        unregisterNode(id) {
            if (! id) return;
            delete this.cards[id];
            delete this.sizes[id];
        },

        // After a node materializes, scroll the horizontally-scrollable
        // wrapper around canvasInner so the new card is actually in view.
        // We scroll the wrapper directly rather than calling scrollIntoView
        // on the card because (a) the canvas root has overflow-hidden which
        // can confuse scrollIntoView's ancestor search, and (b) we want a
        // predictable centering calculation.
        scrollNodeIntoView(id) {
            const inner = this.$refs.canvasInner;
            if (! inner) return;
            const wrapper = inner.parentElement; // .flex-1.overflow-x-auto
            if (! wrapper) return;
            const c = this.cards[id];
            const s = this.sizes[id] || { w: 340, h: 120 };
            const targetLeft = c.x + s.w / 2 - wrapper.clientWidth / 2;
            const maxLeft = Math.max(0, inner.offsetWidth - wrapper.clientWidth);
            wrapper.scrollTo({
                left: Math.max(0, Math.min(maxLeft, targetLeft)),
                behavior: 'smooth',
            });
        },

        init() {
            this.$nextTick(() => this.attachObservers());
        },

        attachObservers() {
            for (const id of Object.keys(this.cards)) {
                const el = this.$refs['card_' + id];
                if (! el) continue;
                const ro = new ResizeObserver(() => this.measureOne(id));
                ro.observe(el);
                this.observers.push(ro);
                this.measureOne(id);
            }
            // Track canvas-inner so the SVG layer stays sized to the
            // pixel-grid the cards live in. Both the initial measurement
            // and on-resize updates flow through here.
            const inner = this.$refs.canvasInner;
            if (inner) {
                const ro = new ResizeObserver(() => this.measureCanvasInner());
                ro.observe(inner);
                this.observers.push(ro);
                this.measureCanvasInner();
            }
        },

        measureCanvasInner() {
            const inner = this.$refs.canvasInner;
            if (! inner) return;
            this.canvasInnerW = inner.offsetWidth;
            this.canvasInnerH = inner.offsetHeight;
        },

        // Re-measure every card. Called when the canvas tab becomes
        // visible — cards rendered while x-show was display:none reported
        // 0×0 dimensions, so the first reading after toggle is unreliable.
        // Inlined as a `for` statement inside an x-on handler triggers an
        // Alpine syntax error (the expression gets `return`-wrapped),
        // hence the method.
        remeasureAll() {
            for (const id of Object.keys(this.cards)) {
                this.measureOne(id);
            }
            this.measureCanvasInner();
            // Belt-and-braces: explicitly compact each column once all
            // sizes are settled.
            this.compactColumn(0);
            this.compactColumn(280);
            this.compactColumn(620);
        },

        // Walk cards in column `colX` top-to-bottom and slide each so it
        // starts no earlier than the previous card's bottom + 20px gap.
        // Cards keep their relative order; only overlapping ones move.
        compactColumn(colX) {
            const keys = Object.keys(this.cards)
                .filter(k => this.cards[k] && Math.abs(this.cards[k].x - colX) <= 50)
                .sort((a, b) => this.cards[a].y - this.cards[b].y);
            let floor = -Infinity;
            for (const k of keys) {
                const c = this.cards[k];
                const h = this.sizes[k]?.h || 140;
                if (c.y < floor) {
                    const newY = Math.round(floor / this.gridSize) * this.gridSize;
                    this.cards[k] = { x: c.x, y: newY };
                    floor = newY + h + 20;
                } else {
                    floor = c.y + h + 20;
                }
            }
        },

        measureOne(id) {
            const el = this.$refs['card_' + id];
            if (! el) return;
            this.sizes[id] = { w: el.offsetWidth, h: el.offsetHeight };
            // Always run pushOverlappers — it's a no-op when nothing in
            // the same column overlaps this card's body. After the push,
            // re-fit so any growth that pushed cards down still fits the
            // viewport without scroll.
            this.pushOverlappers(id);
        },

        pushOverlappers(growingId) {
            const grower = this.cards[growingId];
            if (! grower) return;
            const growerSize = this.sizes[growingId];
            if (! growerSize) return;
            // Chain push: process every card in the same column at or below
            // the grower, sorted top-down. Each card slides past the floor
            // set by the previous one — so a single expansion correctly
            // shifts the whole stack instead of leaving siblings nested.
            const sameColumn = Object.keys(this.cards)
                .filter(k => k !== growingId)
                .filter(k => this.cards[k] && Math.abs(this.cards[k].x - grower.x) <= 50)
                .filter(k => this.cards[k].y >= grower.y)
                .sort((a, b) => this.cards[a].y - this.cards[b].y);
            let floor = grower.y + growerSize.h + 20;
            for (const k of sameColumn) {
                const other = this.cards[k];
                const h = this.sizes[k]?.h || 140;
                let newY = other.y;
                if (other.y < floor) {
                    newY = Math.round(floor / this.gridSize) * this.gridSize;
                    this.cards[k] = { x: other.x, y: newY };
                }
                floor = newY + h + 20;
            }
        },

        cardStyle(id) {
            const c = this.cards[id];
            return `left:${c.x}px;top:${c.y}px;`;
        },

        anchor(id, edge) {
            const c = this.cards[id];
            const s = this.sizes[id] || { w: 260, h: 140 };
            switch (edge) {
                case 'right':  return [c.x + s.w, c.y + s.h / 2];
                case 'left':   return [c.x,       c.y + s.h / 2];
                case 'top':    return [c.x + s.w / 2, c.y];
                case 'bottom': return [c.x + s.w / 2, c.y + s.h];
            }
            return [c.x, c.y];
        },

        pathFor(conn) {
            const [sx, sy] = this.anchor(conn.from, conn.fromEdge);
            const [ex, ey] = this.anchor(conn.to,   conn.toEdge);
            const horizontal = conn.fromEdge === 'right' || conn.fromEdge === 'left';
            // Orthogonal step path — right-angle turns between cards.
            // Multiple connectors from the same edge share the trunk at
            // midX/midY, which reads as a neat fanned bus rather than
            // overlapping bezier arcs.
            if (horizontal) {
                const midX = (sx + ex) / 2;
                return `M ${sx} ${sy} L ${midX} ${sy} L ${midX} ${ey} L ${ex} ${ey}`;
            }
            const midY = (sy + ey) / 2;
            return `M ${sx} ${sy} L ${sx} ${midY} L ${ex} ${midY} L ${ex} ${ey}`;
        },

        // Auto-layout: snap every card back to its type column at the
        // top, then let compactColumn re-stack everything top-to-bottom.
        // Replaces the old drag-based resetPositions.
        relayout() {
            this.cards.network = { x: 0,   y: 0   };
            this.cards.domains = { x: 0,   y: 280 };
            this.cards.region  = { x: 280, y: 0   };
            let y = 0;
            for (const key of Object.keys(this.cards)) {
                if (key === 'network' || key === 'domains' || key === 'region') continue;
                this.cards[key] = { x: 620, y };
                y += (this.sizes[key]?.h || 140) + 20;
            }
            this.compactColumn(0);
            this.compactColumn(280);
            this.compactColumn(620);
        },
    }"
    x-on:canvas-shown.window.debounce.50ms="remeasureAll()"
    x-on:database-added.window="registerNode($event.detail?.id)"
    x-on:database-removed.window="unregisterNode($event.detail?.id)"
    x-on:bucket-added.window="registerNode($event.detail?.id)"
    x-on:bucket-removed.window="unregisterNode($event.detail?.id)"
>
    {{-- Canvas top bar --}}
    <div class="relative z-20 flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/8 bg-white/70 px-5 py-4 backdrop-blur-sm dark:border-brand-mist/15 dark:bg-zinc-900/70">
        <div class="flex items-center gap-2 text-sm">
            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-brand-ink text-brand-cream">
                @if ($mode === 'source')
                    <x-heroicon-o-code-bracket class="h-3.5 w-3.5" aria-hidden="true" />
                @else
                    <x-heroicon-o-cube class="h-3.5 w-3.5" aria-hidden="true" />
                @endif
            </span>
            <span class="font-mono text-sm font-semibold text-brand-ink dark:text-brand-cream">{{ $sourceLabel }}</span>
            @if ($mode === 'source' && $branch !== '')
                <span class="text-brand-mist">·</span>
                <span class="inline-flex items-center gap-1 font-mono text-xs text-brand-moss">
                    <x-heroicon-o-arrow-trending-up class="h-3 w-3" aria-hidden="true" />
                    {{ $branch }}
                </span>
            @endif
        </div>
        <div class="flex items-center gap-3 text-xs text-brand-moss">
            <button
                type="button"
                x-on:click="relayout()"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream"
            >
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Reset layout') }}
            </button>
        </div>
    </div>

    {{-- Canvas body — draggable graph surface on the left, resource palette
         on the right. The palette is the only way to add cache/bucket nodes
         to the graph; the database tile flips the wire-bound mode. --}}
    <div class="relative px-5 py-8 sm:px-8 sm:py-10">
        <div class="flex items-start gap-6">
            <div class="min-w-0 flex-1 overflow-x-hidden" x-ref="canvasViewport">
                <div
                    class="relative"
                    x-bind:style="`min-width: 900px; min-height: ${contentH()}px;`"
                    x-ref="canvasInner"
                >

            {{-- Connector layer — sits behind cards (z-0). Cards have z-10.
                 Paths are emitted server-side rather than via x-for because
                 <template> inside <svg> is parsed as an unknown SVG element,
                 not as an HTMLTemplateElement Alpine can clone — x-for there
                 silently renders nothing. --}}
            @php
                // Connection list. All connections are emitted at render
                // time — a card only exists when the underlying $databases
                // or $buckets row exists, so each connection here points
                // to a real DOM node by id.
                $connections = [
                    ['from' => 'network', 'fromEdge' => 'right', 'to' => 'region', 'toEdge' => 'left'],
                    ['from' => 'domains', 'fromEdge' => 'right', 'to' => 'region', 'toEdge' => 'left'],
                ];
                foreach ($databases as $db) {
                    $connections[] = ['from' => 'region', 'fromEdge' => 'right', 'to' => $db['_id'], 'toEdge' => 'left'];
                }
                foreach ($buckets as $bk) {
                    $connections[] = ['from' => 'region', 'fromEdge' => 'right', 'to' => $bk['_id'], 'toEdge' => 'left'];
                }
            @endphp
            <svg
                class="pointer-events-none absolute left-0 top-0 text-brand-ink/30 dark:text-brand-mist/30"
                aria-hidden="true"
                style="overflow: visible;"
                x-bind:width="canvasInnerW"
                x-bind:height="canvasInnerH"
                x-bind:viewBox="`0 0 ${canvasInnerW} ${canvasInnerH}`"
            >
                @foreach ($connections as $c)
                    <path
                        x-bind:d="pathFor({ from: '{{ $c['from'] }}', fromEdge: '{{ $c['fromEdge'] }}', to: '{{ $c['to'] }}', toEdge: '{{ $c['toEdge'] }}' })"
                        stroke="currentColor"
                        fill="none"
                        stroke-width="1.5"
                        stroke-linecap="round"
                    />
                @endforeach
                <g class="text-brand-sage/70 dark:text-brand-sage/60" fill="currentColor">
                    @foreach ($connections as $c)
                        <circle
                            x-bind:cx="anchor('{{ $c['from'] }}', '{{ $c['fromEdge'] }}')[0]"
                            x-bind:cy="anchor('{{ $c['from'] }}', '{{ $c['fromEdge'] }}')[1]"
                            r="3.5"
                        />
                        <circle
                            x-bind:cx="anchor('{{ $c['to'] }}', '{{ $c['toEdge'] }}')[0]"
                            x-bind:cy="anchor('{{ $c['to'] }}', '{{ $c['toEdge'] }}')[1]"
                            r="3.5"
                        />
                    @endforeach
                </g>
            </svg>

            {{-- =============== NETWORK CARD =============== --}}
            <div
                x-ref="card_network"
                x-bind:style="cardStyle('network')"
                class="absolute z-10 w-[260px] rounded-2xl border border-brand-ink/10 bg-white shadow-sm transition-shadow dark:border-brand-mist/20 dark:bg-zinc-900"
            >
                <div class="flex items-center justify-between border-b border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                    <div class="flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                        <x-heroicon-o-signal class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                        {{ __('Network') }}
                    </div>
                    <span class="text-brand-mist">
                        <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                    </span>
                </div>
                <div class="p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Edge network') }}</p>
                    <ul class="mt-3 space-y-2.5">
                        @foreach ([
                            ['icon' => 'shield-check', 'label' => __('DDoS protection')],
                            ['icon' => 'globe-alt',    'label' => __('CDN')],
                            ['icon' => 'bolt',         'label' => __('Edge caching')],
                        ] as $row)
                            <li class="flex items-center justify-between gap-3 rounded-lg border border-brand-ink/8 bg-brand-cream/40 px-3 py-2 text-sm dark:border-brand-mist/15 dark:bg-zinc-800/40">
                                <span class="inline-flex items-center gap-2 text-brand-ink dark:text-brand-cream">
                                    @switch ($row['icon'])
                                        @case ('shield-check')
                                            <x-heroicon-o-shield-check class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                                            @break
                                        @case ('globe-alt')
                                            <x-heroicon-o-globe-alt class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                                            @break
                                        @default
                                            <x-heroicon-o-bolt class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                                    @endswitch
                                    {{ $row['label'] }}
                                </span>
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-moss">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                                    {{ __('Pending') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            {{-- =============== DOMAINS CARD =============== --}}
            <div
                x-ref="card_domains"
                x-bind:style="cardStyle('domains')"
                class="absolute z-10 w-[260px] rounded-2xl border border-brand-ink/10 bg-white shadow-sm transition-shadow dark:border-brand-mist/20 dark:bg-zinc-900"
                x-data="{ open: true }"
            >
                <div class="flex items-center justify-between gap-3 border-b border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                    <button
                        type="button"
                        x-on:click.stop="open = ! open"
                        x-on:pointerdown.stop
                        data-drag-handle="ignore"
                        class="inline-flex items-center gap-2 text-left text-sm font-semibold text-brand-ink dark:text-brand-cream"
                    >
                        <x-heroicon-o-globe-alt class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                        {{ __('Domains') }}
                    </button>
                    <span class="flex items-center gap-2 text-xs text-brand-mist">
                        <span class="rounded-full bg-brand-cream/60 px-2 py-0.5 font-semibold text-brand-moss dark:bg-zinc-800">{{ count($domains) }} {{ __('custom') }}</span>
                        <button type="button" x-on:click.stop="open = ! open" x-on:pointerdown.stop>
                            <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                        </button>
                    </span>
                </div>
                <ul class="space-y-2 p-4">
                    <li class="flex items-center justify-between gap-3 rounded-lg border border-brand-ink/8 bg-brand-cream/40 px-3 py-2 text-sm dark:border-brand-mist/15 dark:bg-zinc-800/40">
                        <span class="inline-flex items-center gap-2 text-brand-ink dark:text-brand-cream">
                            <x-heroicon-o-globe-alt class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                            {{ __('Cloud domain') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-moss">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                            {{ __('Pending') }}
                        </span>
                    </li>
                    @foreach ($domains as $i => $hostname)
                        <li class="flex items-center justify-between gap-3 rounded-lg border border-brand-ink/8 bg-brand-cream/40 px-3 py-2 text-sm dark:border-brand-mist/15 dark:bg-zinc-800/40">
                            <span class="inline-flex items-center gap-2 font-mono text-xs text-brand-ink dark:text-brand-cream">
                                <x-heroicon-o-link class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                                {{ $hostname }}
                            </span>
                            <button type="button" wire:click="removeDomain({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
                <div x-show="open" x-collapse>
                    <div class="border-t border-brand-ink/8 bg-brand-cream/20 px-4 py-3 dark:border-brand-mist/15 dark:bg-zinc-800/40">
                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add domain') }}</label>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            <input
                                type="text"
                                wire:model="new_domain"
                                wire:keydown.enter.prevent="addDomain"
                                placeholder="app.acme.com"
                                class="dply-input min-w-[10rem] flex-1 font-mono text-xs"
                            />
                            <button type="button" wire:click="addDomain" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Add') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- =============== REGION + APP + WORKERS CARD =============== --}}
            <div
                x-ref="card_region"
                x-bind:style="cardStyle('region')"
                class="absolute z-10 w-[320px] rounded-2xl border-2 border-brand-sage/30 bg-white shadow-md transition-shadow dark:border-brand-sage/30 dark:bg-zinc-900"
                x-data="{ open: true }"
            >
                <div class="flex items-center justify-between gap-2 px-4 py-3">
                    <button
                        type="button"
                        x-on:click.stop="open = ! open"
                        x-on:pointerdown.stop
                        class="inline-flex items-center gap-2 text-left text-sm font-semibold text-brand-ink dark:text-brand-cream"
                    >
                        <x-heroicon-o-map-pin class="h-4 w-4 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                        {{ $regionLabel !== '' ? $regionLabel : __('Region (unset)') }}
                    </button>
                    <button type="button" x-on:click.stop="open = ! open" x-on:pointerdown.stop>
                        <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>
                </div>

                <div x-show="open" x-collapse>
                    <div class="border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                        <label for="canvas_region" class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</label>
                        <select id="canvas_region" wire:model.live="region" class="dply-input mt-1.5 block w-full text-sm">
                            @foreach ($regions as $r)
                                <option value="{{ $r['slug'] }}">{{ $r['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="space-y-3 px-4 pb-4">
                    {{-- App sub-card --}}
                    <section x-data="{ open: true }" class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 dark:border-brand-mist/20 dark:bg-zinc-800/50">
                        <button type="button" x-on:click="open = ! open" x-on:pointerdown.stop class="flex w-full items-center justify-between gap-2 px-3 pt-3 pb-2 text-left">
                            <div class="flex items-center gap-2 text-sm text-brand-ink dark:text-brand-cream">
                                <span class="font-semibold">{{ __('App') }}</span>
                                <span class="inline-flex items-center gap-1 rounded-md border border-brand-ink/10 bg-white px-2 py-0.5 text-[11px] font-medium text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream">
                                    <x-heroicon-o-globe-alt class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Web') }}
                                </span>
                            </div>
                            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                        </button>
                        <p class="px-3 text-xs font-mono text-brand-moss">{{ $appLabel }}</p>

                        <div class="space-y-2 px-3 pt-2 pb-3">
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 dark:border-brand-mist/15 dark:bg-zinc-900">
                                <span class="inline-flex items-center gap-2 text-xs text-brand-moss">
                                    <x-heroicon-o-cpu-chip class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Size') }}
                                </span>
                                <span class="font-mono text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $sizeLabel }}</span>
                            </div>
                            @if ($autoscaling_enabled)
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-brand-sage/25 bg-brand-sage/8 px-3 py-2 dark:border-brand-sage/30 dark:bg-brand-sage/10">
                                    <span class="inline-flex items-center gap-2 text-xs text-brand-forest dark:text-brand-sage">
                                        <x-heroicon-o-arrows-up-down class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Autoscaling') }}
                                    </span>
                                    <span class="font-mono text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $autoscaling_min }}–{{ $autoscaling_max }} · {{ $autoscaling_cpu_percent }}%</span>
                                </div>
                            @else
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 dark:border-brand-mist/15 dark:bg-zinc-900">
                                    <span class="inline-flex items-center gap-2 text-xs text-brand-moss">
                                        <x-heroicon-o-square-3-stack-3d class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Instances') }}
                                    </span>
                                    <span class="font-mono text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $instances }}× fixed</span>
                                </div>
                            @endif
                            @if ($mode === 'source')
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 dark:border-brand-mist/15 dark:bg-zinc-900">
                                    <span class="inline-flex items-center gap-2 text-xs text-brand-moss">
                                        <x-heroicon-o-bolt class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Auto-deploy') }}
                                    </span>
                                    <span class="text-xs font-semibold {{ $deploy_on_push ? 'text-brand-forest dark:text-brand-sage' : 'text-brand-mist' }}">
                                        {{ $deploy_on_push ? __('On') : __('Off') }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <div x-show="open" x-collapse>
                            <div class="space-y-3 border-t border-brand-ink/8 bg-white/60 px-3 py-3 dark:border-brand-mist/15 dark:bg-zinc-900/60">
                                <div role="tablist" class="inline-flex w-full rounded-lg border border-brand-ink/10 bg-brand-cream/60 p-0.5 text-xs dark:border-brand-mist/20 dark:bg-zinc-800/60">
                                    <button type="button" role="tab" wire:click="$set('mode', 'source')"
                                        @class([
                                            'flex-1 inline-flex items-center justify-center gap-1 rounded-md px-2 py-1.5 font-semibold transition',
                                            'bg-brand-ink text-brand-cream shadow-sm' => $mode === 'source',
                                            'text-brand-moss hover:text-brand-ink' => $mode !== 'source',
                                        ])>
                                        <x-heroicon-o-code-bracket class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Repository') }}
                                    </button>
                                    <button type="button" role="tab" wire:click="$set('mode', 'image')"
                                        @class([
                                            'flex-1 inline-flex items-center justify-center gap-1 rounded-md px-2 py-1.5 font-semibold transition',
                                            'bg-brand-ink text-brand-cream shadow-sm' => $mode === 'image',
                                            'text-brand-moss hover:text-brand-ink' => $mode !== 'image',
                                        ])>
                                        <x-heroicon-o-cube class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Image') }}
                                    </button>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('App name') }}</label>
                                    <x-text-input wire:model="name" type="text" class="mt-1 block w-full text-sm" placeholder="acme-api" />
                                </div>
                                @if ($mode === 'source')
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('GitHub repo') }}</label>
                                        <x-text-input wire:model.blur="repo" type="text" class="mt-1 block w-full font-mono text-xs" placeholder="acme/api" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Branch') }}</label>
                                            <x-text-input wire:model="branch" type="text" class="mt-1 block w-full font-mono text-xs" placeholder="main" />
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dockerfile') }}</label>
                                            <x-text-input wire:model="dockerfile_path" type="text" class="mt-1 block w-full font-mono text-xs" placeholder="auto" />
                                        </div>
                                    </div>
                                    <label class="flex cursor-pointer items-center gap-2 text-xs text-brand-ink dark:text-brand-cream">
                                        <input type="checkbox" wire:model="deploy_on_push" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                        {{ __('Auto-deploy on push') }}
                                    </label>
                                @else
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Image') }}</label>
                                        <x-text-input wire:model="image" type="text" class="mt-1 block w-full font-mono text-xs" placeholder="ghcr.io/acme/api:v1" />
                                    </div>
                                @endif
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</label>
                                        <x-text-input wire:model="port" type="number" min="1" max="65535" class="mt-1 block w-full font-mono text-xs" />
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Instances') }}</label>
                                        <x-text-input wire:model="instances" type="number" min="1" max="50" class="mt-1 block w-full font-mono text-xs" />
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</label>
                                        <select wire:model.live="size_tier" class="dply-input mt-1 block w-full text-xs">
                                            <optgroup label="{{ __('Basic') }}">
                                                <option value="small">Small</option>
                                                <option value="medium">Medium</option>
                                                <option value="large">Large</option>
                                                <option value="xlarge">XLarge</option>
                                            </optgroup>
                                            <optgroup label="{{ __('Pro') }}">
                                                <option value="small-pro">Small Pro</option>
                                                <option value="medium-pro">Medium Pro</option>
                                                <option value="large-pro">Large Pro</option>
                                                <option value="xlarge-pro">XLarge Pro</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Workers sub-card --}}
                    <section x-data="{ open: {{ ! empty($workers) ? 'true' : 'false' }} }" class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 dark:border-brand-mist/20 dark:bg-zinc-800/50">
                        @if (! empty($workers))
                            <button type="button" x-on:click="open = ! open" x-on:pointerdown.stop class="flex w-full items-center justify-between gap-3 px-3 py-3 text-left">
                                <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                                    <x-heroicon-o-queue-list class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                                    {{ trans_choice('{1} 1 worker process|[2,*] :count worker processes', count($workers), ['count' => count($workers)]) }}
                                </span>
                                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                            </button>
                        @else
                            <button type="button" x-on:click="open = true" x-on:pointerdown.stop class="flex w-full items-center justify-center gap-1.5 px-3 py-3 text-sm font-semibold text-brand-forest hover:text-brand-ink dark:text-brand-sage">
                                <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                                {{ __('Add worker cluster') }}
                            </button>
                        @endif

                        <div x-show="open" x-collapse>
                            <div class="space-y-2 border-t border-brand-ink/8 bg-white/60 px-3 py-3 dark:border-brand-mist/15 dark:bg-zinc-900/60">
                                @unless ($backendSupportsWorkers)
                                    <p class="rounded-md bg-brand-gold/10 px-2.5 py-1.5 text-[11px] text-brand-ink">
                                        {{ __('Workers need a DigitalOcean account.') }}
                                    </p>
                                @else
                                    @foreach ($workers as $i => $worker)
                                        <div class="rounded-lg border border-brand-ink/10 bg-white p-2 dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $worker['type'] === 'scheduler' ? __('Scheduler') : __('Worker') }}</span>
                                                <button type="button" wire:click="removeWorker({{ $i }})" class="text-[10px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                            </div>
                                            <input type="text" wire:model="workers.{{ $i }}.name" class="dply-input mt-1 block w-full font-mono text-[11px]" placeholder="worker-1">
                                            <input type="text" wire:model="workers.{{ $i }}.command" class="dply-input mt-1 block w-full font-mono text-[11px]" placeholder="php artisan queue:work" @disabled($worker['type'] === 'scheduler')>
                                        </div>
                                    @endforeach
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" wire:click="addWorker('worker')" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-brand-ink dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                            <x-heroicon-o-plus class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Queue worker') }}
                                        </button>
                                        <button type="button" wire:click="addWorker('scheduler')" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-brand-ink disabled:opacity-50 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream" @disabled($this->hasScheduler())>
                                            <x-heroicon-o-clock class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Scheduler') }}
                                        </button>
                                    </div>
                                @endunless
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            {{-- Deploy is no longer a draggable canvas card — it lives in
                 the palette aside as a sticky action so it never overlaps
                 the Region card's open body. --}}

            {{-- =============== DATABASE CARDS (one per $databases row) ===
                 Each entry in $databases renders its own draggable card,
                 connected to Region by a per-database SVG path. Add entries
                 from the palette (Postgres / MySQL / Redis tiles); remove
                 via the inline X confirm. --}}
            @foreach ($databases as $i => $db)
                @php
                    $rowEngine = (string) ($db['engine'] ?? 'postgres');
                    $rowMode = (string) ($db['mode'] ?? 'create');
                    $rowName = trim((string) ($db['name'] ?? '')) !== '' ? $db['name'] : __('New database');
                    $rowEngineLabel = $engineLabels[$rowEngine] ?? ucfirst($rowEngine);
                @endphp
                <div
                    x-ref="card_{{ $db['_id'] }}"
                    x-bind:style="cardStyle('{{ $db['_id'] }}')"
                    class="absolute z-10 w-[260px] rounded-2xl border border-brand-sage/30 bg-white shadow-sm transition-shadow dark:border-brand-sage/30 dark:bg-zinc-900"
                    x-data="{ open: true, confirmingRemove: false }"
                >
                    <div class="flex items-center justify-between gap-2 px-4 py-4">
                        <button type="button" x-on:click.stop="open = ! open" x-on:pointerdown.stop class="flex flex-1 items-center gap-3 text-left">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-forest ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-sage dark:ring-brand-mist/25">
                                <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-brand-ink dark:text-brand-cream">{{ $rowName }}</span>
                                <span class="block text-xs text-brand-moss">
                                    {{ $rowEngineLabel }} · {{ $db['size'] ?? 'small' }} · <span class="font-mono">{{ strtoupper((string) ($db['env_prefix'] ?? 'DB')) }}</span>
                                </span>
                            </span>
                        </button>
                        <div class="flex items-center gap-1">
                            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                            <button
                                type="button"
                                x-on:click.stop="confirmingRemove = true"
                                x-on:pointerdown.stop
                                x-show="! confirmingRemove"
                                class="rounded-md p-1 text-brand-mist hover:bg-brand-cream/40 hover:text-rose-700"
                                title="{{ __('Remove this database') }}"
                            >
                                <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    {{-- Inline remove confirm — appears on click, replacing
                         the X. Keeps the user in-flow vs. a full modal. --}}
                    <div x-show="confirmingRemove" x-on:pointerdown.stop class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/8 bg-rose-50/60 px-4 py-2 text-xs text-rose-900 dark:border-brand-mist/15 dark:bg-rose-950/30 dark:text-rose-100">
                        <span>{{ __('Remove this database from the app?') }}</span>
                        <div class="flex items-center gap-2">
                            <button type="button" x-on:click="confirmingRemove = false" class="rounded-md px-2 py-1 font-semibold text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                            <button type="button" wire:click="removeDatabase({{ $i }})" class="rounded-md bg-rose-700 px-2 py-1 font-semibold text-white hover:bg-rose-800">{{ __('Remove') }}</button>
                        </div>
                    </div>

                    <div x-show="open" x-collapse>
                        <div class="space-y-3 border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                            <div>
                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                                <input type="text" wire:model.blur="databases.{{ $i }}.name" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="postgres-1">
                                <x-input-error :messages="$errors->get('databases.'.$i.'.name')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Mode') }}</label>
                                    <select wire:model.live="databases.{{ $i }}.mode" class="dply-input mt-1 block w-full text-xs">
                                        <option value="create">{{ __('Create new') }}</option>
                                        <option value="attach" @disabled($attachableDatabases->isEmpty())>{{ __('Attach existing') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Env prefix') }}</label>
                                    <input type="text" wire:model.blur="databases.{{ $i }}.env_prefix" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="DB">
                                    <x-input-error :messages="$errors->get('databases.'.$i.'.env_prefix')" class="mt-1" />
                                </div>
                            </div>

                            @if ($rowMode === 'attach')
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Database') }}</label>
                                    <select wire:model="databases.{{ $i }}.cloud_database_id" class="dply-input mt-1 block w-full text-xs">
                                        <option value="">{{ __('— select —') }}</option>
                                        @foreach ($attachableDatabases as $existing)
                                            <option value="{{ $existing->id }}">{{ $existing->name }} · {{ $existing->engine }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('databases.'.$i.'.cloud_database_id')" class="mt-1" />
                                </div>
                            @else
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</label>
                                        <select wire:model.live="databases.{{ $i }}.engine" class="dply-input mt-1 block w-full text-xs">
                                            <option value="postgres">Postgres</option>
                                            <option value="mysql">MySQL</option>
                                            <option value="redis">Redis</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</label>
                                        <select wire:model="databases.{{ $i }}.size" class="dply-input mt-1 block w-full text-xs">
                                            <option value="small">small</option>
                                            <option value="medium">medium</option>
                                            <option value="large">large</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</label>
                                        <input type="text" wire:model.blur="databases.{{ $i }}.version" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="17">
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            {{-- =============== BUCKET CARDS (one per $buckets row) ===
                 Mirrors the database card pattern: each entry renders its
                 own draggable card connected to Region. Add via the "Add
                 bucket" palette tile; remove via the inline X confirm. --}}
            @foreach ($buckets as $i => $bk)
                @php
                    $rowName = trim((string) ($bk['name'] ?? '')) !== '' ? $bk['name'] : __('New bucket');
                @endphp
                <div
                    x-ref="card_{{ $bk['_id'] }}"
                    x-bind:style="cardStyle('{{ $bk['_id'] }}')"
                    class="absolute z-10 w-[260px] rounded-2xl border border-brand-sage/30 bg-white shadow-sm transition-shadow dark:border-brand-sage/30 dark:bg-zinc-900"
                    x-data="{ open: true, confirmingRemove: false }"
                >
                    <div class="flex items-center justify-between gap-2 px-4 py-4">
                        <button type="button" x-on:click.stop="open = ! open" x-on:pointerdown.stop class="flex flex-1 items-center gap-3 text-left">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-forest ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-sage dark:ring-brand-mist/25">
                                <x-heroicon-o-archive-box class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-brand-ink dark:text-brand-cream">{{ $rowName }}</span>
                                <span class="block text-xs text-brand-moss">
                                    {{ __('Bucket') }} · <span class="font-mono">{{ strtoupper((string) ($bk['env_prefix'] ?? 'S3')) }}</span>
                                </span>
                            </span>
                        </button>
                        <div class="flex items-center gap-1">
                            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                            <button
                                type="button"
                                x-on:click.stop="confirmingRemove = true"
                                x-on:pointerdown.stop
                                x-show="! confirmingRemove"
                                class="rounded-md p-1 text-brand-mist hover:bg-brand-cream/40 hover:text-rose-700"
                                title="{{ __('Remove this bucket') }}"
                            >
                                <x-heroicon-m-x-mark class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <div x-show="confirmingRemove" x-on:pointerdown.stop class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/8 bg-rose-50/60 px-4 py-2 text-xs text-rose-900 dark:border-brand-mist/15 dark:bg-rose-950/30 dark:text-rose-100">
                        <span>{{ __('Remove this bucket from the app?') }}</span>
                        <div class="flex items-center gap-2">
                            <button type="button" x-on:click="confirmingRemove = false" class="rounded-md px-2 py-1 font-semibold text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                            <button type="button" wire:click="removeBucket({{ $i }})" class="rounded-md bg-rose-700 px-2 py-1 font-semibold text-white hover:bg-rose-800">{{ __('Remove') }}</button>
                        </div>
                    </div>

                    <div x-show="open" x-collapse>
                        <div class="space-y-3 border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                            <div>
                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                                <input type="text" wire:model.blur="buckets.{{ $i }}.name" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="bucket-1">
                                <x-input-error :messages="$errors->get('buckets.'.$i.'.name')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Backend') }}</label>
                                    <select wire:model.live="buckets.{{ $i }}.backend" class="dply-input mt-1 block w-full text-xs">
                                        <option value="digitalocean_spaces">DO Spaces</option>
                                        <option value="aws_s3">AWS S3</option>
                                        <option value="cloudflare_r2">Cloudflare R2</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Env prefix') }}</label>
                                    <input type="text" wire:model.blur="buckets.{{ $i }}.env_prefix" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="S3">
                                    <x-input-error :messages="$errors->get('buckets.'.$i.'.env_prefix')" class="mt-1" />
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</label>
                                <input type="text" wire:model.blur="buckets.{{ $i }}.region" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="nyc3">
                                <p class="mt-1 text-[10px] text-brand-mist">{{ __('Defaults to the app region. Override per bucket if you need a different one.') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

                </div>
            </div>

            {{-- Resource palette — adding from here is the only way to put
                 cache/bucket nodes on the graph. Database is wire-bound so
                 its tile flips $database_mode and the card materializes on
                 the next render. --}}
            <aside class="w-56 shrink-0 rounded-2xl border border-brand-ink/10 bg-white/70 p-3 shadow-sm backdrop-blur-sm dark:border-brand-mist/20 dark:bg-zinc-900/70">
                <p class="px-2 pb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Add resources') }}</p>
                <div class="space-y-2">
                    {{-- One palette tile per database engine. Each click
                         adds a NEW row to $databases — multiple per engine
                         supported as long as env_prefix differs. The
                         server-dispatched 'database-added' event tells the
                         canvas where to place + scroll the new card. --}}
                    @foreach ([
                        ['engine' => 'postgres', 'label' => __('Add Postgres'),  'desc' => __('Managed Postgres cluster')],
                        ['engine' => 'mysql',    'label' => __('Add MySQL'),    'desc' => __('Managed MySQL cluster')],
                        ['engine' => 'redis',    'label' => __('Add Redis'),    'desc' => __('Managed Redis cluster')],
                    ] as $tile)
                        <button
                            type="button"
                            wire:click="addDatabase('{{ $tile['engine'] }}')"
                            class="group flex w-full items-center gap-3 rounded-xl border border-brand-ink/10 bg-white p-3 text-left transition hover:border-brand-sage/40 hover:shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900"
                        >
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-cream/60 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                                <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $tile['label'] }}</span>
                                <span class="block text-[11px] text-brand-moss">{{ $tile['desc'] }}</span>
                            </span>
                            <x-heroicon-m-plus class="h-4 w-4 text-brand-mist group-hover:text-brand-forest" aria-hidden="true" />
                        </button>
                    @endforeach

                    {{-- Bucket tile (wire-bound). Clicking adds a new row
                         to $buckets; the canvas's bucket-added listener
                         positions + scrolls the new node into view. For
                         cache, the user adds a Redis database with a
                         CACHE prefix — that path is fully end-to-end. --}}
                    <button
                        type="button"
                        wire:click="addBucket"
                        class="group flex w-full items-center gap-3 rounded-xl border border-brand-ink/10 bg-white p-3 text-left transition hover:border-brand-sage/40 hover:shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900"
                    >
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-cream/60 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                            <x-heroicon-o-archive-box class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ __('Add bucket') }}</span>
                            <span class="block text-[11px] text-brand-moss">{{ __('Object storage (DO Spaces)') }}</span>
                        </span>
                        <x-heroicon-m-plus class="h-4 w-4 text-brand-mist group-hover:text-brand-forest" aria-hidden="true" />
                    </button>
                </div>
                <p class="px-2 pt-3 text-[10px] leading-relaxed text-brand-mist">
                    {{ __('Click to drop a node on the canvas. Drag it where you want it.') }}
                </p>
            </aside>
        </div>

        {{-- Advanced configuration strip — normal grid, not draggable. --}}
        <div class="relative mt-10">
            <div class="flex items-center gap-3">
                <div class="h-px flex-1 bg-brand-ink/10 dark:bg-brand-mist/15"></div>
                <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Advanced configuration') }}</span>
                <div class="h-px flex-1 bg-brand-ink/10 dark:bg-brand-mist/15"></div>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {{-- Environment --}}
                <section x-data="{ open: {{ trim($env_file_content ?? '') !== '' ? 'true' : 'false' }} }" class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                            <x-heroicon-o-variable class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                            {{ __('Environment') }}
                        </span>
                        <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>
                    <div x-show="open" x-collapse>
                        <div class="border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                            <textarea wire:model="env_file_content" rows="5" class="dply-input block w-full font-mono text-xs" placeholder="APP_ENV=production&#10;LOG_LEVEL=info"></textarea>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('One KEY=value per line.') }}</p>
                        </div>
                    </div>
                </section>

                {{-- Autoscaling --}}
                <section x-data="{ open: {{ $autoscaling_enabled ? 'true' : 'false' }} }" class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                            <x-heroicon-o-arrows-up-down class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                            {{ __('Autoscaling') }}
                        </span>
                        <span class="flex items-center gap-2 text-xs">
                            <span class="text-{{ $autoscaling_enabled ? 'brand-forest dark:text-brand-sage' : 'brand-mist' }}">
                                {{ $autoscaling_enabled ? __('On') : __('Off') }}
                            </span>
                            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                        </span>
                    </button>
                    <div x-show="open" x-collapse>
                        <div class="space-y-3 border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" wire:model.live="autoscaling_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                <span class="text-xs text-brand-moss">{{ __('Float instance count between min and max based on CPU. Requires Pro size tier.') }}</span>
                            </label>
                            @if ($autoscaling_enabled)
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Min') }}</label>
                                        <input type="number" min="1" max="50" wire:model="autoscaling_min" class="dply-input mt-1 block w-full font-mono text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Max') }}</label>
                                        <input type="number" min="1" max="50" wire:model="autoscaling_max" class="dply-input mt-1 block w-full font-mono text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('CPU %') }}</label>
                                        <input type="number" min="1" max="100" wire:model="autoscaling_cpu_percent" class="dply-input mt-1 block w-full font-mono text-xs">
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Health check --}}
                <section x-data="{ open: {{ $health_check_enabled ? 'true' : 'false' }} }" class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                            <x-heroicon-o-heart class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                            {{ __('Health check') }}
                        </span>
                        <span class="flex items-center gap-2 text-xs">
                            <span class="text-{{ $health_check_enabled ? 'brand-forest dark:text-brand-sage' : 'brand-mist' }}">
                                {{ $health_check_enabled ? __('On') : __('Off') }}
                            </span>
                            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                        </span>
                    </button>
                    <div x-show="open" x-collapse>
                        <div class="space-y-3 border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" wire:model.live="health_check_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                <span class="text-xs text-brand-moss">{{ __('Probe each instance on a path and restart on failures.') }}</span>
                            </label>
                            @if ($health_check_enabled)
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Path') }}</label>
                                        <input type="text" wire:model="health_check_path" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="/healthz">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Period (s)') }}</label>
                                        <input type="number" min="1" wire:model="health_check_period_seconds" class="dply-input mt-1 block w-full font-mono text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Timeout (s)') }}</label>
                                        <input type="number" min="1" wire:model="health_check_timeout_seconds" class="dply-input mt-1 block w-full font-mono text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Threshold') }}</label>
                                        <input type="number" min="1" wire:model="health_check_failure_threshold" class="dply-input mt-1 block w-full font-mono text-xs">
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Deploy tasks --}}
                <section x-data="{ open: {{ $migrations_enabled || ! empty($deploy_tasks) ? 'true' : 'false' }} }" class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                            <x-heroicon-o-bolt class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                            {{ __('Deploy tasks') }}
                        </span>
                        <span class="flex items-center gap-2 text-xs text-brand-mist">
                            @if ($migrations_enabled) <span>{{ __('Migrations on') }}</span> @endif
                            <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                        </span>
                    </button>
                    <div x-show="open" x-collapse>
                        <div class="space-y-3 border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                            @unless ($backendSupportsDeployTasks)
                                <p class="rounded-md bg-brand-gold/10 px-2.5 py-1.5 text-[11px] text-brand-ink">
                                    {{ __('Deploy tasks need a DigitalOcean account.') }}
                                </p>
                            @else
                                <label class="flex cursor-pointer items-start gap-2">
                                    <input type="checkbox" wire:model.live="migrations_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                    <span class="text-xs text-brand-moss">{{ __('Run migrations on PRE_DEPLOY before traffic flips.') }}</span>
                                </label>
                                @if ($migrations_enabled)
                                    <input type="text" wire:model="migrations_command" class="dply-input block w-full font-mono text-xs" placeholder="php artisan migrate --force">
                                @endif
                                @foreach ($deploy_tasks as $i => $task)
                                    <div class="rounded-lg border border-brand-ink/10 bg-brand-cream/40 p-2 dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                        <div class="flex items-center justify-between gap-2">
                                            <select wire:model="deploy_tasks.{{ $i }}.trigger" class="dply-input block w-32 text-[11px]">
                                                <option value="pre_deploy">pre_deploy</option>
                                                <option value="post_deploy">post_deploy</option>
                                                <option value="failed_deploy">failed_deploy</option>
                                                <option value="manual">manual</option>
                                            </select>
                                            <button type="button" wire:click="removeDeployTask({{ $i }})" class="text-[10px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                        </div>
                                        <input type="text" wire:model="deploy_tasks.{{ $i }}.name" class="dply-input mt-1.5 block w-full font-mono text-[11px]" placeholder="task name">
                                        <input type="text" wire:model="deploy_tasks.{{ $i }}.command" class="dply-input mt-1.5 block w-full font-mono text-[11px]" placeholder="command">
                                    </div>
                                @endforeach
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="addDeployTask('pre_deploy')" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-brand-ink dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                        <x-heroicon-o-plus class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Pre') }}
                                    </button>
                                    <button type="button" wire:click="addDeployTask('post_deploy')" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-brand-ink dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                        <x-heroicon-o-plus class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Post') }}
                                    </button>
                                    <button type="button" wire:click="addDeployTask('manual')" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-brand-ink dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                        <x-heroicon-o-plus class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Manual') }}
                                    </button>
                                </div>
                            @endunless
                        </div>
                    </div>
                </section>

                {{-- Alerts --}}
                <section x-data="{ open: false }" class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                            <x-heroicon-o-bell-alert class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                            {{ __('Alerts') }}
                        </span>
                        <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>
                    <div x-show="open" x-collapse>
                        <div class="space-y-3 border-t border-brand-ink/8 px-4 py-3 text-xs dark:border-brand-mist/15">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" wire:model.live="alert_deployment_failed_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                <span class="text-brand-moss">{{ __('Deploy failed') }}</span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" wire:model.live="alert_restart_count_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                <span class="flex-1 text-brand-moss">
                                    {{ __('Restart loop') }}
                                    @if ($alert_restart_count_enabled)
                                        <input type="number" min="1" max="100" wire:model="alert_restart_count_value" class="dply-input ms-2 inline-block w-16 font-mono text-[11px]">
                                        <span class="text-brand-mist">{{ __('in 5m') }}</span>
                                    @endif
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" wire:model.live="alert_cpu_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                <span class="flex-1 text-brand-moss">
                                    {{ __('CPU sustained') }}
                                    @if ($alert_cpu_enabled)
                                        <input type="number" min="1" max="100" wire:model="alert_cpu_value" class="dply-input ms-2 inline-block w-16 font-mono text-[11px]">
                                        <span class="text-brand-mist">% {{ __('for 5m') }}</span>
                                    @endif
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="checkbox" wire:model.live="alert_mem_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                <span class="flex-1 text-brand-moss">
                                    {{ __('Memory sustained') }}
                                    @if ($alert_mem_enabled)
                                        <input type="number" min="1" max="100" wire:model="alert_mem_value" class="dply-input ms-2 inline-block w-16 font-mono text-[11px]">
                                        <span class="text-brand-mist">% {{ __('for 5m') }}</span>
                                    @endif
                                </span>
                            </label>
                        </div>
                    </div>
                </section>

                {{-- Detect runtime (source mode) --}}
                @if ($mode === 'source')
                    <section x-data="{ open: false }" class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                        <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                            <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink dark:text-brand-cream">
                                <x-heroicon-o-sparkles class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                                {{ __('Detect runtime') }}
                            </span>
                            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                        </button>
                        <div x-show="open" x-collapse>
                            <div class="space-y-3 border-t border-brand-ink/8 px-4 py-3 dark:border-brand-mist/15">
                                <button
                                    type="button"
                                    wire:click="detectFromRepository"
                                    wire:loading.attr="disabled"
                                    wire:target="detectFromRepository"
                                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                                >
                                    <x-heroicon-o-sparkles wire:loading.remove wire:target="detectFromRepository" class="h-3.5 w-3.5" aria-hidden="true" />
                                    <x-spinner wire:loading wire:target="detectFromRepository" size="sm" variant="cream" />
                                    <span wire:loading.remove wire:target="detectFromRepository">{{ __('Detect runtime') }}</span>
                                    <span wire:loading wire:target="detectFromRepository">{{ __('Detecting…') }}</span>
                                </button>
                                <div class="rounded-lg border border-brand-ink/8 bg-brand-cream/40 p-3 dark:border-brand-mist/15 dark:bg-zinc-800/40">
                                    @include('livewire.partials._runtime-detection-panel')
                                </div>
                            </div>
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </div>
</div>
