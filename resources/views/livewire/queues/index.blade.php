@php
    use App\Modules\Queue\Models\QueueNamespace;
    use App\Modules\Queue\Support\QueueEndpoint;

    // No provisioning state: a namespace is a row, so creation is synchronous
    // and it is active or nothing.
    $statusTone = [
        QueueNamespace::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        QueueNamespace::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        QueueNamespace::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);

    // Fullness reads as a verdict, not a percentage: a tier's max depth is a
    // hard push-rejection threshold, so nearing it means jobs are about to be
    // refused. Tones are text colours because the dial arc strokes them.
    $fullTone = match (true) {
        $metrics['fullest'] === null => 'text-brand-sage',
        $metrics['fullestPercent'] >= 90 => 'text-brand-rust',
        $metrics['fullestPercent'] >= 70 => 'text-brand-gold',
        default => 'text-brand-sage',
    };

    // A real backlog can still round to 0% of a large cap, and an empty arc
    // reads as "failed to load" rather than "barely used". Give any non-zero
    // backlog a visible sliver; the label keeps the true figure.
    $dialPercent = $metrics['backlog'] > 0
        ? max(2, min(100, $metrics['fullestPercent']))
        : 0;

    $activityMax = max(1, collect($metrics['activity'])->max('jobs') ?? 1);
@endphp

<div class="contents">
    <x-workspace-nav />

    {{-- Scoped motion for the console: the dial draws itself and the activity
         bars rise on first paint. Nothing loops — a queue console that pulses
         forever would compete with the numbers it exists to show. --}}
    @verbatim
        <style>
            @keyframes dply-bar-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
            /* --dial-full is the full circumference, set inline on the arc. */
            @keyframes dply-dial-draw { from { stroke-dashoffset: var(--dial-full); } }
            @keyframes dply-meter-fill { from { width: 0; } to { width: var(--meter-w); } }
            .dply-bar { transform-origin: bottom; animation: dply-bar-rise .55s cubic-bezier(.16,1,.3,1) both; }
            .dply-dial { animation: dply-dial-draw 1s cubic-bezier(.16,1,.3,1) both; }
            .dply-meter { width: var(--meter-w); animation: dply-meter-fill .7s cubic-bezier(.16,1,.3,1) both; }
            @media (prefers-reduced-motion: reduce) {
                .dply-bar, .dply-dial, .dply-meter { animation: none; }
            }
        </style>
    @endverbatim

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="__('Queues')"
            :description="__('Managed job queues for your apps — an SQS-compatible endpoint your Laravel worker drains, with no Redis to run.')"
            icon="heroicon-o-queue-list"
        >
            <x-slot:actions>
                @if ($canManage && $namespaces->isNotEmpty() && ! $atLimit)
                    <button
                        type="button"
                        wire:click="startCreate"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('New queue') }}
                    </button>
                @endif
            </x-slot:actions>

            @if ($namespaces->isNotEmpty())
                <x-slot:stats>
                    {{-- The queue console. Inverted against the rest of the card so
                         the backlog verdict lands first: a tier's max depth is a
                         hard threshold past which pushes are rejected, and that
                         outranks the spend or the queue count. --}}
                    <div class="relative overflow-hidden bg-brand-ink text-brand-cream">
                        <div class="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full bg-brand-sage/25 blur-3xl" aria-hidden="true"></div>
                        <div class="pointer-events-none absolute -left-24 -bottom-24 h-64 w-64 rounded-full bg-brand-gold/15 blur-3xl" aria-hidden="true"></div>

                        {{-- Three zones: the backlog verdict, the numbers behind it,
                             and two weeks of throughput. The chart column is capped
                             so 14 bars stay a chart rather than stretching into
                             slabs across the full card width. --}}
                        <div class="relative grid gap-6 px-4 py-5 sm:px-6 sm:py-6 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,24rem)] lg:items-center lg:gap-8">
                            <div class="flex items-center gap-4 sm:gap-5">
                                <div class="relative shrink-0">
                                    <svg viewBox="0 0 120 120" class="h-24 w-24 -rotate-90 sm:h-28 sm:w-28" aria-hidden="true">
                                        <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" class="text-brand-cream/12" />
                                        <circle
                                            cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                                            class="dply-dial {{ $fullTone }}"
                                            stroke-dasharray="326.726"
                                            stroke-dashoffset="{{ 326.726 * (1 - $dialPercent / 100) }}"
                                            style="--dial-full: 326.726"
                                        />
                                    </svg>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                                        <span class="font-mono text-2xl font-semibold tabular-nums leading-none text-brand-cream sm:text-[28px]">{{ number_format($metrics['backlog']) }}</span>
                                        <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('queued') }}</span>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Backlog') }}</p>
                                    <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                        @if ($metrics['fullest'] !== null && $metrics['fullestPercent'] >= 90)
                                            {{ __(':name is nearly full.', ['name' => $metrics['fullest']->name]) }}
                                        @elseif ($metrics['fullest'] !== null && $metrics['fullestPercent'] >= 70)
                                            {{ __(':name is filling up.', ['name' => $metrics['fullest']->name]) }}
                                        @elseif ($metrics['backlog'] === 0)
                                            {{ __('Every queue is drained.') }}
                                        @else
                                            {{ __('Queues are draining normally.') }}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-brand-cream/55">
                                        @if ($metrics['fullest'] !== null)
                                            {{ __(':pct% of :name’s :cap job limit', [
                                                'pct' => $metrics['fullestPercent'],
                                                'name' => $metrics['fullest']->name,
                                                'cap' => number_format($metrics['fullestCap']),
                                            ]) }}
                                        @else
                                            {{ __('No tier on this workspace caps queue depth.') }}
                                        @endif
                                    </p>

                                    @if ($metrics['failed'] > 0)
                                        <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-brand-rust/15 px-2 py-0.5 text-2xs font-semibold text-brand-rust ring-1 ring-inset ring-brand-rust/25">
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ trans_choice(':count failed job|:count failed jobs', $metrics['failed'], ['count' => number_format($metrics['failed'])]) }}
                                        </p>
                                    @endif
                                    @if ($metrics['depthPartial'])
                                        {{-- Totals built from a store that only
                                             partly answered are not the whole
                                             picture; say so rather than let them
                                             read as complete. --}}
                                        <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-brand-gold/15 px-2 py-0.5 text-2xs font-semibold text-brand-gold ring-1 ring-inset ring-brand-gold/25">
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ __('Some depths could not be read') }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            {{-- The breakdown. Pending / delayed / reserved are three
                                 different situations — work waiting, work scheduled,
                                 and work a worker is holding — and collapsing them
                                 into one number hides which is which. --}}
                            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Pending') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($metrics['pending']) }}</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('claimable now') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Delayed') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($metrics['delayed']) }}</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('scheduled later') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('In flight') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($metrics['reserved']) }}</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('held by a worker') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('On this bill') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $money($monthlyCents) }}</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">
                                        {{ $billingEnabled
                                            ? trans_choice(':count billable|:count billable', $billableCount, ['count' => $billableCount])
                                            : __('free while in beta') }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Queues') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $metrics['namespaces'] }}@if ($entitlement->hasNamespaceLimit())<span class="text-sm text-brand-cream/40">/{{ $entitlement->maxNamespaces }}</span>@endif</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">
                                        {{ $freeCount > 0
                                            ? __(':count included free', ['count' => $freeCount])
                                            : __('on the :plan plan', ['plan' => ucfirst($entitlement->planKey)]) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Pushed today') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ number_format($metrics['jobsToday']) }}</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __(':n in :days days', ['n' => number_format($metrics['jobsWindow']), 'days' => $metrics['activityDays']]) }}</dd>
                                </div>
                            </dl>

                            {{-- Two weeks of pushes. Real recorded history, unlike the
                                 live depth above — this comes from the daily usage
                                 rollup, so it survives a page reload. --}}
                            <div class="min-w-0">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Jobs pushed') }}</p>
                                    <span class="font-mono text-2xs text-brand-cream/40">{{ __('last :days days', ['days' => $metrics['activityDays']]) }}</span>
                                </div>
                                @if ($metrics['jobsWindow'] === 0)
                                    <p class="mt-3 text-xs text-brand-cream/45">{{ __('Nothing pushed yet — the chart fills in as your app enqueues work.') }}</p>
                                @else
                                    <div class="mt-3 flex h-16 items-end gap-1">
                                        @foreach ($metrics['activity'] as $index => $day)
                                            <div
                                                class="dply-bar flex-1 rounded-sm {{ $day['jobs'] > 0 ? 'bg-brand-sage' : 'bg-brand-cream/10' }}"
                                                style="height: {{ $day['jobs'] > 0 ? max(6, (int) round($day['jobs'] / $activityMax * 100)) : 4 }}%; animation-delay: {{ $index * 35 }}ms"
                                                title="{{ $day['date'] }} — {{ number_format($day['jobs']) }}"
                                            ></div>
                                        @endforeach
                                    </div>
                                    <p class="mt-1.5 flex items-baseline justify-between text-2xs text-brand-cream/40">
                                        <span>{{ \Illuminate\Support\Carbon::parse($metrics['activity'][0]['date'])->isoFormat('D MMM') }}</span>
                                        <span>{{ __('peak :n/day', ['n' => number_format($activityMax)]) }}</span>
                                        <span>{{ __('today') }}</span>
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-slot:stats>
            @endif

            <div class="px-3 py-3 sm:px-4">
                @if ($endpointBase === '')
                    {{-- Without a publicly reachable URL there is no endpoint to hand
                         out, and a queue nobody can reach is worse than none. --}}
                    <x-alert tone="warning" class="mb-3">
                        {{ __('dply Queue has no public endpoint configured, so queues created here would be unreachable. Set DPLY_QUEUE_PUBLIC_URL (or DPLY_PUBLIC_APP_URL) first.') }}
                    </x-alert>
                @endif

                @if ($namespaces->isEmpty())
                    <x-empty-state
                        icon="heroicon-o-queue-list"
                        :title="__('No queues yet')"
                        :description="__('A queue gives your app a managed place to put background jobs — no Redis to provision, and your worker drains it over the SQS protocol Laravel already speaks. Serverless sites get one automatically, free.')"
                    >
                        @if ($canManage && $endpointBase !== '')
                            <button
                                type="button"
                                wire:click="startCreate"
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create a queue') }}
                            </button>
                        @endif
                    </x-empty-state>
                @else
                    <div class="divide-y divide-brand-ink/10">
                        @foreach ($namespaces as $namespace)
                            @php
                                $depth = $depths[$namespace->id] ?? null;
                                $tierCfg = $namespace->tierConfig();
                                $failedCount = $failed[$namespace->id] ?? null;
                                $endpoint = QueueEndpoint::forNamespace($namespace);

                                $total = $depth?->total() ?? 0;
                                $capacity = $tierCfg->maxQueueDepth;
                                $fillPercent = $capacity > 0 ? min(100, (int) round($total / $capacity * 100)) : 0;

                                $rowTone = match (true) {
                                    $namespace->status === QueueNamespace::STATUS_FAILED => 'bg-brand-rust',
                                    ! $namespace->isActive() => 'bg-brand-mist',
                                    $fillPercent >= 90 => 'bg-brand-rust',
                                    $fillPercent >= 70 => 'bg-brand-gold',
                                    default => 'bg-brand-sage',
                                };
                            @endphp
                            <article class="group relative px-2 py-4 transition-colors hover:bg-brand-sand/15 sm:px-3">
                                {{-- Status rail: the row's health readable down the
                                     left edge before any text is parsed. --}}
                                <span class="pointer-events-none absolute inset-y-3 left-0 w-0.5 rounded-full {{ $rowTone }} opacity-60" aria-hidden="true"></span>

                                <a href="{{ route('queues.show', $namespace) }}" wire:navigate
                                    class="absolute inset-0 z-0 rounded-[inherit]" aria-label="{{ __('Manage :name', ['name' => $namespace->name]) }}"></a>

                                <div class="pointer-events-none relative z-10 flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="truncate text-base font-semibold tracking-tight text-brand-ink">{{ $namespace->name }}</h3>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$namespace->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                                                {{ ucfirst($namespace->status) }}
                                            </span>
                                            @if (! $namespace->isBillable())
                                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/12 px-2 py-0.5 text-xs font-medium text-brand-forest ring-1 ring-inset ring-brand-sage/25">
                                                    <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                    {{ __('included') }}
                                                </span>
                                            @endif
                                            @if ($failedCount)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-200">
                                                    <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                    {{ trans_choice(':count failed|:count failed', $failedCount, ['count' => number_format($failedCount)]) }}
                                                </span>
                                            @endif
                                            <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform group-hover:translate-x-0.5" />

                                            @if ($canManage)
                                                {{-- Above the stretched link, so the row still
                                                     clicks through everywhere else. --}}
                                                <span class="pointer-events-auto ml-auto flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        wire:click="togglePause('{{ $namespace->id }}')"
                                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                                    >
                                                        @if ($namespace->status === QueueNamespace::STATUS_PAUSED)
                                                            <x-heroicon-o-play class="h-3.5 w-3.5 shrink-0 text-brand-forest" aria-hidden="true" />
                                                            {{ __('Resume') }}
                                                        @else
                                                            <x-heroicon-o-pause class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                                                            {{ __('Pause') }}
                                                        @endif
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="confirmDelete('{{ $namespace->id }}')"
                                                        class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700"
                                                    >
                                                        <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                        {{ __('Delete') }}
                                                    </button>
                                                </span>
                                            @endif
                                        </div>

                                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                            @if ($namespace->site !== null)
                                                <span class="inline-flex items-center gap-1.5 rounded-md bg-brand-sand/40 px-2 py-0.5 text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">
                                                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                                                    {{ $namespace->site->name }}
                                                </span>
                                            @endif
                                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-2 py-0.5 text-xs text-brand-moss ring-1 ring-inset ring-brand-ink/10">
                                                {{ $tierCfg->label }}
                                                @if ($capacity > 0)
                                                    <span class="ml-1 text-brand-mist">· {{ number_format($capacity) }} {{ __('max') }}</span>
                                                @endif
                                            </span>
                                            {{-- The endpoint is the one string anyone
                                                 comes here to fetch, so it is copyable
                                                 without opening the queue. --}}
                                            @if ($endpoint !== '')
                                                <span
                                                    class="pointer-events-auto inline-flex max-w-full items-center gap-1.5 rounded-md bg-brand-sand/40 py-0.5 pl-2 pr-1 ring-1 ring-inset ring-brand-ink/10"
                                                    x-data="{ copied: false, async copyVal() { try { await navigator.clipboard.writeText(@js($endpoint)); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }"
                                                >
                                                    <span class="text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('endpoint') }}</span>
                                                    <span class="truncate font-mono text-xs text-brand-moss">{{ $endpoint }}</span>
                                                    <button
                                                        type="button"
                                                        @click="copyVal()"
                                                        class="shrink-0 rounded p-0.5 text-brand-mist transition-colors hover:text-brand-forest"
                                                        :aria-label="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"
                                                    >
                                                        <span x-show="! copied"><x-heroicon-o-clipboard-document class="h-3.5 w-3.5" aria-hidden="true" /></span>
                                                        <span x-show="copied" x-cloak class="text-brand-forest"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" /></span>
                                                    </button>
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Depth: the segmented bar is the point. One
                                         number cannot distinguish a queue backing up
                                         from one whose workers are chewing through it. --}}
                                    <div class="w-full shrink-0 sm:w-64">
                                        @if ($depth === null)
                                            {{-- The job store is a separate database; say so rather
                                                 than render a zero that looks like an empty queue. --}}
                                            <p class="text-right text-xs text-brand-mist" title="{{ __('The job store could not be reached.') }}">
                                                {{ __('Depth unavailable') }}
                                            </p>
                                        @else
                                            <div class="flex items-baseline justify-between gap-2">
                                                <span class="font-mono text-lg font-semibold tabular-nums text-brand-ink">
                                                    {{ trans_choice(':count job|:count jobs', $total, ['count' => number_format($total)]) }}
                                                </span>
                                                <span class="font-mono text-xs tabular-nums text-brand-mist">
                                                    {{ $capacity > 0 ? '/ '.number_format($capacity) : __('no limit') }}
                                                </span>
                                            </div>
                                            <div class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-brand-ink/8">
                                                @if ($total === 0)
                                                    <div class="dply-meter h-full rounded-full bg-brand-sage/40" style="--meter-w: 2%"></div>
                                                @else
                                                    {{-- Segments are shares of the CURRENT
                                                         depth, scaled to the tier fill, so
                                                         the bar's length still reads against
                                                         the cap. --}}
                                                    @php $scale = $capacity > 0 ? max(2, $fillPercent) : 100; @endphp
                                                    <div class="dply-meter h-full bg-brand-sage" style="--meter-w: {{ $depth->pending / $total * $scale }}%" title="{{ __('pending') }}"></div>
                                                    <div class="dply-meter h-full bg-brand-gold" style="--meter-w: {{ $depth->delayed / $total * $scale }}%" title="{{ __('delayed') }}"></div>
                                                    <div class="dply-meter h-full bg-brand-moss" style="--meter-w: {{ $depth->reserved / $total * $scale }}%" title="{{ __('in flight') }}"></div>
                                                @endif
                                            </div>
                                            <div class="mt-1.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-2xs text-brand-moss">
                                                <span class="inline-flex items-center gap-2.5">
                                                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-brand-sage"></span>{{ __(':count pending', ['count' => number_format($depth->pending)]) }}</span>
                                                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-brand-gold"></span>{{ __(':count delayed', ['count' => number_format($depth->delayed)]) }}</span>
                                                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-brand-moss"></span>{{ __(':count in flight', ['count' => number_format($depth->reserved)]) }}</span>
                                                </span>
                                                <span class="font-semibold text-brand-forest">
                                                    @if (! $namespace->isBillable())
                                                        {{ __('Included') }}
                                                    @elseif (! $billingEnabled)
                                                        <span class="text-brand-mist">{{ __('Free (beta)') }}</span>
                                                    @else
                                                        {{ $money($tierCfg->priceCents) }}/{{ __('mo') }}
                                                    @endif
                                                </span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @if ($atLimit)
                        <p class="mt-3 text-xs text-brand-mist">
                            {{ __('This plan allows :max queue(s). Upgrade to add another.', ['max' => $entitlement->maxNamespaces]) }}
                        </p>
                    @endif
                @endif
            </div>
        </x-profile-shell>
    </div>

    {{-- Create modal --}}
    <x-modal name="queue-create-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('New queue') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Creates a managed queue endpoint and its first credential. Point your app at it with QUEUE_CONNECTION=dply.') }}
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <x-input-label for="queue_name" :value="__('Name')" />
                    <input
                        id="queue_name"
                        type="text"
                        wire:model="createName"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        placeholder="{{ __('checkout-workers') }}"
                    />
                    <x-input-error :messages="$errors->get('createName')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="queue_tier" :value="__('Capacity tier')" />
                    <select
                        id="queue_tier"
                        wire:model.live="createTier"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        @foreach ($tiers as $slug => $tierOption)
                            <option value="{{ $slug }}">
                                {{ $tierOption->label }} — {{ number_format($tierOption->maxQueueDepth) }} {{ __('jobs deep') }}, {{ number_format($tierOption->requestsPerMinute) }} {{ __('req/min') }} — {{ $money($tierOption->priceCents) }}/{{ __('mo') }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @php $chosen = $tiers[$createTier] ?? null; @endphp
                <label class="flex items-start gap-2 rounded-lg bg-brand-sand/30 p-3">
                    <input type="checkbox" wire:model="confirmCreateCharge" class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                    <span class="text-xs text-brand-moss">
                        @if ($billingEnabled)
                            {{ __('I understand this adds :price/month to my workspace subscription.', ['price' => $chosen ? $money($chosen->priceCents) : '—']) }}
                        @else
                            {{ __('I understand this queue is free during the beta and will bill at :price/month when dply Queue leaves beta.', ['price' => $chosen ? $money($chosen->priceCents) : '—']) }}
                        @endif
                        {{ __('Queues attached to a dply Serverless site are always included at no charge.') }}
                    </span>
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelCreate" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    wire:click="createNamespace"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest"
                >
                    {{ __('Create queue') }}
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Delete modal --}}
    <x-modal name="queue-delete-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">
                {{ __('Delete :name?', ['name' => $deletingNamespace?->name ?? __('this queue')]) }}
            </h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Any jobs still in this queue are discarded, and apps using its credentials will start failing to enqueue. This cannot be undone.') }}
            </p>

            @php $pending = $deletingNamespace !== null ? ($depths[$deletingNamespace->id] ?? null) : null; @endphp
            @if ($pending !== null && $pending->total() > 0)
                <x-alert tone="warning" class="mt-3">
                    {{ trans_choice(
                        ':count job is still queued and will be discarded.|:count jobs are still queued and will be discarded.',
                        $pending->total(),
                        ['count' => number_format($pending->total())],
                    ) }}
                </x-alert>
            @endif

            {{-- Typing the name, not just clicking through. This throws away
                 jobs the customer's app believes are still going to run, with
                 no undo and no trace — the friction is the point. --}}
            @if ($deletingNamespace !== null)
                <div class="mt-4">
                    <label for="delete-confirmation" class="block text-xs font-medium text-brand-moss">
                        {{ __('Type :name to confirm', ['name' => $deletingNamespace->name]) }}
                    </label>
                    <input
                        id="delete-confirmation"
                        type="text"
                        wire:model="deleteConfirmation"
                        autocomplete="off"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        placeholder="{{ $deletingNamespace->name }}"
                    />
                </div>
            @endif

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelDelete" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <x-danger-button wire:click="deleteNamespace">{{ __('Delete queue') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
