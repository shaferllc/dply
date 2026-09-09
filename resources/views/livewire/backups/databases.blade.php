<div class="contents">
    @if ($qdId)
        <div wire:poll.1500ms="pollQuickDownload" class="hidden"></div>
    @endif

    <x-workspace-nav />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Backups'), 'href' => route('backups.overview'), 'icon' => 'archive-box'],
            ['label' => __('Databases'), 'icon' => 'circle-stack'],
        ]" />

        @if (! $featureActive)
            <x-profile-shell
                dense
                :title="__('Databases')"
                :description="__('Scheduled SQL dumps and on-demand downloads.')"
                icon="heroicon-o-circle-stack"
            >
                <x-slot:tabs>
                    <x-backups-subnav active="databases" />
                </x-slot:tabs>
                <div class="px-3 py-3 sm:px-4">
                    <x-backups-preview-panel compact />
                </div>
            </x-profile-shell>
        @else
            @php
                $coverage = $metrics['coverage'];
                $unscheduled = $metrics['databases'] - $metrics['protected'];

                $coverageTone = match (true) {
                    $metrics['databases'] === 0 => 'text-brand-mist',
                    $unscheduled === 0 => 'text-brand-sage',
                    $metrics['protected'] === 0 => 'text-brand-rust',
                    default => 'text-brand-gold',
                };

                // r=52 on a 120 viewBox, same dial as the Backups overview.
                $dialCircumference = 326.726;
                $dialOffset = $dialCircumference * (1 - min(100, max(0, $coverage)) / 100);

                $activityMax = max(1, collect($activity)->max(fn ($day) => $day['completed'] + $day['failed']));
                $activityTotal = collect($activity)->sum(fn ($day) => $day['completed'] + $day['failed']);
                $activityFailed = collect($activity)->sum(fn ($day) => $day['failed']);

                // Engine identity: a two-letter tile and a tone, so a Postgres row
                // is distinguishable from a MySQL row at a glance.
                $engineBadge = static fn (?string $engine): array => match (true) {
                    $engine === null => ['DB', 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10'],
                    str_contains($engine, 'postg') => ['PG', 'bg-brand-forest/12 text-brand-forest ring-brand-forest/20'],
                    str_contains($engine, 'maria') => ['MA', 'bg-brand-gold/25 text-amber-800 ring-brand-gold/40'],
                    str_contains($engine, 'mysql') => ['MY', 'bg-brand-copper/12 text-brand-copper ring-brand-copper/25'],
                    str_contains($engine, 'sqlite') => ['SQ', 'bg-brand-moss/12 text-brand-moss ring-brand-moss/20'],
                    default => ['DB', 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10'],
                };
            @endphp

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
                :title="__('Databases')"
                :description="__('SQL dumps — the artifact that restores correct data, on a schedule or right now.')"
                icon="heroicon-o-circle-stack"
            >
                <x-slot:stats>
                    {{-- Same inverted console as the Backups overview, scoped to
                         one type: how much of it is scheduled, what engines are in
                         play, and two weeks of dump history. --}}
                    <div class="relative overflow-hidden bg-brand-ink text-brand-cream">
                        <div class="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full bg-brand-sage/25 blur-3xl" aria-hidden="true"></div>
                        <div class="pointer-events-none absolute -left-24 -bottom-24 h-64 w-64 rounded-full bg-brand-gold/15 blur-3xl" aria-hidden="true"></div>

                        <div class="relative grid gap-6 px-4 py-5 sm:px-6 sm:py-6 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,26rem)] lg:items-center lg:gap-8">
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
                                        <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('scheduled') }}</span>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Dump coverage') }}</p>
                                    <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                        @if ($metrics['databases'] === 0)
                                            {{ __('No databases found yet.') }}
                                        @elseif ($unscheduled === 0)
                                            {{ __('Every database is scheduled.') }}
                                        @else
                                            {{ trans_choice(':count database has no schedule|:count databases have no schedule', $unscheduled, ['count' => $unscheduled]) }}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-brand-cream/55">
                                        {{ __(':protected of :total on an active schedule · :storage stored', [
                                            'protected' => $metrics['protected'],
                                            'total' => $metrics['databases'],
                                            'storage' => $metrics['storage'],
                                        ]) }}
                                    </p>
                                </div>
                            </div>

                            {{-- What is actually being dumped, by engine. --}}
                            @if ($engineMix->isNotEmpty())
                                <div class="min-w-0 lg:border-l lg:border-brand-cream/10 lg:pl-8">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Engines') }}</p>
                                    <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                        @foreach ($engineMix as $engine => $count)
                                            @php
                                                $badge = $engineBadge($engine);
                                            @endphp
                                            <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.07] py-1 pl-1 pr-2.5 ring-1 ring-brand-cream/12">
                                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-brand-cream/10 font-mono text-[9px] font-bold text-brand-cream">{{ $badge[0] }}</span>
                                                <span class="text-xs text-brand-cream/85">{{ \Illuminate\Support\Str::title($engine) }}</span>
                                                <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream">{{ $count }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- Two weeks of dump history, as an actual shape --}}
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Dumps · 14 days') }}</p>
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
                                            // Floor at 14% so a single dump is still a visible
                                            // bar rather than a hairline next to a busy day.
                                            $barPercent = $dayTotal > 0
                                                ? max(14, (int) round($dayTotal / $activityMax * 100))
                                                : 0;
                                            $failedPercent = $dayTotal > 0
                                                ? (int) round($day['failed'] / $dayTotal * 100)
                                                : 0;
                                            $dayLabel = $day['date']->format('D M j').' — '.
                                                trans_choice(':count dump|:count dumps', $dayTotal, ['count' => $dayTotal]).
                                                ($day['failed'] > 0 ? ', '.__(':count failed', ['count' => $day['failed']]) : '');
                                        @endphp
                                        <div class="group flex min-w-0 flex-1 flex-col items-center gap-1.5" title="{{ $dayLabel }}">
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
                                        {{ __('No dumps in the last 14 days.') }}
                                    @else
                                        <span class="font-mono font-semibold tabular-nums text-brand-cream">{{ number_format($activityTotal) }}</span>
                                        {{ trans_choice('dump|dumps', $activityTotal) }}@if ($activityFailed > 0)<span class="text-brand-rust">, {{ __(':count failed', ['count' => $activityFailed]) }}</span>@endif.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                </x-slot:stats>

                <x-slot:tabs>
                    <x-backups-subnav active="databases" />
                </x-slot:tabs>

                {{-- Coverage: the columns here are the properties a dump needs
                     before it is worth anything. The list below stays the editor;
                     this is the scan. --}}
                @if ($coverageChecks !== [])
                    <x-backups-coverage-grid
                        :rows="$coverageChecks"
                        :entity-label="__('Database')"
                        :columns="[
                            'schedule' => __('Schedule'),
                            'dump' => __('Last dump'),
                            'offsite' => __('Off-box copy'),
                            'restore' => __('Restore'),
                        ]"
                        :note="__(':covered of :total checks passing across :count databases.', [
                            'covered' => collect($coverageChecks)->sum('covered'),
                            'total' => collect($coverageChecks)->sum('applicable'),
                            'count' => count($coverageChecks),
                        ])"
                    />
                @endif

                {{-- One row per database, with the schedule protecting it folded
                     in. The old layout split "here are your databases" and "here
                     are your schedules" across two tables, so answering "is this
                     one covered, and when did it last run" meant reading both.
                     Schedules whose target is gone are listed below instead. --}}
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-circle-stack"
                        :title="__('Databases')"
                        :count="$databases->isNotEmpty() ? $databases->count() : null"
                        :note="__('Edit a schedule or pull a dump. State is in the grid above. Instant dumps capped at :cap.', ['cap' => \Illuminate\Support\Number::fileSize((int) config('quick_download.max_bytes', 262_144_000))])"
                    />

                    @if ($databases->isEmpty())
                        <div class="px-4 py-10 text-center sm:px-6">
                            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sand/50 text-brand-moss ring-1 ring-brand-ink/8">
                                <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No databases found on your servers.') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('dply discovers databases when it provisions or imports a server.') }}</p>
                            <a
                                href="{{ route('servers.index') }}"
                                wire:navigate
                                class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                            >
                                {{ __('Go to servers') }}
                                <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                            </a>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($databases as $database)
                                @php
                                    $ownSchedules = $schedulesByTarget->get($database->id) ?? collect();
                                    $schedule = $ownSchedules->first();
                                    $badge = $engineBadge($database->engine);
                                    $trend = $trends[$database->id] ?? [];
                                    $trendMax = $trend === [] ? 0 : max($trend);
                                    $latestBytes = $trend === [] ? null : $trend[array_key_last($trend)];
                                    $next = $schedule ? ($nextRuns[$schedule->id] ?? null) : null;
                                    $lastRun = $lastRuns[$database->id] ?? null;
                                    $lastRunFailed = $lastRun?->status === 'failed';
                                @endphp
                                <li
                                    wire:key="db-{{ $database->id }}"
                                    @class([
                                        'group grid gap-x-4 gap-y-3 border-l-[3px] px-3 py-3 transition-colors hover:bg-brand-sand/15 sm:px-4',
                                        'lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)_auto_auto] lg:items-center',
                                        'border-brand-rust' => $lastRunFailed,
                                        'border-brand-sage' => ! $lastRunFailed && $schedule?->is_active,
                                        'border-brand-gold' => ! $lastRunFailed && $schedule && ! $schedule->is_active,
                                        'border-transparent' => ! $lastRunFailed && ! $schedule,
                                    ])
                                >
                                    {{-- Identity --}}
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl font-mono text-[11px] font-bold ring-1 {{ $badge[1] }}">
                                            {{ $badge[0] }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $database->name }}</p>
                                            <p class="truncate text-xs text-brand-moss">
                                                @if ($database->server)
                                                    <a href="{{ route('servers.backups', $database->server) }}" wire:navigate class="hover:text-brand-forest hover:underline">
                                                        {{ $database->server->name }}
                                                    </a>
                                                @else
                                                    —
                                                @endif
                                                <span class="text-brand-mist">· {{ \Illuminate\Support\Str::title($database->engine) }}</span>
                                            </p>
                                            @if ($lastRunFailed)
                                                {{-- A schedule can look perfectly healthy while every run
                                                     fails. Surfacing the CAUSE here — not the driver's
                                                     error code — is the difference between "protected"
                                                     and "believes it is protected". --}}
                                                @php
                                                    $why = app(\App\Modules\Backups\Services\BackupFailureExplainer::class)
                                                        ->explain($lastRun->error_message, $database->engine, $database->server?->name);
                                                @endphp
                                                <p class="mt-1 flex items-start gap-1 text-2xs leading-relaxed text-brand-rust" title="{{ $why['raw'] }}">
                                                    <x-heroicon-m-exclamation-triangle class="mt-px h-3 w-3 shrink-0" aria-hidden="true" />
                                                    <span>
                                                        <span class="font-semibold">{{ $why['summary'] }}</span>
                                                        @if ($why['action'])
                                                            <span class="text-brand-moss">{{ $why['action'] }}</span>
                                                        @endif
                                                    </span>
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Protection: the schedule, inline --}}
                                    <div class="min-w-0">
                                        @if ($schedule)
                                            <button
                                                type="button"
                                                wire:click="editSchedule('{{ $schedule->id }}')"
                                                class="group/sched w-full text-left"
                                                title="{{ __('Edit this schedule') }}"
                                            >
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                @if ($schedule->is_active)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/20 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-brand-forest">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                                        {{ __('Active') }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-gold/25 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-amber-800">
                                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                                                        {{ __('Paused') }}
                                                    </span>
                                                @endif
                                                <span class="truncate text-xs font-medium text-brand-ink">
                                                    {{ $schedule->cronDescription() ?: $schedule->cron_expression }}
                                                </span>
                                                @if ($ownSchedules->count() > 1)
                                                    <span class="shrink-0 text-2xs text-brand-mist">{{ __('+:count more', ['count' => $ownSchedules->count() - 1]) }}</span>
                                                @endif
                                            </div>
                                            <p class="mt-1 flex items-center gap-1 truncate font-mono text-2xs text-brand-mist">
                                                {{ $schedule->cron_expression }}
                                                @if ($next)
                                                    <span class="font-sans text-brand-moss">· {{ __('next in :when', [
                                                        'when' => $next->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true),
                                                    ]) }}</span>
                                                @endif
                                                <x-heroicon-o-pencil-square class="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover/sched:opacity-100" aria-hidden="true" />
                                            </p>
                                            </button>
                                        @else
                                            @if ($database->server)
                                                <button
                                                    type="button"
                                                    wire:click="openScheduleModal('{{ \App\Models\BackupSchedule::TARGET_DATABASE }}', '{{ $database->id }}', '{{ $database->server->id }}')"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-brand-ink/20 px-2.5 py-1 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-forest/40 hover:bg-white hover:text-brand-forest"
                                                >
                                                    <x-heroicon-m-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Not scheduled') }}
                                                </button>
                                            @else
                                                <span class="text-xs text-brand-mist">{{ __('Not scheduled') }}</span>
                                            @endif
                                        @endif
                                    </div>

                                    {{-- Dump-size trend. A dump that suddenly halves is
                                         the cheapest signal that something upstream broke. --}}
                                    <div class="hidden lg:block">
                                        @if (count($trend) > 1)
                                            <div
                                                class="flex h-8 w-24 items-end gap-px"
                                                title="{{ trans_choice('Last :count dump size|Last :count dump sizes', count($trend), ['count' => count($trend)]) }}"
                                                role="img"
                                                aria-label="{{ __('Recent dump sizes') }}"
                                            >
                                                @foreach ($trend as $bytes)
                                                    <span
                                                        class="flex-1 rounded-[1px] {{ $loop->last ? 'bg-brand-forest' : 'bg-brand-sage/70' }}"
                                                        style="height: {{ max(12, (int) round($bytes / max(1, $trendMax) * 100)) }}%"
                                                    ></span>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="h-8 w-24" aria-hidden="true"></div>
                                        @endif
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex flex-wrap items-center gap-1.5 lg:justify-end">
                                        <x-quick-download.database-link :server="$database->server" :database="$database" :active-key="$qdTargetKey" />
                                        @if ($schedule)
                                            <button
                                                type="button"
                                                wire:click="runScheduleNow('{{ $schedule->id }}')"
                                                wire:loading.attr="disabled"
                                                class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                                title="{{ __('Run an extra dump now — does not move the schedule') }}"
                                            >
                                                <x-heroicon-o-play class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Run') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="toggleSchedule('{{ $schedule->id }}')"
                                                wire:loading.attr="disabled"
                                                class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-brand-ink/15 bg-white text-brand-moss shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                                title="{{ $schedule->is_active ? __('Pause') : __('Resume') }}"
                                            >
                                                @if ($schedule->is_active)
                                                    <x-heroicon-o-pause class="h-3.5 w-3.5" aria-hidden="true" />
                                                @else
                                                    <x-heroicon-o-play-pause class="h-3.5 w-3.5" aria-hidden="true" />
                                                @endif
                                            </button>
                                        @endif
                                        @if ($database->server)
                                            <a
                                                href="{{ route('servers.backups', $database->server) }}"
                                                wire:navigate
                                                class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-brand-ink/10 bg-white text-brand-mist shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
                                                title="{{ __('Open on server') }}"
                                            >
                                                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                            </a>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Schedules whose target database no longer exists. Rare, but
                     they still fire, so they cannot be silently dropped from the
                     merged rows above. --}}
                @if ($orphanSchedules->isNotEmpty())
                    <section class="border-b border-brand-ink/10 bg-brand-rust/[0.05]">
                        <x-workspace-panel-head
                            dense
                            tone="amber"
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-exclamation-triangle"
                            :title="__('Orphaned schedules')"
                            :count="$orphanSchedules->count()"
                            :note="__('These point at a database dply can no longer find.')"
                        />
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($orphanSchedules as $schedule)
                                <li wire:key="orphan-{{ $schedule->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-l-[3px] border-brand-rust px-3 py-2.5 sm:px-4">
                                    <span class="shrink-0 text-sm font-semibold text-brand-ink">{{ $schedule->targetLabel() }}</span>
                                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-mist">{{ $schedule->cron_expression }}</span>
                                    <span class="shrink-0 text-xs text-brand-moss">{{ $schedule->server?->name ?? '—' }}</span>
                                    <button
                                        type="button"
                                        wire:click="toggleSchedule('{{ $schedule->id }}')"
                                        wire:loading.attr="disabled"
                                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-moss shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                    >
                                        {{ $schedule->is_active ? __('Pause') : __('Resume') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Run history. Every row now carries what you need to judge it
                     without opening anything: which database, which server, where
                     it shipped, how big, and the error when it failed. --}}
                <section>
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-clipboard-document-list"
                        :title="__('Run history')"
                        :count="$runs->total()"
                        :note="__('Every database dump across all servers.')"
                    />

                    {{-- Filter bar --}}
                    <div class="flex flex-col gap-2 border-b border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5 sm:flex-row sm:items-center sm:px-4">
                        <div class="relative min-w-0 flex-1">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                                <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <input
                                type="search"
                                wire:model.live.debounce.300ms="runSearch"
                                placeholder="{{ __('Search database, server, destination or error…') }}"
                                class="w-full rounded-lg border-brand-ink/15 bg-white py-1.5 ps-8 pe-3 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage"
                                aria-label="{{ __('Search run history') }}"
                            />
                        </div>

                        <select
                            wire:model.live="runStatus"
                            class="rounded-lg border-brand-ink/15 bg-white py-1.5 pe-8 ps-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            aria-label="{{ __('Filter by status') }}"
                        >
                            <option value="">{{ __('Any status') }}</option>
                            <option value="completed">{{ __('Completed') }}</option>
                            <option value="failed">{{ __('Failed') }}</option>
                            <option value="pending">{{ __('Pending') }}</option>
                        </select>

                        <select
                            wire:model.live="runDestination"
                            class="max-w-[14rem] rounded-lg border-brand-ink/15 bg-white py-1.5 pe-8 ps-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            aria-label="{{ __('Filter by destination') }}"
                        >
                            <option value="">{{ __('Any destination') }}</option>
                            <option value="none">{{ __('Kept on the server') }}</option>
                            @foreach ($runDestinations as $destination)
                                <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                            @endforeach
                        </select>

                        @if ($this->hasRunFilters())
                            <button
                                type="button"
                                wire:click="clearRunFilters"
                                class="shrink-0 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-moss shadow-sm transition-colors hover:text-brand-ink"
                            >
                                {{ __('Clear') }}
                            </button>
                        @endif
                    </div>

                    @if ($runs->isEmpty())
                        <div class="px-3 py-10 text-center sm:px-4">
                            <x-heroicon-o-clipboard-document-list class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                            <p class="mt-2 text-sm text-brand-moss">
                                {{ $this->hasRunFilters() ? __('No runs match these filters.') : __('No dumps yet.') }}
                            </p>
                            @if ($this->hasRunFilters())
                                <button type="button" wire:click="clearRunFilters" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                    {{ __('Clear filters') }}
                                </button>
                            @endif
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($runs as $run)
                                @php
                                    $tone = match ($run->status) {
                                        'completed' => ['bg-brand-sage text-brand-cream', 'heroicon-m-check', __('Completed')],
                                        'failed' => ['bg-brand-rust text-brand-cream', 'heroicon-m-x-mark', __('Failed')],
                                        default => ['bg-brand-gold text-brand-ink', 'heroicon-m-ellipsis-horizontal', __('Pending')],
                                    };
                                    $shipped = $run->backupConfiguration !== null;
                                @endphp
                                <li wire:key="run-{{ $run->id }}" @class([
                                    'flex flex-col gap-2 border-l-[3px] px-3 py-3 transition-colors hover:bg-brand-sand/15 sm:flex-row sm:items-start sm:gap-4 sm:px-4',
                                    'border-brand-sage' => $run->status === 'completed',
                                    'border-brand-rust' => $run->status === 'failed',
                                    'border-brand-gold' => ! in_array($run->status, ['completed', 'failed'], true),
                                ])>
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $tone[0] }}">
                                        <x-dynamic-component :component="$tone[1]" class="h-4 w-4" aria-hidden="true" />
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="text-sm font-semibold text-brand-ink">{{ $run->serverDatabase?->name ?? __('(database removed)') }}</span>
                                            <span @class([
                                                'inline-flex items-center rounded px-1.5 py-0.5 text-2xs font-bold uppercase tracking-wide',
                                                'bg-brand-sage/20 text-brand-forest' => $run->status === 'completed',
                                                'bg-brand-rust/15 text-brand-rust' => $run->status === 'failed',
                                                'bg-brand-gold/25 text-amber-800' => ! in_array($run->status, ['completed', 'failed'], true),
                                            ])>{{ $tone[2] }}</span>
                                        </p>

                                        {{-- Where it came from and where it went, on one line. --}}
                                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-o-server class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                {{ $run->serverDatabase?->server?->name ?? '—' }}
                                            </span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                @if ($shipped)
                                                    {{ $run->backupConfiguration->name }}
                                                    <span class="text-brand-mist">· {{ \App\Models\BackupConfiguration::labelForProvider($run->backupConfiguration->provider) }}</span>
                                                @else
                                                    <span class="text-brand-mist">{{ __('kept on the server') }}</span>
                                                @endif
                                            </span>
                                            <span class="inline-flex items-center gap-1 font-mono tabular-nums">
                                                <x-heroicon-o-circle-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                {{ $run->bytes ? \Illuminate\Support\Number::fileSize((int) $run->bytes) : __('no artifact') }}
                                            </span>
                                        </p>

                                        @if ($run->destination_path || $run->s3_key)
                                            <p class="mt-1 truncate font-mono text-2xs text-brand-mist" title="{{ $run->destination_path ?: $run->s3_key }}">
                                                {{ $run->destination_path ?: $run->s3_key }}
                                            </p>
                                        @endif

                                        @if ($run->error_message)
                                            @php
                                                $runWhy = app(\App\Modules\Backups\Services\BackupFailureExplainer::class)
                                                    ->explain($run->error_message, $run->serverDatabase?->engine, $run->serverDatabase?->server?->name);
                                            @endphp
                                            <div class="mt-1.5 rounded-lg bg-brand-rust/8 px-2 py-1.5 text-xs leading-relaxed">
                                                <p class="font-semibold text-brand-rust">{{ $runWhy['summary'] }}</p>
                                                @if ($runWhy['action'])
                                                    <p class="mt-0.5 text-brand-moss">{{ $runWhy['action'] }}</p>
                                                @endif
                                                @if ($runWhy['raw'] !== $runWhy['summary'])
                                                    {{-- The driver's own words stay one click away: an
                                                         explanation that hides evidence is worse than none. --}}
                                                    <details class="mt-1">
                                                        <summary class="cursor-pointer text-2xs text-brand-mist hover:text-brand-moss">{{ __('Show original error') }}</summary>
                                                        <p class="mt-1 break-words font-mono text-2xs text-brand-mist">{{ $runWhy['raw'] }}</p>
                                                    </details>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 items-start gap-3">
                                        <time
                                            class="text-right font-mono text-xs tabular-nums text-brand-moss"
                                            datetime="{{ $run->created_at->toIso8601String() }}"
                                            title="{{ $run->created_at->format('Y-m-d H:i:s') }}"
                                        >
                                            {{ $run->created_at->diffForHumans(short: true) }}
                                            <span class="mt-0.5 block text-2xs text-brand-mist">{{ $run->created_at->format('M j, H:i') }}</span>
                                        </time>

                                        @php($runDownloadable = $run->isDownloadable())
                                        <div class="flex items-center gap-1">
                                            @if ($runDownloadable)
                                                <button
                                                    type="button"
                                                    wire:click="downloadRun('{{ $run->id }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="downloadRun('{{ $run->id }}')"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-brand-ink/15 bg-white text-brand-moss shadow-sm transition-colors hover:bg-brand-sand/40 hover:text-brand-ink"
                                                    title="{{ __('Download this dump') }}"
                                                >
                                                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                                                </button>

                                                {{-- Restore is the one action that destroys data, so it
                                                     is styled as such and opens a typed confirmation
                                                     rather than acting on click. --}}
                                                <button
                                                    type="button"
                                                    wire:click="openRestoreModal('{{ $run->id }}')"
                                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-brand-rust/30 bg-white text-brand-rust shadow-sm transition-colors hover:bg-brand-rust/10"
                                                    title="{{ __('Restore from this dump — overwrites a live database') }}"
                                                >
                                                    <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" />
                                                </button>
                                            @endif

                                            <button
                                                type="button"
                                                wire:click="deleteRun('{{ $run->id }}')"
                                                wire:confirm="{{ __('Delete this backup and its stored file? This cannot be undone.') }}"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-brand-ink/10 bg-white text-brand-mist shadow-sm transition-colors hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                                title="{{ __('Delete this backup') }}"
                                            >
                                                <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        @if ($runs->hasPages())
                            <div class="border-t border-brand-ink/10 px-3 py-2.5 sm:px-4">
                                {{ $runs->onEachSide(1)->links() }}
                            </div>
                        @endif
                    @endif
                </section>

            </x-profile-shell>

            @include('livewire.backups.partials._schedule-modal')
            @include('livewire.backups.partials._restore-modal')
        @endif
    </div>
</div>
