@php
    use App\Modules\Realtime\Models\RealtimeApp;

    $statusTone = [
        RealtimeApp::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        RealtimeApp::STATUS_PROVISIONING => 'bg-amber-100 text-amber-700 ring-amber-200',
        RealtimeApp::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        RealtimeApp::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);

    // Utilisation reads as a verdict, not a percentage: room to grow is calm,
    // approaching the cap is a warning, at the cap means clients are being
    // refused right now. Tones are text colours because the arc strokes them.
    $utilTone = match (true) {
        $metrics['activeApps'] === 0 => 'text-brand-mist',
        $metrics['tightestPercent'] >= 90 => 'text-brand-rust',
        $metrics['tightestPercent'] >= 70 => 'text-brand-gold',
        default => 'text-brand-sage',
    };

    // A workspace with real traffic can still round to 0%, and a dial with no
    // arc at all reads as "failed to load" rather than "barely used". Give any
    // non-zero peak a visible sliver; the label keeps the true figure.
    $dialPercent = $metrics['peak'] > 0
        ? max(2, min(100, $metrics['utilisation']))
        : 0;
@endphp

<div class="contents">
    <x-workspace-nav />

    {{-- Scoped motion for the console: the dial draws itself, capacity meters
         fill, and the emitting rings give the relay a pulse. Nothing that
         carries a number animates on a loop — only the decorative rings do. --}}
    @verbatim
        <style>
            /* --dial-full is the full circumference, set inline on the arc. */
            @keyframes dply-dial-draw { from { stroke-dashoffset: var(--dial-full); } }
            @keyframes dply-meter-fill { from { width: 0; } to { width: var(--meter-w); } }
            @keyframes dply-ping-out {
                0%   { transform: scale(.82); opacity: .7; }
                70%  { transform: scale(1.28); opacity: 0; }
                100% { transform: scale(1.28); opacity: 0; }
            }
            .dply-dial { animation: dply-dial-draw 1s cubic-bezier(.16,1,.3,1) both; }
            .dply-meter { width: var(--meter-w); animation: dply-meter-fill .7s cubic-bezier(.16,1,.3,1) both; }
            .dply-ping { animation: dply-ping-out 3.2s cubic-bezier(0,0,.2,1) infinite; }
            .dply-ping-2 { animation-delay: 1.6s; }
            @media (prefers-reduced-motion: reduce) {
                .dply-dial, .dply-meter, .dply-ping { animation: none; }
                .dply-ping { opacity: 0; }
            }
        </style>
    @endverbatim

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="__('Realtime')"
            :description="__('Managed Pusher-compatible relay for your apps. Each active app is billed monthly by its connection tier and added to this workspace subscription.')"
            icon="heroicon-o-signal"
        >
            <x-slot:actions>
                @if ($featureActive && $canManage && $apps->isNotEmpty())
                    <button
                        type="button"
                        wire:click="startCreate"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('New app') }}
                    </button>
                @endif
            </x-slot:actions>

            @if ($apps->isNotEmpty())
                <x-slot:stats>
                    {{-- The relay console. Inverted against the rest of the card so
                         the headroom verdict lands first: a tier is a hard
                         connection cap, so how close the busiest app is to its own
                         ceiling matters more than the spend or the app count. --}}
                    <div class="relative overflow-hidden bg-brand-ink text-brand-cream">
                        <div class="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full bg-brand-sage/25 blur-3xl" aria-hidden="true"></div>
                        <div class="pointer-events-none absolute -left-24 -bottom-24 h-64 w-64 rounded-full bg-brand-gold/15 blur-3xl" aria-hidden="true"></div>

                        <div class="relative grid gap-6 px-4 py-5 sm:px-6 sm:py-6 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,24rem)] lg:items-center lg:gap-8">
                            {{-- Headroom dial. The emitting rings are the one piece
                                 of pure decoration here, and they only run while an
                                 app is actually active — a dead relay should not
                                 look like it is transmitting. --}}
                            <div class="flex items-center gap-4 sm:gap-5">
                                <div class="relative shrink-0">
                                    @if ($metrics['activeApps'] > 0)
                                        <span class="dply-ping pointer-events-none absolute inset-0 rounded-full ring-1 ring-brand-sage/30" aria-hidden="true"></span>
                                        <span class="dply-ping dply-ping-2 pointer-events-none absolute inset-0 rounded-full ring-1 ring-brand-sage/20" aria-hidden="true"></span>
                                    @endif
                                    <svg viewBox="0 0 120 120" class="relative h-24 w-24 -rotate-90 sm:h-28 sm:w-28" aria-hidden="true">
                                        <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" class="text-brand-cream/12" />
                                        <circle
                                            cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                                            class="dply-dial {{ $utilTone }}"
                                            stroke-dasharray="326.726"
                                            stroke-dashoffset="{{ 326.726 * (1 - $dialPercent / 100) }}"
                                            style="--dial-full: 326.726"
                                        />
                                    </svg>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                                        <span class="font-mono text-2xl font-semibold tabular-nums leading-none text-brand-cream sm:text-[28px]">{{ $metrics['utilisation'] }}<span class="text-base text-brand-cream/50">%</span></span>
                                        <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('of cap') }}</span>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Connection headroom') }}</p>
                                    <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                        @if ($metrics['activeApps'] === 0)
                                            {{ __('No app is relaying yet.') }}
                                        @elseif ($metrics['tightestPercent'] >= 90)
                                            {{ __(':name is at :pct% of its cap.', ['name' => $metrics['tightestApp']->name, 'pct' => $metrics['tightestPercent']]) }}
                                        @elseif ($metrics['tightestPercent'] >= 70)
                                            {{ __(':name is filling up.', ['name' => $metrics['tightestApp']->name]) }}
                                        @else
                                            {{ __('Room to grow on every app.') }}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-brand-cream/55">
                                        {{ __(':peak peak of :cap connections across :count active', [
                                            'peak' => number_format($metrics['peak']),
                                            'cap' => number_format($metrics['capacity']),
                                            'count' => trans_choice(':value app|:value apps', $metrics['activeApps'], ['value' => $metrics['activeApps']]),
                                        ]) }}
                                    </p>
                                    @if ($metrics['staleCount'] > 0)
                                        {{-- A peak nobody has refreshed is a number about
                                             the past, so say so rather than let the dial
                                             imply it is current. --}}
                                        <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-brand-gold/15 px-2 py-0.5 text-2xs font-semibold text-brand-gold ring-1 ring-inset ring-brand-gold/25">
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ trans_choice(':count app has not reported in 24h|:count apps have not reported in 24h', $metrics['staleCount'], ['count' => $metrics['staleCount']]) }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            {{-- The supporting roster, in the order an operator asks:
                                 what am I paying, how many apps, who depends on them. --}}
                            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4 lg:grid-cols-2" aria-label="{{ __('Realtime at a glance') }}">
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('On this bill') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $money($metrics['monthlyCents']) }}</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __(':amount / yr', ['amount' => $money($metrics['annualCents'])]) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Apps') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $metrics['activeApps'] }}<span class="text-sm text-brand-cream/40">/{{ $metrics['apps'] }}</span></dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">
                                        @if ($metrics['provisioning'] > 0)
                                            {{ __(':count provisioning', ['count' => $metrics['provisioning']]) }}
                                        @elseif ($metrics['failed'] > 0)
                                            <span class="text-brand-rust">{{ trans_choice(':count failed|:count failed', $metrics['failed'], ['count' => $metrics['failed']]) }}</span>
                                        @else
                                            {{ __('all relaying') }}
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Sites bound') }}</dt>
                                    <dd class="mt-1.5 font-mono text-xl font-semibold tabular-nums text-brand-cream">{{ $metrics['boundSites'] }}</dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('broadcasting through dply') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Last report') }}</dt>
                                    <dd class="mt-1.5 text-sm font-semibold text-brand-cream">
                                        {{ $metrics['lastStatsAt'] ? $metrics['lastStatsAt']->diffForHumans(short: true) : __('never') }}
                                    </dd>
                                    <dd class="mt-0.5 text-2xs text-brand-cream/45">{{ __('relay stats') }}</dd>
                                </div>
                            </dl>

                            {{-- Per-app capacity. A workspace-wide ratio hides one app
                                 at 99% behind three that are idle, so every active app
                                 gets its own bar against its own cap. --}}
                            <div class="min-w-0">
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Peak against tier cap') }}</p>
                                @if ($metrics['activeApps'] === 0)
                                    <p class="mt-3 text-xs text-brand-cream/45">{{ __('Nothing active to measure yet.') }}</p>
                                @else
                                    <ul class="mt-3 space-y-2.5">
                                        @foreach ($apps->where('status', RealtimeApp::STATUS_ACTIVE)->take(4) as $activeApp)
                                            @php
                                                $cap = $activeApp->maxConnections();
                                                $pct = $cap > 0 ? min(100, (int) round((int) $activeApp->peak_connections / $cap * 100)) : 0;
                                                $barTone = match (true) {
                                                    $pct >= 90 => 'bg-brand-rust',
                                                    $pct >= 70 => 'bg-brand-gold',
                                                    default => 'bg-brand-sage',
                                                };
                                            @endphp
                                            <li>
                                                <div class="flex items-baseline justify-between gap-3 text-2xs">
                                                    <span class="truncate font-medium text-brand-cream/80">{{ $activeApp->name }}</span>
                                                    <span class="shrink-0 font-mono tabular-nums text-brand-cream/50">{{ number_format((int) $activeApp->peak_connections) }} / {{ number_format($cap) }}</span>
                                                </div>
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-brand-cream/10">
                                                    {{-- A 0% peak still gets a hairline: an empty
                                                         track reads as "no data", not "no traffic". --}}
                                                    <div class="dply-meter h-full rounded-full {{ $barTone }}" style="--meter-w: {{ max(2, $pct) }}%"></div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($metrics['activeApps'] > 4)
                                        <p class="mt-2.5 text-2xs text-brand-cream/40">{{ __('+:count more below', ['count' => $metrics['activeApps'] - 4]) }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>

                        {{-- Second band: what the capacity costs and where it is
                             being wasted. Split off from the headroom row because
                             these are decisions to make later, not the state of
                             the relay right now. --}}
                        <div class="relative border-t border-brand-cream/10 px-4 py-4 sm:px-6">
                            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3 lg:grid-cols-6">
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Provisioned') }}</dt>
                                    <dd class="mt-1 font-mono text-base font-semibold tabular-nums text-brand-cream">{{ number_format($metrics['capacity']) }}</dd>
                                    <dd class="text-2xs text-brand-cream/45">{{ __('connection slots') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Headroom') }}</dt>
                                    <dd class="mt-1 font-mono text-base font-semibold tabular-nums text-brand-cream">{{ number_format($metrics['headroom']) }}</dd>
                                    <dd class="text-2xs text-brand-cream/45">{{ __('before the cap') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Unit cost') }}</dt>
                                    <dd class="mt-1 font-mono text-base font-semibold tabular-nums text-brand-cream">{{ $money($metrics['centsPerThousandCapacity']) }}</dd>
                                    <dd class="text-2xs text-brand-cream/45">{{ __('per 1k slots / mo') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Idle apps') }}</dt>
                                    <dd class="mt-1 font-mono text-base font-semibold tabular-nums {{ $metrics['neverUsed'] > 0 ? 'text-brand-gold' : 'text-brand-cream' }}">{{ $metrics['neverUsed'] }}</dd>
                                    <dd class="text-2xs text-brand-cream/45">{{ __('never had a connection') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Unattached') }}</dt>
                                    <dd class="mt-1 font-mono text-base font-semibold tabular-nums {{ $metrics['unboundBillable'] > 0 ? 'text-brand-gold' : 'text-brand-cream' }}">{{ $metrics['unboundBillable'] }}</dd>
                                    <dd class="text-2xs text-brand-cream/45">
                                        {{ $metrics['unboundCents'] > 0
                                            ? __(':amount/mo wasted', ['amount' => $money($metrics['unboundCents'])])
                                            : __('all in use') }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-cream/40">{{ __('Since') }}</dt>
                                    <dd class="mt-1 text-sm font-semibold text-brand-cream">{{ $metrics['oldestAt']?->isoFormat('MMM YYYY') ?? '—' }}</dd>
                                    <dd class="text-2xs text-brand-cream/45">{{ __('first app') }}</dd>
                                </div>
                            </dl>

                            {{-- A saving is only worth surfacing when it is real
                                 money and the downgrade is safe — see the 2x
                                 headroom rule in cheaperTierFor(). --}}
                            @if ($metrics['downgradable'] > 0 && $metrics['downgradableCents'] > 0)
                                <p class="mt-4 inline-flex flex-wrap items-center gap-2 rounded-lg bg-brand-sage/12 px-3 py-2 text-xs text-brand-cream/80 ring-1 ring-inset ring-brand-sage/25">
                                    <x-heroicon-m-arrow-trending-down class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span>
                                        {{ trans_choice(
                                            ':count app has more capacity than it has ever used.|:count apps have more capacity than they have ever used.',
                                            $metrics['downgradable'],
                                            ['count' => $metrics['downgradable']],
                                        ) }}
                                        <span class="font-semibold text-brand-cream">{{ __('Downgrading would save :amount/mo.', ['amount' => $money($metrics['downgradableCents'])]) }}</span>
                                    </span>
                                </p>
                            @endif
                        </div>
                    </div>
                </x-slot:stats>
            @endif

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                </div>
            @endif


            @if ($apps->isEmpty())
                <section class="border-b border-brand-ink/10 px-5 py-16 text-center sm:px-6">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-signal class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h3 class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No broadcasting apps yet') }}</h3>
                    <p class="mx-auto mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Create one here, or add managed broadcasting from a site’s Resources tab. Provisioned apps show up here to manage and bill.') }}
                    </p>
                    @if ($featureActive && $canManage)
                        <button
                            type="button"
                            wire:click="startCreate"
                            class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('New app') }}
                        </button>
                    @endif
                    @unless ($featureActive)
                        <p class="mx-auto mt-3 max-w-md text-xs text-brand-moss">
                            {{ __('Managed realtime isn’t enabled for this workspace yet. Bring-your-own broadcasting is always available on a site.') }}
                        </p>
                    @endunless
                </section>
            @else
                <section class="divide-y divide-brand-ink/10">
                    @foreach ($apps as $app)
                        @php
                            $sites = $siteUsage->get($app->id) ?? collect();
                            $tier = $app->tierConfig();
                            $cap = $app->maxConnections();
                            $peak = (int) ($app->peak_connections ?? 0);
                            $pct = $cap > 0 ? min(100, (int) round($peak / $cap * 100)) : 0;
                            $isActive = $app->status === RealtimeApp::STATUS_ACTIVE;
                            $isStale = $isActive && ($app->last_stats_at === null || $app->last_stats_at->lt(now()->subHours(24)));
                            $rowTone = match (true) {
                                ! $isActive => 'bg-brand-mist',
                                $pct >= 90 => 'bg-brand-rust',
                                $pct >= 70 => 'bg-brand-gold',
                                default => 'bg-brand-sage',
                            };
                        @endphp
                        <article
                            class="group relative px-5 py-5 transition-colors hover:bg-brand-sand/15 sm:px-6"
                            @if ($app->status === RealtimeApp::STATUS_PROVISIONING) wire:poll.5s @endif
                        >
                            {{-- Status rail: the row's health readable at a glance
                                 down the left edge, before any text is parsed. --}}
                            <span class="pointer-events-none absolute inset-y-3 left-0 w-0.5 rounded-full {{ $rowTone }} opacity-60" aria-hidden="true"></span>

                            {{-- Stretched link: the whole card clicks through to the app's detail
                                 page; the interactive controls sit above it via z-10. --}}
                            <a href="{{ route('realtime.show', $app) }}" wire:navigate
                                class="absolute inset-0 z-0 rounded-[inherit]" aria-label="{{ __('View :name', ['name' => $app->name]) }}"></a>

                            <div class="pointer-events-none relative z-10 flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate text-base font-semibold tracking-tight text-brand-ink">{{ $app->name }}</h3>
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$app->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                                            @if ($app->status === RealtimeApp::STATUS_PROVISIONING)
                                                <x-spinner size="sm" />
                                            @endif
                                            {{ ucfirst($app->status) }}
                                        </span>
                                        @if ($isStale)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-gold/12 px-2 py-0.5 text-xs font-medium text-brand-gold ring-1 ring-inset ring-brand-gold/25">
                                                <x-heroicon-m-signal-slash class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('no stats') }}
                                            </span>
                                        @endif
                                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform group-hover:translate-x-0.5" />
                                    </div>

                                    {{-- Connection endpoints, copyable without leaving
                                         the index — the two strings anyone actually
                                         comes here for. The secret stays on the detail
                                         page; nothing here is sensitive. --}}
                                    <div class="pointer-events-auto mt-2 flex flex-wrap items-center gap-1.5">
                                        @foreach ([
                                            ['label' => __('key'), 'value' => $app->app_key],
                                            ['label' => __('host'), 'value' => $app->host()],
                                        ] as $chip)
                                            <span
                                                x-data="{ copied: false, async copyVal() { try { await navigator.clipboard.writeText(@js($chip['value'])); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }"
                                                class="inline-flex max-w-full items-center gap-1.5 rounded-md bg-brand-sand/40 py-0.5 pl-2 pr-1 ring-1 ring-inset ring-brand-ink/10"
                                            >
                                                <span class="text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $chip['label'] }}</span>
                                                <span class="truncate font-mono text-xs text-brand-moss">{{ $chip['value'] }}</span>
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
                                        @endforeach
                                    </div>

                                    @if ($app->status === RealtimeApp::STATUS_FAILED && $app->error_message)
                                        <p class="mt-2 rounded-md bg-red-50 px-2 py-1 text-xs text-red-700 ring-1 ring-inset ring-red-100">{{ $app->error_message }}</p>
                                    @endif
                                </div>

                                {{-- Capacity + price. The meter is the point: the tier
                                     is a hard cap, so "how full" outranks "how much". --}}
                                <div class="w-full shrink-0 sm:w-56">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format($peak) }}</span>
                                        <span class="font-mono text-xs tabular-nums text-brand-mist">/ {{ number_format($cap) }}</span>
                                    </div>
                                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-brand-ink/8">
                                        <div class="dply-meter h-full rounded-full {{ $rowTone }}" style="--meter-w: {{ max(2, $pct) }}%"></div>
                                    </div>
                                    <div class="mt-1.5 flex items-baseline justify-between gap-2 text-xs">
                                        <span class="text-brand-moss">{{ __('peak connections') }}</span>
                                        <span class="font-semibold text-brand-forest">{{ $tier['label'] }} · {{ $money($app->priceCents()) }}/{{ __('mo') }}</span>
                                    </div>
                                </div>
                            </div>

                            {{-- Sites depending on this app. --}}
                            <div class="pointer-events-none relative z-10 mt-4 flex flex-wrap items-center gap-2 border-t border-brand-ink/10 pt-4">
                                @if ($sites->isNotEmpty())
                                    <span class="text-xs text-brand-moss">{{ __('Used by') }}</span>
                                    @foreach ($sites as $binding)
                                        @if ($binding->site)
                                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-2 py-0.5 text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $binding->site->name }}</span>
                                        @endif
                                    @endforeach
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-xs text-brand-mist">
                                        <x-heroicon-o-link-slash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Not attached to any site — still billed while active.') }}
                                    </span>
                                @endif

                                @if ($canManage)
                                    <div class="pointer-events-auto ml-auto flex items-center gap-2">
                                        <x-secondary-button type="button" wire:click="startTierChange('{{ $app->id }}')" class="text-xs">
                                            <x-heroicon-o-arrows-up-down class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Change tier') }}
                                        </x-secondary-button>
                                        <button type="button" wire:click="confirmDelete('{{ $app->id }}')" class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Delete') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </section>

                {{-- Playground and integration guide, both shared with the
                     per-app page so the two surfaces cannot drift. Only offered
                     for an active app — there is nothing to connect to
                     otherwise. --}}
                @if ($demoApp && $demoApp->isActive())
                    <x-realtime-playground
                        :app="$demoApp"
                        :apps="$apps->where('status', \App\Modules\Realtime\Models\RealtimeApp::STATUS_ACTIVE)"
                        :can-manage="$canManage"
                        :channel="$demoChannel"
                    />

                    @if ($canManage)
                        <x-realtime-integration-guide :app="$demoApp" />
                    @endif
                @endif
            @endif
        </x-profile-shell>
    </div>

    {{-- Tier-change modal --}}
    <x-modal name="realtime-tier-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        @if ($managingApp)
            @php
                $currentCents = $managingApp->priceCents();
                $selectedCents = (int) ($tiers[$selectedTier]['price_cents'] ?? $currentCents);
                $isUpgrade = $selectedCents > $currentCents;
            @endphp
            <form wire:submit="changeTier">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Change connection tier') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $managingApp->name }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Currently :tier · :price/mo. Changing tiers adjusts the hard connection cap and this workspace’s bill.', ['tier' => $managingApp->tierConfig()['label'], 'price' => $money($currentCents)]) }}</p>
                    </div>
                </div>

                <div class="space-y-4 px-6 py-6">
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ($tiers as $slug => $tier)
                            <button type="button" wire:click="$set('selectedTier', '{{ $slug }}')" class="rounded-lg border p-3 text-left transition-colors {{ $selectedTier === $slug ? 'border-brand-forest bg-brand-forest/5 ring-1 ring-brand-forest/40' : 'border-brand-ink/10 hover:bg-brand-sand/30' }}">
                                <div class="text-sm font-semibold text-brand-ink">{{ $tier['label'] }}</div>
                                <div class="mt-0.5 text-xs text-brand-moss">{{ number_format($tier['max_connections']) }} {{ __('connections') }}</div>
                                <div class="mt-1 text-xs font-semibold text-brand-forest">{{ $money((int) $tier['price_cents']) }}/{{ __('mo') }}</div>
                            </button>
                        @endforeach
                    </div>

                    @if ($isUpgrade)
                        <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                            <input type="checkbox" wire:model="confirmTierCharge" class="mt-0.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span>{{ __('I understand this raises this app’s charge from :from to :to per month on this workspace’s bill.', ['from' => $money($currentCents), 'to' => $money($selectedCents)]) }}</span>
                        </label>
                    @elseif ($selectedCents < $currentCents)
                        <p class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">{{ __('Downgrading lowers this app’s charge to :to/mo. The lower connection cap takes effect immediately.', ['to' => $money($selectedCents)]) }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelTierChange">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="changeTier">
                        <span wire:loading.remove wire:target="changeTier">{{ __('Save tier') }}</span>
                        <span wire:loading wire:target="changeTier" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Saving…') }}</span>
                    </x-primary-button>
                </div>
            </form>
        @endif
    </x-modal>

    {{-- Delete confirmation modal --}}
    <x-modal name="realtime-delete-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" focusable>
        @if ($deletingApp)
            <div>
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 ring-1 ring-red-200">
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Delete broadcasting app') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $deletingApp->name }}</h2>
                    </div>
                </div>
                <div class="space-y-3 px-6 py-6 text-sm leading-6 text-brand-moss">
                    <p>{{ __('This tears the app down on the relay and stops its :price/mo charge on this workspace’s bill. Connections are revoked immediately.', ['price' => $money($deletingApp->priceCents())]) }}</p>
                    @if ($deletingAppSites->isNotEmpty())
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                            {{ __('Warning: :count site(s) still broadcast through this app and will lose realtime until you point them elsewhere:', ['count' => $deletingAppSites->count()]) }}
                            <span class="font-medium">{{ $deletingAppSites->map(fn ($b) => $b->site?->name)->filter()->join(', ') }}</span>
                        </div>
                    @endif
                </div>
                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelDelete">{{ __('Cancel') }}</x-secondary-button>
                    <x-danger-button type="button" wire:click="deleteApp" wire:loading.attr="disabled" wire:target="deleteApp">
                        <span wire:loading.remove wire:target="deleteApp">{{ __('Delete app') }}</span>
                        <span wire:loading wire:target="deleteApp" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Deleting…') }}</span>
                    </x-danger-button>
                </div>
            </div>
        @endif
    </x-modal>

    {{-- Create-app modal: name + tier + billing consent. --}}
    <x-modal name="realtime-create-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        @php
            $createCents = (int) ($tiers[$createTier]['price_cents'] ?? 0);
        @endphp
        <form wire:submit="createApp">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New broadcasting app') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Provision a managed relay') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Spins up a Pusher-compatible relay app you can attach to any site. Billed monthly by its connection tier and added to this workspace’s subscription.') }}</p>
                </div>
            </div>

            <div class="space-y-5 px-6 py-6">
                <div>
                    <label for="create-name" class="block text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('App name') }}</label>
                    <input id="create-name" type="text" wire:model="createName" placeholder="{{ __('e.g. Production realtime') }}"
                        class="mt-1.5 block w-full rounded-lg border-brand-ink/15 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                </div>

                <div>
                    <p class="block text-xs font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Connection tier') }}</p>
                    <div class="mt-1.5 grid gap-2 sm:grid-cols-3">
                        @foreach ($tiers as $slug => $tierOption)
                            <button type="button" wire:click="$set('createTier', '{{ $slug }}')" class="rounded-lg border p-3 text-left transition-colors {{ $createTier === $slug ? 'border-brand-forest bg-brand-forest/5 ring-1 ring-brand-forest/40' : 'border-brand-ink/10 hover:bg-brand-sand/30' }}">
                                <div class="text-sm font-semibold text-brand-ink">{{ $tierOption['label'] }}</div>
                                <div class="mt-0.5 text-xs text-brand-moss">{{ number_format($tierOption['max_connections']) }} {{ __('connections') }}</div>
                                <div class="mt-1 text-xs font-semibold text-brand-forest">{{ $money((int) $tierOption['price_cents']) }}/{{ __('mo') }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>

                <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                    <input type="checkbox" wire:model="confirmCreateCharge" class="mt-0.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                    <span>{{ __('I understand this adds :price/mo to this workspace’s bill while the app is active.', ['price' => $money($createCents)]) }}</span>
                </label>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelCreate">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createApp">
                    <span wire:loading.remove wire:target="createApp">{{ __('Create app') }}</span>
                    <span wire:loading wire:target="createApp" class="inline-flex items-center gap-2"><x-spinner variant="cream" size="sm" />{{ __('Creating…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
