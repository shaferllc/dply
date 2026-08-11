<div class="contents">
    @if ($qdId)
        <div wire:poll.1500ms="pollQuickDownload" class="hidden"></div>
    @endif
    @if ($stagingId !== null)
        <div wire:poll.2s="pollStaging" class="hidden" aria-hidden="true"></div>
    @endif

    <x-workspace-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Backups'), 'href' => route('backups.overview'), 'icon' => 'archive-box'],
            ['label' => __('Files'), 'icon' => 'folder'],
        ]" />

        @if (! $featureActive)
            <x-profile-shell
                dense
                :title="__('File backups')"
                :description="__('Scheduled site archives and on-demand downloads.')"
                icon="heroicon-o-folder"
            >
                <x-slot:tabs>
                    <x-backups-subnav active="files" />
                </x-slot:tabs>
                <div class="px-3 py-3 sm:px-4">
                    <x-backups-preview-panel compact />
                </div>
            </x-profile-shell>
        @else
            @php
                $coverage = $metrics['coverage'];
                $unscheduled = $metrics['archivable'] - $metrics['protected'];

                $coverageTone = match (true) {
                    $metrics['archivable'] === 0 => 'text-brand-mist',
                    $unscheduled === 0 => 'text-brand-sage',
                    $metrics['protected'] === 0 => 'text-brand-rust',
                    default => 'text-brand-gold',
                };

                // r=52 on a 120 viewBox, same dial as the other Backups tabs.
                $dialCircumference = 326.726;
                $dialOffset = $dialCircumference * (1 - min(100, max(0, $coverage)) / 100);

                $activityMax = max(1, collect($activity)->max(fn ($day) => $day['completed'] + $day['failed']));
                $activityTotal = collect($activity)->sum(fn ($day) => $day['completed'] + $day['failed']);
                $activityFailed = collect($activity)->sum(fn ($day) => $day['failed']);
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
                :title="__('File backups')"
                :description="__('Full site archives across :org — pair one with every database dump for a complete restore.', ['org' => $organization->name])"
                icon="heroicon-o-folder"
            >
                <x-slot:actions>
                    <a
                        href="{{ route('backups.storage') }}"
                        wire:navigate
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-archive-box class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Destinations') }}
                    </a>
                </x-slot:actions>

                <x-slot:stats>
                    {{-- Same inverted console as the other Backups tabs, scoped to
                         site archives: how much of what CAN be archived is
                         scheduled, what the fleet is made of, and two weeks of
                         archive history. --}}
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
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Archive coverage') }}</p>
                                    <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                        @if ($metrics['archivable'] === 0)
                                            {{ __('No site can be archived yet.') }}
                                        @elseif ($unscheduled === 0)
                                            {{ __('Every archivable site is scheduled.') }}
                                        @else
                                            {{ trans_choice(':count site has no schedule|:count sites have no schedule', $unscheduled, ['count' => $unscheduled]) }}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-brand-cream/55">
                                        {{ __(':protected of :total archivable sites · :storage stored', [
                                            'protected' => $metrics['protected'],
                                            'total' => $metrics['archivable'],
                                            'storage' => $metrics['storage'],
                                        ]) }}
                                    </p>
                                </div>
                            </div>

                            {{-- What the fleet is actually made of. Coverage is a
                                 ratio over archivable sites only, so the split has
                                 to be visible or the dial looks wrong. --}}
                            <div class="min-w-0 lg:border-l lg:border-brand-cream/10 lg:pl-8">
                                <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Archive readiness') }}</p>
                                <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                    <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.07] py-1 pl-2.5 pr-2.5 ring-1 ring-brand-cream/12">
                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                        <span class="text-xs text-brand-cream/85">{{ __('SSH-ready') }}</span>
                                        <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream">{{ $metrics['archivable'] }}</span>
                                    </li>
                                    @if ($metrics['unarchivable'] > 0)
                                        <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.04] py-1 pl-2.5 pr-2.5 ring-1 ring-brand-cream/10">
                                            <span class="h-1.5 w-1.5 rounded-full bg-brand-cream/30" aria-hidden="true"></span>
                                            <span class="text-xs text-brand-cream/60">{{ __('No filesystem') }}</span>
                                            <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream/70">{{ $metrics['unarchivable'] }}</span>
                                        </li>
                                    @endif
                                    <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.07] py-1 pl-2.5 pr-2.5 ring-1 ring-brand-cream/12">
                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-copper" aria-hidden="true"></span>
                                        <span class="text-xs text-brand-cream/85">{{ __('Ever archived') }}</span>
                                        <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream">{{ $metrics['archivedSites'] }}</span>
                                    </li>
                                </ul>
                                @if ($metrics['unarchivable'] > 0)
                                    <p class="mt-2 text-2xs leading-relaxed text-brand-cream/40">
                                        {{ __('Edge and serverless sites have no filesystem to archive, so they are left out of coverage.') }}
                                    </p>
                                @endif
                            </div>

                            {{-- Two weeks of archive history, as an actual shape --}}
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Archives · 14 days') }}</p>
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
                                            // Floor at 14% so a single archive is still a
                                            // visible bar next to a busy day.
                                            $barPercent = $dayTotal > 0
                                                ? max(14, (int) round($dayTotal / $activityMax * 100))
                                                : 0;
                                            $failedPercent = $dayTotal > 0
                                                ? (int) round($day['failed'] / $dayTotal * 100)
                                                : 0;
                                            $dayLabel = $day['date']->format('D M j').' — '.
                                                trans_choice(':count archive|:count archives', $dayTotal, ['count' => $dayTotal]).
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
                                        {{ __('No archives in the last 14 days.') }}
                                    @else
                                        <span class="font-mono font-semibold tabular-nums text-brand-cream">{{ number_format($activityTotal) }}</span>
                                        {{ trans_choice('archive|archives', $activityTotal) }}@if ($activityFailed > 0)<span class="text-brand-rust">, {{ __(':count failed', ['count' => $activityFailed]) }}</span>@endif.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                </x-slot:stats>

                <x-slot:tabs>
                    <x-backups-subnav active="files" />
                </x-slot:tabs>

                {{-- One row per site with the schedule protecting it folded in.
                     Until now this tab showed no schedules at all, so a file
                     schedule set up in a server workspace was invisible here even
                     though it fires on the same dispatcher as every dump. --}}
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-globe-alt"
                        :title="__('Sites')"
                        :count="$sites->isNotEmpty() ? $sites->count() : null"
                        :note="__('A full tar.gz of each site repository root (vendor / node_modules excluded by default). Add or edit a schedule from the server workspace.')"
                    />

                    @if ($sites->isEmpty())
                        <div class="px-4 py-10 text-center sm:px-6">
                            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sand/50 text-brand-moss ring-1 ring-brand-ink/8">
                                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No sites yet.') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Create a server and add a site to enable file backups.') }}</p>
                            <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                                @if (multi_surface_active())
                                    <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                                        {{ __('Open launchpad') }}
                                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                                    </a>
                                @else
                                    <a href="{{ route('servers.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                                        {{ __('Add a server') }}
                                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                                    </a>
                                @endif
                                <a href="{{ route('sites.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                                    {{ __('View sites') }}
                                </a>
                            </div>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($sites as $site)
                                @php
                                    $archivable = $site->supportsSshFileArchive();
                                    $ownSchedules = $schedulesByTarget->get($site->id) ?? collect();
                                    $schedule = $ownSchedules->first();
                                    $next = $schedule ? ($nextRuns[$schedule->id] ?? null) : null;
                                    $siteBackups = $recentBackups->get($site->id) ?? collect();
                                    $latest = $siteBackups->first();
                                    $latestDownloadable = $siteBackups->first(fn ($backup) => $backup->isDownloadable());
                                    $trend = $trends[$site->id] ?? [];
                                    $trendMax = $trend === [] ? 0 : max($trend);
                                    $effectiveRoot = $site->effectiveRepositoryPath();
                                    $runbookCount = $site->workspace?->runbooks?->count() ?? 0;
                                @endphp

                                {{-- Sites are ordered archivable-first, so this is
                                     the one boundary between "you can act on this"
                                     and "there is nothing to archive here". --}}
                                @if (! $archivable && ($loop->first || $sites[$loop->index - 1]->supportsSshFileArchive()))
                                    <li wire:key="unarchivable-divider" class="flex items-center gap-3 bg-brand-sand/20 px-3 py-1.5 sm:px-4">
                                        <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('No filesystem to archive') }}</span>
                                        <span class="h-px flex-1 bg-brand-ink/10"></span>
                                        <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $metrics['unarchivable'] }}</span>
                                    </li>
                                @endif

                                <li
                                    wire:key="file-backup-{{ $site->id }}"
                                    @class([
                                        'group grid gap-x-4 gap-y-3 border-l-[3px] px-3 py-3 transition-colors hover:bg-brand-sand/15 sm:px-4',
                                        'lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1.1fr)_minmax(0,1fr)_auto_auto] lg:items-center',
                                        'border-brand-sage' => $schedule?->is_active,
                                        'border-brand-gold' => $schedule && ! $schedule->is_active,
                                        'border-transparent' => ! $schedule,
                                    ])
                                >
                                    {{-- Identity --}}
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span @class([
                                            'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1',
                                            'bg-brand-copper/12 text-brand-copper ring-brand-copper/25' => $archivable,
                                            'bg-brand-sand/50 text-brand-mist ring-brand-ink/10' => ! $archivable,
                                        ])>
                                            <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-ink">
                                                <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-forest hover:underline">
                                                    {{ $site->name }}
                                                </a>
                                            </p>
                                            <p class="truncate text-xs text-brand-moss">
                                                {{ $site->server?->name ?? '—' }}
                                                <span class="font-mono text-brand-mist" title="{{ __('Archive root') }}">· {{ $effectiveRoot }}</span>
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Protection: the schedule, inline --}}
                                    <div class="min-w-0">
                                        @if ($schedule)
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
                                            <p class="mt-1 truncate font-mono text-2xs text-brand-mist">
                                                {{ $schedule->cron_expression }}
                                                @if ($next)
                                                    <span class="font-sans text-brand-moss">· {{ __('next in :when', [
                                                        'when' => $next->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true),
                                                    ]) }}</span>
                                                @endif
                                            </p>
                                        @elseif ($archivable)
                                            <a
                                                href="{{ $site->server ? route('servers.backups', $site->server) : route('servers.index') }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-brand-ink/20 px-2.5 py-1 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-forest/40 hover:bg-white hover:text-brand-forest"
                                            >
                                                <x-heroicon-m-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Not scheduled') }}
                                            </a>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-brand-sand/40 px-2.5 py-1 text-xs font-medium text-brand-mist">
                                                <x-heroicon-m-no-symbol class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('No filesystem to archive') }}
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Last archive + where it landed --}}
                                    <div class="min-w-0 text-xs">
                                        @if ($latest)
                                            <p class="truncate text-brand-ink">
                                                <span @class([
                                                    'font-medium',
                                                    'text-brand-rust' => $latest->status === \App\Modules\Backups\Models\SiteFileBackup::STATUS_FAILED,
                                                ])>{{ $latest->created_at->diffForHumans(short: true) }}</span>
                                                @if ($latest->bytes)
                                                    <span class="font-mono tabular-nums text-brand-moss">· {{ \Illuminate\Support\Number::fileSize((int) $latest->bytes) }}</span>
                                                @endif
                                            </p>
                                            <p class="mt-0.5 truncate text-brand-mist">
                                                {{ \Illuminate\Support\Str::of($latest->status)->replace('_', ' ')->title() }}
                                                @if ($runbookCount > 0)
                                                    · {{ trans_choice(':count runbook|:count runbooks', $runbookCount, ['count' => $runbookCount]) }}
                                                @endif
                                            </p>
                                        @elseif ($archivable)
                                            <p class="text-brand-mist">{{ __('Never archived') }}</p>
                                            @if ($runbookCount === 0)
                                                <p class="mt-0.5 truncate text-brand-mist">{{ __('No restore runbook') }}</p>
                                            @endif
                                        @else
                                            <p class="text-brand-mist">—</p>
                                        @endif
                                    </div>

                                    {{-- Archive-size trend. An archive that suddenly
                                         halves is the cheapest signal that an exclude
                                         rule or a mount went wrong. --}}
                                    <div class="hidden lg:block">
                                        @if (count($trend) > 1)
                                            <div
                                                class="flex h-8 w-24 items-end gap-px"
                                                title="{{ trans_choice('Last :count archive size|Last :count archive sizes', count($trend), ['count' => count($trend)]) }}"
                                                role="img"
                                                aria-label="{{ __('Recent archive sizes') }}"
                                            >
                                                @foreach ($trend as $bytes)
                                                    <span
                                                        class="flex-1 rounded-[1px] {{ $loop->last ? 'bg-brand-copper' : 'bg-brand-copper/45' }}"
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
                                        @if ($archivable)
                                            <button
                                                type="button"
                                                wire:click="queueFullBackup('{{ $site->id }}')"
                                                wire:loading.attr="disabled"
                                                class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
                                                title="{{ __('Queue a full tar.gz of the repository root') }}"
                                            >
                                                <x-heroicon-o-archive-box-arrow-down class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Archive') }}
                                            </button>
                                            @if ($latestDownloadable)
                                                @if ($stagingBackupId === $latestDownloadable->id)
                                                    <span class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-mist shadow-sm">
                                                        {{ __('Preparing…') }}
                                                    </span>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="requestDownload('site_files', '{{ $latestDownloadable->id }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="requestDownload"
                                                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                                        title="{{ __('Download the newest completed archive') }}"
                                                    >
                                                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                                                        {{ __('Download') }}
                                                    </button>
                                                @endif
                                            @endif
                                            <x-quick-download.site-menu :server="$site->server" :site="$site" :active-key="$qdTargetKey" />
                                            @if ($schedule)
                                                <button
                                                    type="button"
                                                    wire:click="runScheduleNow('{{ $schedule->id }}')"
                                                    wire:loading.attr="disabled"
                                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                                    title="{{ __('Run an extra archive now — does not move the schedule') }}"
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
                                        @endif
                                        @if ($site->server)
                                            <a
                                                href="{{ route('servers.backups', $site->server) }}"
                                                wire:navigate
                                                class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-brand-ink/10 bg-white text-brand-mist shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
                                                title="{{ __('Open on server') }}"
                                            >
                                                <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                            </a>
                                        @endif
                                    </div>

                                    @if ($latestDownloadable && isset($stagingErrors[$latestDownloadable->id]))
                                        <p class="text-xs text-brand-rust lg:col-span-5">{{ $stagingErrors[$latestDownloadable->id] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Schedules whose target site no longer exists. Rare, but they
                     still fire, so they cannot be silently dropped. --}}
                @if ($orphanSchedules->isNotEmpty())
                    <section class="border-b border-brand-ink/10 bg-brand-rust/[0.05]">
                        <x-workspace-panel-head
                            dense
                            tone="amber"
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-exclamation-triangle"
                            :title="__('Orphaned schedules')"
                            :count="$orphanSchedules->count()"
                            :note="__('These point at a site dply can no longer find.')"
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

                {{-- Run history, grouped by day. Download lives here too: it is
                     where you look when you need a specific older archive rather
                     than the newest one. --}}
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-clipboard-document-list"
                        :title="__('Run history')"
                        :note="__('Last 25 site archives across all servers.')"
                    />

                    @if ($runs->isEmpty())
                        <div class="px-3 py-8 text-center sm:px-4">
                            <x-heroicon-o-clipboard-document-list class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                            <p class="mt-2 text-sm text-brand-moss">{{ __('No archives yet.') }}</p>
                        </div>
                    @else
                        @foreach ($runs->groupBy(fn ($run) => $run->created_at->toDateString()) as $day => $dayRuns)
                            @php
                                $dayDate = $dayRuns->first()->created_at;
                                $dayBytes = $dayRuns->sum(fn ($run) => (int) $run->bytes);
                                $dayFailed = $dayRuns->where('status', \App\Modules\Backups\Models\SiteFileBackup::STATUS_FAILED)->count();
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
                                        {{ $dayRuns->count() }} · {{ \Illuminate\Support\Number::fileSize($dayBytes) }}
                                    </span>
                                </div>

                                {{-- No row rules inside a day: the tight column of
                                     status nodes carries the rhythm. --}}
                                <ul class="py-1">
                                    @foreach ($dayRuns as $run)
                                        @php
                                            $tone = match ($run->status) {
                                                \App\Modules\Backups\Models\SiteFileBackup::STATUS_COMPLETED => ['bg-brand-sage text-brand-cream', 'heroicon-m-check', __('Done')],
                                                \App\Modules\Backups\Models\SiteFileBackup::STATUS_FAILED => ['bg-brand-rust text-brand-cream', 'heroicon-m-x-mark', __('Failed')],
                                                default => ['bg-brand-gold text-brand-ink', 'heroicon-m-ellipsis-horizontal', __('Pending')],
                                            };
                                        @endphp
                                        <li wire:key="run-{{ $run->id }}" class="relative flex items-center gap-3 px-3 py-1.5 transition-colors hover:bg-brand-sand/20 sm:px-4">
                                            <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-[3px] ring-brand-cream {{ $tone[0] }}">
                                                <x-dynamic-component :component="$tone[1]" class="h-4 w-4" aria-hidden="true" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-brand-ink">
                                                    <span class="font-semibold">{{ $run->site?->name ?? '—' }}</span>
                                                    <span class="text-brand-mist">{{ __('on') }}</span>
                                                    {{ $run->site?->server?->name ?? '—' }}
                                                </p>
                                                <p class="mt-0.5 truncate text-xs text-brand-mist">
                                                    <span @class([
                                                        'font-medium',
                                                        'text-brand-rust' => $run->status === \App\Modules\Backups\Models\SiteFileBackup::STATUS_FAILED,
                                                        'text-brand-moss' => $run->status !== \App\Modules\Backups\Models\SiteFileBackup::STATUS_FAILED,
                                                    ])>{{ $tone[2] }}</span>
                                                    · <span class="font-mono tabular-nums">{{ $run->bytes ? \Illuminate\Support\Number::fileSize((int) $run->bytes) : __('no artifact') }}</span>
                                                    · {{ $run->effectiveStorageKind() === \App\Modules\Backups\Models\SiteFileBackup::STORAGE_KIND_REMOTE_SERVER
                                                        ? __('On the server')
                                                        : __('Control plane') }}
                                                    @if ($run->error_message)
                                                        · <span class="text-brand-rust">{{ \Illuminate\Support\Str::limit($run->error_message, 80) }}</span>
                                                    @endif
                                                </p>
                                                @if (isset($stagingErrors[$run->id]))
                                                    <p class="mt-0.5 text-xs text-brand-rust">{{ $stagingErrors[$run->id] }}</p>
                                                @endif
                                            </div>
                                            @if ($run->isDownloadable())
                                                @if ($stagingBackupId === $run->id)
                                                    <span class="shrink-0 text-xs font-semibold text-brand-mist">{{ __('Preparing…') }}</span>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="requestDownload('site_files', '{{ $run->id }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="requestDownload"
                                                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                                                    >
                                                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                                                        {{ __('Download') }}
                                                    </button>
                                                @endif
                                            @endif
                                            <time
                                                class="shrink-0 font-mono text-xs tabular-nums text-brand-moss"
                                                datetime="{{ $run->created_at->toIso8601String() }}"
                                                title="{{ $run->created_at->format('Y-m-d H:i:s') }}"
                                            >
                                                {{ $run->created_at->diffForHumans(short: true) }}
                                            </time>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @endif
                </section>

                {{-- Hygiene + where archives land. Storage owns destinations, so
                     this is a pointer, not a second editor. --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss sm:px-4">
                    <p class="min-w-0 flex-1">
                        <span class="font-semibold text-brand-ink">{{ __('Hygiene:') }}</span>
                        {{ __('back up hard-to-recreate paths, keep excludes explicit, and document the restore destination and its checks in a runbook. Dumps and archives are separate artifacts — restore SQL, then files.') }}
                    </p>
                    @if ($storageDestinations->isEmpty())
                        <a href="{{ route('backups.storage') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-md bg-brand-ink px-2 py-1 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                            {{ __('Add a storage destination') }}
                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    @else
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            @foreach ($storageDestinations->take(4) as $destination)
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-2xs text-brand-ink">
                                    <span class="font-semibold">{{ $destination->name }}</span>
                                    <span class="text-brand-mist">· {{ $providerLabels[$destination->provider] ?? $destination->provider }}</span>
                                </span>
                            @endforeach
                            @if ($storageDestinations->count() > 4)
                                <a href="{{ route('backups.storage') }}" wire:navigate class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-2xs font-semibold text-brand-moss hover:text-brand-ink">
                                    {{ __('+:count more', ['count' => $storageDestinations->count() - 4]) }}
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            </x-profile-shell>
        @endif
    </div>
</div>
