<div class="contents">
    @if ($qdId)
        <div wire:poll.1500ms="pollQuickDownload" class="hidden"></div>
    @endif

    <x-workspace-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Backups'), 'icon' => 'archive-box'],
        ]" />

        @if (! $featureActive)
            <x-profile-shell
                dense
                :title="__('Backups')"
                :description="__('Schedules, recent runs, and storage destinations.')"
                icon="heroicon-o-archive-box"
            >
                <x-slot:tabs>
                    <x-backups-subnav active="overview" />
                </x-slot:tabs>
                <div class="px-3 py-3 sm:px-4">
                    <x-backups-preview-panel compact />
                </div>
            </x-profile-shell>
        @else
            @php
                $coverage = $metrics['coverage'];
                $lastSuccessAt = $metrics['lastSuccessAt'];
                $unprotectedCount = $metrics['unprotectedServers'];

                // The console reads as a verdict, not a number: full coverage is
                // calm, partial coverage is a warning, nothing covered is a
                // problem. Tones are text colours because the dial strokes them.
                $coverageTone = match (true) {
                    $metrics['servers'] === 0 => 'text-brand-mist',
                    $unprotectedCount === 0 => 'text-brand-sage',
                    $metrics['protectedServers'] === 0 => 'text-brand-rust',
                    default => 'text-brand-gold',
                };

                // Nothing scheduled and nothing ever run means this org has not
                // started — show the onboarding splash instead of empty tables.
                $neverUsed = $metrics['activeSchedules'] === 0 && empty($recentRuns);

                // r=52 on a 120 viewBox. Kept as a constant so the dash offset
                // maths below stays readable.
                $dialCircumference = 326.726;
                $dialOffset = $dialCircumference * (1 - min(100, max(0, $coverage)) / 100);

                $activityMax = max(1, collect($activity)->max(fn ($day) => $day['completed'] + $day['failed']));
                $activityTotal = collect($activity)->sum(fn ($day) => $day['completed'] + $day['failed']);
                $activityFailed = collect($activity)->sum(fn ($day) => $day['failed']);
            @endphp

            {{-- Scoped motion for the console. The dial draws itself and the
                 activity bars rise on first paint — enough to make the posture
                 band feel alive without animating anything the user reads. --}}
            @verbatim
                <style>
                    @keyframes dply-bar-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
                    /* --dial-full is the full circumference, set inline on the arc. */
                    @keyframes dply-dial-draw { from { stroke-dashoffset: var(--dial-full); } }
                    .dply-bar { transform-origin: bottom; animation: dply-bar-rise .55s cubic-bezier(.16,1,.3,1) both; }
                    .dply-dial { animation: dply-dial-draw 1s cubic-bezier(.16,1,.3,1) both; }
                    @media (prefers-reduced-motion: reduce) {
                        .dply-bar, .dply-dial { animation: none; }
                    }
                </style>
            @endverbatim

            <x-profile-shell
                dense
                :title="__('Backups')"
                :description="__('Database dumps and site archives across :org — scheduled, on demand, and shipped to storage you own.', ['org' => $organization->name])"
                icon="heroicon-o-archive-box"
            >
                <x-slot:actions>
                    <a
                        href="{{ route('backups.storage') }}"
                        wire:navigate
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-archive-box-arrow-down class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Destinations') }}
                    </a>
                </x-slot:actions>

                <x-slot:stats>
                    {{-- The protection console. Inverted against the rest of the
                         card so the posture verdict is the first thing the eye
                         lands on: coverage dial, two weeks of run history, and
                         the supporting numbers all on one dark band. --}}
                    <div class="relative overflow-hidden bg-brand-ink text-brand-cream">
                        <div class="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full bg-brand-sage/25 blur-3xl" aria-hidden="true"></div>
                        <div class="pointer-events-none absolute -left-24 -bottom-24 h-64 w-64 rounded-full bg-brand-gold/15 blur-3xl" aria-hidden="true"></div>

                        {{-- Three zones: the coverage verdict, the roster it is a
                             ratio of, and two weeks of history. The chart column is
                             capped so 14 bars stay a chart instead of stretching
                             into slabs across the full card width. --}}
                        <div class="relative grid gap-6 px-4 py-5 sm:px-6 sm:py-6 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,26rem)] lg:items-center lg:gap-8">
                            {{-- Coverage dial + verdict --}}
                            <div class="flex items-center gap-4 sm:gap-5">
                                <div class="relative shrink-0">
                                    <svg viewBox="0 0 120 120" class="h-24 w-24 -rotate-90 sm:h-28 sm:w-28" aria-hidden="true">
                                        <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" class="text-brand-cream/12" />
                                        <circle
                                            cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                                            class="dply-dial {{ $coverageTone }}"
                                            stroke-dasharray="{{ $dialCircumference }}"
                                            stroke-dashoffset="{{ $dialOffset }}"
                                            style="--dial-full: {{ $dialCircumference }}"
                                        />
                                    </svg>
                                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                                        <span class="font-mono text-2xl font-semibold tabular-nums leading-none text-brand-cream sm:text-[28px]">{{ $coverage }}<span class="text-base text-brand-cream/50">%</span></span>
                                        <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('covered') }}</span>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Protection coverage') }}</p>
                                    <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                        @if ($metrics['servers'] === 0)
                                            {{ __('No servers in this workspace yet.') }}
                                        @elseif ($unprotectedCount === 0)
                                            {{ __('Every server is covered.') }}
                                        @else
                                            {{ trans_choice(':count server is unprotected|:count servers are unprotected', $unprotectedCount, ['count' => $unprotectedCount]) }}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-brand-cream/55">
                                        {{ __(':protected of :total servers on an active schedule', [
                                            'protected' => $metrics['protectedServers'],
                                            'total' => $metrics['servers'],
                                        ]) }}
                                    </p>
                                    @if ($unprotectedCount > 0 && $gaps !== [])
                                        <a
                                            href="#backup-gaps"
                                            class="group mt-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-cream/10 px-2.5 py-1.5 text-xs font-semibold text-brand-cream ring-1 ring-brand-cream/15 transition-colors hover:bg-brand-cream/20"
                                        >
                                            <x-heroicon-m-shield-exclamation class="h-3.5 w-3.5 text-brand-gold" aria-hidden="true" />
                                            {{ __('Close the gaps') }}
                                            <x-heroicon-m-arrow-down class="h-3 w-3 transition-transform group-hover:translate-y-0.5" aria-hidden="true" />
                                        </a>
                                    @endif
                                </div>
                            </div>

                            {{-- The roster the ratio is made of. Uncovered first,
                                 so the eye lands on what needs attention. --}}
                            @if ($roster->isNotEmpty())
                                <div class="min-w-0 lg:border-l lg:border-brand-cream/10 lg:pl-8">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Servers') }}</p>
                                    <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                        @foreach ($roster->take(9) as $entry)
                                            <li wire:key="roster-{{ $entry['server']->id }}">
                                                <a
                                                    href="{{ route('servers.backups', $entry['server']) }}"
                                                    wire:navigate
                                                    @class([
                                                        'inline-flex max-w-[13rem] items-center gap-1.5 rounded-full px-2 py-1 text-xs ring-1 transition-colors',
                                                        'bg-brand-cream/[0.07] text-brand-cream/85 ring-brand-cream/12 hover:bg-brand-cream/15' => $entry['protected'],
                                                        'bg-brand-rust/15 text-brand-cream ring-brand-rust/40 hover:bg-brand-rust/25' => ! $entry['protected'],
                                                    ])
                                                    title="{{ $entry['protected'] ? __('Covered by an active schedule') : __('No active schedule') }}"
                                                >
                                                    <span @class([
                                                        'h-1.5 w-1.5 shrink-0 rounded-full',
                                                        'bg-brand-sage' => $entry['protected'],
                                                        'bg-brand-rust' => ! $entry['protected'],
                                                    ]) aria-hidden="true"></span>
                                                    <span class="truncate">{{ $entry['name'] }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                        @if ($roster->count() > 9)
                                            <li class="inline-flex items-center px-1.5 py-1 text-xs text-brand-cream/45">
                                                {{ __('+:count more', ['count' => $roster->count() - 9]) }}
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            @endif

                            {{-- Two weeks of run history, as an actual shape --}}
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Run activity · 14 days') }}</p>
                                    <p class="flex items-center gap-3 text-2xs text-brand-cream/50">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-2 w-2 rounded-[2px] bg-brand-sage" aria-hidden="true"></span>{{ __('completed') }}
                                        </span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-2 w-2 rounded-[2px] bg-brand-rust" aria-hidden="true"></span>{{ __('failed') }}
                                        </span>
                                    </p>
                                </div>

                                <div class="mt-3 flex items-end gap-[3px] sm:gap-1">
                                    @foreach ($activity as $day)
                                        @php
                                            $dayTotal = $day['completed'] + $day['failed'];
                                            // Floor at 14% so a single run is still a visible
                                            // bar rather than a hairline next to a busy day.
                                            $barPercent = $dayTotal > 0
                                                ? max(14, (int) round($dayTotal / $activityMax * 100))
                                                : 0;
                                            $failedPercent = $dayTotal > 0
                                                ? (int) round($day['failed'] / $dayTotal * 100)
                                                : 0;
                                            $dayLabel = $day['date']->format('D M j').' — '.
                                                trans_choice(':count run|:count runs', $dayTotal, ['count' => $dayTotal]).
                                                ($day['failed'] > 0 ? ', '.__(':count failed', ['count' => $day['failed']]) : '');
                                        @endphp
                                        <div
                                            class="group flex min-w-0 flex-1 flex-col items-center gap-1.5"
                                            title="{{ $dayLabel }}"
                                        >
                                            <div class="flex h-16 w-full items-end sm:h-20">
                                                @if ($dayTotal > 0)
                                                    <div
                                                        class="dply-bar flex w-full flex-col justify-end overflow-hidden rounded-[4px] shadow-sm shadow-black/20 transition-opacity group-hover:opacity-80"
                                                        style="height: {{ $barPercent }}%; animation-delay: {{ $loop->index * 35 }}ms"
                                                    >
                                                        @if ($day['failed'] > 0)
                                                            <div class="w-full bg-gradient-to-t from-brand-rust to-brand-copper" style="height: {{ $failedPercent }}%"></div>
                                                        @endif
                                                        <div class="w-full flex-1 bg-gradient-to-t from-brand-forest via-brand-sage to-brand-sage"></div>
                                                    </div>
                                                @else
                                                    <div class="h-[3px] w-full rounded-full bg-brand-cream/15" aria-hidden="true"></div>
                                                @endif
                                            </div>
                                            <span class="text-[9px] font-medium uppercase text-brand-cream/35">{{ substr($day['date']->format('D'), 0, 1) }}</span>
                                        </div>
                                    @endforeach
                                </div>

                                <p class="mt-2.5 text-xs text-brand-cream/55">
                                    @if ($activityTotal === 0)
                                        {{ __('No backup runs in the last 14 days.') }}
                                    @else
                                        <span class="font-mono font-semibold tabular-nums text-brand-cream">{{ number_format($activityTotal) }}</span>
                                        {{ trans_choice('run|runs', $activityTotal) }}
                                        {{ __('across databases and site files') }}@if ($activityFailed > 0)<span class="text-brand-rust">, {{ __(':count failed', ['count' => $activityFailed]) }}</span>@endif.
                                    @endif
                                </p>
                            </div>
                        </div>

                        @php
                            $metricCards = [
                                [
                                    'label' => __('Last successful'),
                                    'value' => $lastSuccessAt ? $lastSuccessAt->diffForHumans(short: true) : __('Never'),
                                    'icon' => 'heroicon-m-check-circle',
                                    'tone' => $lastSuccessAt ? 'text-brand-sage' : 'text-brand-mist',
                                    'hint' => $lastSuccessAt?->format('Y-m-d H:i'),
                                ],
                                [
                                    'label' => __('Completed (7d)'),
                                    'value' => number_format($metrics['completed7d']),
                                    'icon' => 'heroicon-m-arrow-path',
                                    'tone' => 'text-brand-sage',
                                    'hint' => null,
                                ],
                                [
                                    'label' => __('Failed (7d)'),
                                    'value' => number_format($metrics['failed7d']),
                                    'icon' => 'heroicon-m-exclamation-circle',
                                    'tone' => $metrics['failed7d'] > 0 ? 'text-brand-rust' : 'text-brand-cream/40',
                                    'hint' => null,
                                ],
                                [
                                    'label' => __('Storage used'),
                                    'value' => $metrics['storage'],
                                    'icon' => 'heroicon-m-cloud-arrow-up',
                                    'tone' => 'text-brand-gold',
                                    'hint' => null,
                                ],
                            ];
                        @endphp
                        <dl class="relative grid grid-cols-2 gap-px border-t border-brand-cream/10 bg-brand-cream/10 sm:grid-cols-4">
                            @foreach ($metricCards as $card)
                                <div class="bg-brand-ink px-4 py-3 transition-colors hover:bg-brand-cream/[0.04] sm:px-5">
                                    <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-[0.12em] text-brand-cream/45">
                                        <x-dynamic-component :component="$card['icon']" class="h-3.5 w-3.5 shrink-0 {{ $card['tone'] }}" aria-hidden="true" />
                                        <span class="truncate">{{ $card['label'] }}</span>
                                    </dt>
                                    <dd class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-cream" @if ($card['hint']) title="{{ $card['hint'] }}" @endif>
                                        {{ $card['value'] }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </x-slot:stats>

                <x-slot:tabs>
                    <x-backups-subnav active="overview" />
                </x-slot:tabs>

                {{-- Gaps: not "who has nothing" but "protected against what".
                     Capability-aware, so a Custom box is never nagged about an
                     image its provider cannot take. --}}
                @if ($gaps !== [])
                    <section id="backup-gaps" class="scroll-mt-24 border-b border-brand-ink/10 bg-brand-gold/[0.06]">
                        <x-workspace-panel-head
                            dense
                            tone="amber"
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-shield-exclamation"
                            :title="__('Gaps')"
                            :count="count($gaps)"
                            :note="__('What each server is missing — open one to close the gap.')"
                        />
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($gaps as $gap)
                                <li
                                    wire:key="gap-{{ $gap['server']->id }}"
                                    @class([
                                        'group flex flex-wrap items-center gap-x-3 gap-y-1 border-l-[3px] px-3 py-2.5 transition-colors hover:bg-white sm:px-4',
                                        'border-brand-rust' => ! $gap['protected'],
                                        'border-brand-gold' => $gap['protected'],
                                    ])
                                >
                                    <a
                                        href="{{ route('servers.backups', $gap['server']) }}"
                                        wire:navigate
                                        class="min-w-0 shrink-0 text-sm font-semibold text-brand-ink hover:text-brand-forest"
                                    >
                                        {{ $gap['server']->name }}
                                    </a>
                                    @if (! $gap['protected'])
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-brand-rust px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-cream">
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3" aria-hidden="true" />
                                            {{ __('no protection') }}
                                        </span>
                                    @endif
                                    <span class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
                                        @foreach ($gap['missing'] as $missing)
                                            <span class="inline-flex shrink-0 items-center rounded-md bg-brand-ink/[0.06] px-1.5 py-0.5 text-2xs font-medium text-brand-moss ring-1 ring-inset ring-brand-ink/8">
                                                {{ $missing }}
                                            </span>
                                        @endforeach
                                        @if ($gap['note'])
                                            <span class="text-xs text-brand-mist">· {{ $gap['note'] }}</span>
                                        @endif
                                    </span>
                                    <a
                                        href="{{ route('servers.backups', $gap['server']) }}"
                                        wire:navigate
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md bg-brand-ink px-2 py-1 text-xs font-semibold text-brand-cream opacity-70 transition-all hover:bg-brand-forest group-hover:opacity-100"
                                    >
                                        {{ __('Fix') }}
                                        <x-heroicon-m-arrow-right class="h-3 w-3 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($neverUsed)
                    {{-- Onboarding splash: nothing scheduled, nothing ever run. --}}
                    <section class="relative overflow-hidden border-b border-brand-ink/10 bg-gradient-to-b from-brand-sand/25 via-white to-white px-4 py-10 sm:px-6 sm:py-12">
                        <div class="pointer-events-none absolute -top-24 left-1/2 h-56 w-[28rem] -translate-x-1/2 rounded-full bg-brand-sage/15 blur-3xl" aria-hidden="true"></div>
                        <div class="relative mx-auto max-w-2xl text-center">
                            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-ink text-brand-gold shadow-lg shadow-brand-forest/20">
                                <x-heroicon-o-archive-box class="h-7 w-7" aria-hidden="true" />
                            </span>
                            <h3 class="mt-5 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Nothing is being backed up yet') }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                {{ __('dply dumps your databases on a schedule you choose, ships them to storage you own, and keeps a downloadable copy one click away. Set the first one up from any server.') }}
                            </p>
                            <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                                <a
                                    href="{{ route('servers.index') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md shadow-brand-forest/20 transition-all hover:-translate-y-0.5 hover:bg-brand-forest"
                                >
                                    <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Protect a server') }}
                                </a>
                                <a
                                    href="{{ route('backups.storage') }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-cloud-arrow-up class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Add a destination') }}
                                </a>
                            </div>
                        </div>

                        @php
                            $capabilities = [
                                [
                                    'icon' => 'heroicon-o-clock',
                                    'title' => __('On a schedule'),
                                    'body' => __('Pick a cadence per database or site. Runs land in the history below with size and status.'),
                                ],
                                [
                                    'icon' => 'heroicon-o-cloud-arrow-up',
                                    'title' => __('Into your own storage'),
                                    'body' => __('Point schedules at any S3-compatible bucket. Your keys, your bucket, your retention.'),
                                ],
                                [
                                    'icon' => 'heroicon-o-arrow-down-tray',
                                    'title' => __('Or right now'),
                                    'body' => __('Stream a fresh dump straight to your browser — no schedule and no bucket required.'),
                                ],
                            ];
                        @endphp
                        <ul class="relative mx-auto mt-9 grid max-w-4xl gap-3 sm:grid-cols-3">
                            @foreach ($capabilities as $capability)
                                <li class="rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                        <x-dynamic-component :component="$capability['icon']" class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <p class="mt-3 text-sm font-semibold text-brand-ink">{{ $capability['title'] }}</p>
                                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $capability['body'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @else

                    {{-- Recent runs, grouped by day and hung off a timeline spine so
                         25 near-identical rows read as history instead of a wall. --}}
                    @php
                        $runsByDay = collect($recentRuns)->groupBy(fn ($run) => $run['at']->toDateString());
                    @endphp
                    <section class="border-b border-brand-ink/10">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-circle-stack"
                            :title="__('Recent runs')"
                            :note="__('Last 25 database dumps and file archives across all servers.')"
                        />

                        @if (empty($recentRuns))
                            <div class="px-3 py-6 text-center sm:px-4">
                                <x-heroicon-o-circle-stack class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                                <p class="mt-2 text-sm text-brand-moss">{{ __('No backup runs yet.') }}</p>
                            </div>
                        @else
                            @foreach ($runsByDay as $day => $runs)
                                @php
                                    $dayDate = $runs->first()['at'];
                                    $dayBytes = $runs->sum(fn ($run) => (int) $run['bytes']);
                                    $dayFailed = $runs->where('status', 'failed')->count();
                                @endphp
                                <div wire:key="day-{{ $day }}">
                                    <div class="flex items-center gap-3 bg-brand-sand/20 px-3 py-1.5 sm:px-4">
                                        <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                                            @if ($dayDate->isToday())
                                                {{ __('Today') }}
                                            @elseif ($dayDate->isYesterday())
                                                {{ __('Yesterday') }}
                                            @else
                                                {{ $dayDate->format('D, M j') }}
                                            @endif
                                        </span>
                                        <span class="h-px flex-1 bg-brand-ink/10"></span>
                                        @if ($dayFailed > 0)
                                            <span class="text-2xs font-semibold uppercase tracking-wide text-brand-rust">{{ __(':count failed', ['count' => $dayFailed]) }}</span>
                                        @endif
                                        <span class="font-mono text-2xs tabular-nums text-brand-mist">
                                            {{ $runs->count() }} · {{ \Illuminate\Support\Number::fileSize($dayBytes) }}
                                        </span>
                                    </div>

                                    {{-- No row rules inside a day: the tight column
                                         of status nodes carries the rhythm, and the
                                         day headers do the separating. --}}
                                    <ul class="py-1">
                                        @foreach ($runs as $run)
                                            @php
                                                $runTone = match ($run['status']) {
                                                    'completed' => ['bg-brand-sage text-brand-cream', 'heroicon-m-check', __('Done')],
                                                    'failed' => ['bg-brand-rust text-brand-cream', 'heroicon-m-x-mark', __('Failed')],
                                                    default => ['bg-brand-gold text-brand-ink', 'heroicon-m-ellipsis-horizontal', __('Pending')],
                                                };
                                            @endphp
                                            <li wire:key="run-{{ $run['key'] }}" class="relative flex items-center gap-3 px-3 py-1.5 transition-colors hover:bg-brand-sand/20 sm:px-4">
                                                <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-[3px] ring-brand-cream {{ $runTone[0] }}">
                                                    <x-dynamic-component :component="$runTone[1]" class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="flex items-center gap-1.5 truncate text-sm font-medium text-brand-ink">
                                                        <span @class([
                                                            'inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-2xs font-bold uppercase tracking-wide',
                                                            'bg-brand-forest/12 text-brand-forest' => $run['kind'] === 'database',
                                                            'bg-brand-copper/15 text-brand-copper' => $run['kind'] !== 'database',
                                                        ])>{{ $run['kind'] === 'database' ? __('DB') : __('Files') }}</span>
                                                        <span class="truncate">
                                                            <span class="font-semibold">{{ $run['name'] }}</span>
                                                            <span class="text-brand-mist">{{ __('on') }}</span>
                                                            {{ $run['context'] }}
                                                        </span>
                                                    </p>
                                                    <p class="mt-0.5 truncate text-xs text-brand-mist">
                                                        <span @class([
                                                            'font-medium',
                                                            'text-brand-rust' => $run['status'] === 'failed',
                                                            'text-brand-moss' => $run['status'] !== 'failed',
                                                        ])>{{ $runTone[2] }}</span>
                                                        · <span class="font-mono tabular-nums">{{ $run['bytes'] ? \Illuminate\Support\Number::fileSize((int) $run['bytes']) : __('no artifact') }}</span>
                                                        · {{ $run['destination'] }}
                                                    </p>
                                                </div>
                                                <time
                                                    class="shrink-0 font-mono text-xs tabular-nums text-brand-moss"
                                                    datetime="{{ $run['at']->toIso8601String() }}"
                                                    title="{{ $run['at']->format('Y-m-d H:i:s') }}"
                                                >
                                                    {{ $run['at']->diffForHumans(short: true) }}
                                                </time>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        @endif
                    </section>
                @endif

                {{-- Type summaries. The full tables live on their own tabs. --}}
                <div class="grid gap-px bg-brand-ink/10 lg:grid-cols-2">
                    <a
                        href="{{ route('backups.databases') }}"
                        wire:navigate
                        class="group relative block bg-white p-4 transition-colors hover:bg-brand-sand/15 sm:p-5"
                    >
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-forest/12 text-brand-forest ring-1 ring-brand-forest/15 transition-transform group-hover:scale-105">
                                <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                                    {{ __('Databases') }}
                                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 text-brand-mist transition-transform group-hover:translate-x-0.5 group-hover:text-brand-forest" aria-hidden="true" />
                                </p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Dumps, schedules and instant downloads.') }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-baseline gap-x-6 gap-y-2">
                            <span class="flex items-baseline gap-1.5">
                                <span class="font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($metrics['activeSchedules']) }}</span>
                                <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ trans_choice('active schedule|active schedules', $metrics['activeSchedules']) }}</span>
                            </span>
                            <span class="flex items-baseline gap-1.5">
                                <span class="font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $metrics['storage'] }}</span>
                                <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('stored') }}</span>
                            </span>
                        </div>
                        <p class="mt-3 text-xs leading-relaxed text-brand-mist">
                            {{ __('A dump is the artifact that restores correct data — pair one with every server image.') }}
                        </p>
                    </a>

                    <a
                        href="{{ route('backups.files') }}"
                        wire:navigate
                        class="group relative block bg-white p-4 transition-colors hover:bg-brand-sand/15 sm:p-5"
                    >
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-copper/12 text-brand-copper ring-1 ring-brand-copper/20 transition-transform group-hover:scale-105">
                                <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                                    {{ __('Site files') }}
                                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 text-brand-mist transition-transform group-hover:translate-x-0.5 group-hover:text-brand-copper" aria-hidden="true" />
                                </p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Full-site archives, queued per site.') }}</p>
                            </div>
                        </div>

                        @if ($files['recent']->isEmpty())
                            <div class="mt-4 flex flex-wrap items-baseline gap-x-6 gap-y-2">
                                <span class="flex items-baseline gap-1.5">
                                    <span class="font-mono text-2xl font-semibold tabular-nums text-brand-mist">0</span>
                                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('archives') }}</span>
                                </span>
                                <span class="flex items-baseline gap-1.5">
                                    <span class="font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($files['sites']) }}</span>
                                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ trans_choice('site ready|sites ready', $files['sites']) }}</span>
                                </span>
                            </div>
                            <p class="mt-3 text-xs leading-relaxed text-brand-mist">
                                {{ __('Nothing archived yet — an archive captures the whole site directory, not just the database.') }}
                            </p>
                        @else
                            @php
                                $filesCoverage = $files['sites'] > 0
                                    ? (int) round($files['archivedSites'] / $files['sites'] * 100)
                                    : 0;
                            @endphp
                            <div class="mt-4 flex items-baseline gap-1.5">
                                <span class="font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $files['archivedSites'] }}<span class="text-brand-mist">/{{ $files['sites'] }}</span></span>
                                <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('sites archived') }}</span>
                            </div>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-brand-sand/70" role="img" aria-label="{{ __(':percent% of sites archived', ['percent' => $filesCoverage]) }}">
                                <div class="h-full rounded-full bg-brand-copper" style="width: {{ max($filesCoverage, 2) }}%"></div>
                            </div>
                            <ul class="mt-3 space-y-1.5">
                                @foreach ($files['recent'] as $archive)
                                    <li wire:key="archive-{{ $archive['site']?->id ?? $loop->index }}" class="flex items-center gap-2 text-xs">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-copper" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate font-medium text-brand-ink">{{ $archive['site']?->name ?? '—' }}</span>
                                        <span class="shrink-0 truncate text-brand-mist">{{ $archive['site']?->server?->name ?? '—' }}</span>
                                        @if ($archive['bytes'])
                                            <span class="shrink-0 font-mono tabular-nums text-brand-mist">{{ \Illuminate\Support\Number::fileSize((int) $archive['bytes']) }}</span>
                                        @endif
                                        <time
                                            class="shrink-0 font-mono tabular-nums text-brand-moss"
                                            datetime="{{ $archive['at']->toIso8601String() }}"
                                            title="{{ $archive['at']->format('Y-m-d H:i:s') }}"
                                        >
                                            {{ $archive['at']->diffForHumans(short: true) }}
                                        </time>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </a>

                    <section class="bg-white lg:col-span-2">
                        <x-workspace-panel-head
                            dense
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-cloud-arrow-up"
                            :title="__('Storage destinations')"
                            :count="$destinations->isNotEmpty() ? $destinations->count() : null"
                            :note="__('Where scheduled dumps land.')"
                        >
                            <x-slot:actions>
                                <a
                                    href="{{ route('backups.storage') }}"
                                    wire:navigate
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Add') }}
                                </a>
                            </x-slot:actions>
                        </x-workspace-panel-head>

                        @if ($destinations->isEmpty())
                            <div class="px-4 py-8 text-center sm:px-6">
                                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sand/50 text-brand-moss ring-1 ring-brand-ink/8">
                                    <x-heroicon-o-cloud-arrow-up class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No storage destinations configured yet.') }}</p>
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Without one, scheduled dumps stay on the server.') }}</p>
                                <a
                                    href="{{ route('backups.storage') }}"
                                    wire:navigate
                                    class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                                >
                                    {{ __('Add your first destination') }}
                                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                                </a>
                            </div>
                        @else
                            <ul class="grid gap-3 p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-3">
                                @foreach ($destinations as $destination)
                                    <li wire:key="dest-{{ $destination->id }}">
                                        <a
                                            href="{{ route('backups.storage') }}"
                                            wire:navigate
                                            class="group flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white p-3 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand-ink/20 hover:shadow-md"
                                        >
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                                <x-heroicon-o-archive-box class="h-4 w-4" aria-hidden="true" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-semibold text-brand-ink">{{ $destination->name }}</p>
                                                <p class="truncate text-xs text-brand-mist">
                                                    {{ \App\Models\BackupConfiguration::labelForProvider($destination->provider) }}
                                                </p>
                                            </div>
                                            <x-heroicon-m-arrow-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform group-hover:translate-x-0.5 group-hover:text-brand-ink" aria-hidden="true" />
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </x-profile-shell>
        @endif
    </div>
</div>
