<div class="contents">
    <x-workspace-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Backups'), 'href' => route('backups.overview'), 'icon' => 'archive-box'],
            ['label' => __('Snapshots'), 'icon' => 'camera'],
        ]" />

        @if (! $featureActive)
            <x-profile-shell
                dense
                :title="__('Snapshots')"
                :description="__('Full-disk provider images across your fleet.')"
                icon="heroicon-o-camera"
            >
                <x-slot:tabs>
                    <x-backups-subnav active="snapshots" />
                </x-slot:tabs>
                <div class="px-3 py-3 sm:px-4">
                    <x-backups-preview-panel compact />
                </div>
            </x-profile-shell>
        @else
            @php
                $coverage = $metrics['coverage'];
                $uncoveredCount = $metrics['capable'] - $metrics['imaged'];

                $coverageTone = match (true) {
                    $metrics['capable'] === 0 => 'text-brand-mist',
                    $uncoveredCount === 0 => 'text-brand-sage',
                    $metrics['imaged'] === 0 => 'text-brand-rust',
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
                :title="__('Snapshots')"
                :description="__('Full-disk provider images across :org — restore a whole machine, not just its data.', ['org' => $organization->name])"
                icon="heroicon-o-camera"
            >
                <x-slot:stats>
                    {{-- Same inverted console as the other Backups tabs, scoped to
                         provider images: how much of what CAN be imaged is imaged,
                         what the fleet is made of, and two weeks of capture
                         history. --}}
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
                                        <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('imaged') }}</span>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Image coverage') }}</p>
                                    <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                        @if ($metrics['capable'] === 0)
                                            {{ __('No server can be imaged.') }}
                                        @elseif ($uncoveredCount === 0)
                                            {{ __('Every capable server has an image.') }}
                                        @else
                                            {{ trans_choice(':count server has never been imaged|:count servers have never been imaged', $uncoveredCount, ['count' => $uncoveredCount]) }}
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-brand-cream/55">
                                        {{ __(':imaged of :capable capable servers · :storage stored', [
                                            'imaged' => $metrics['imaged'],
                                            'capable' => $metrics['capable'],
                                            'storage' => $metrics['storage'],
                                        ]) }}
                                    </p>
                                </div>
                            </div>

                            {{-- What the fleet is made of. Coverage is a ratio over
                                 capable servers only, so the split has to be
                                 visible or the dial looks wrong. --}}
                            <div class="min-w-0 lg:border-l lg:border-brand-cream/10 lg:pl-8">
                                <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Image readiness') }}</p>
                                <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                    <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.07] px-2.5 py-1 ring-1 ring-brand-cream/12">
                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                        <span class="text-xs text-brand-cream/85">{{ __('Capable') }}</span>
                                        <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream">{{ $metrics['capable'] }}</span>
                                    </li>
                                    @if ($metrics['incapable'] > 0)
                                        <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.04] px-2.5 py-1 ring-1 ring-brand-cream/10">
                                            <span class="h-1.5 w-1.5 rounded-full bg-brand-cream/30" aria-hidden="true"></span>
                                            <span class="text-xs text-brand-cream/60">{{ __('No image API') }}</span>
                                            <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream/70">{{ $metrics['incapable'] }}</span>
                                        </li>
                                    @endif
                                    <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.07] px-2.5 py-1 ring-1 ring-brand-cream/12">
                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-copper" aria-hidden="true"></span>
                                        <span class="text-xs text-brand-cream/85">{{ __('Images') }}</span>
                                        <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream">{{ number_format($metrics['images']) }}</span>
                                    </li>
                                </ul>
                                <p class="mt-2 text-2xs leading-relaxed text-brand-cream/40">
                                    {{ __('Images live in your own provider account and are billed by them. dply keeps the index and the restore path.') }}
                                </p>
                            </div>

                            {{-- Two weeks of capture history, as an actual shape --}}
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Images · 14 days') }}</p>
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
                                            // Floor at 14% so a single capture is still a
                                            // visible bar next to a busy day.
                                            $barPercent = $dayTotal > 0
                                                ? max(14, (int) round($dayTotal / $activityMax * 100))
                                                : 0;
                                            $failedPercent = $dayTotal > 0
                                                ? (int) round($day['failed'] / $dayTotal * 100)
                                                : 0;
                                            $dayLabel = $day['date']->format('D M j').' — '.
                                                trans_choice(':count image|:count images', $dayTotal, ['count' => $dayTotal]).
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
                                        {{ __('No images taken in the last 14 days.') }}
                                    @else
                                        <span class="font-mono font-semibold tabular-nums text-brand-cream">{{ number_format($activityTotal) }}</span>
                                        {{ trans_choice('image|images', $activityTotal) }}@if ($activityFailed > 0)<span class="text-brand-rust">, {{ __(':count failed', ['count' => $activityFailed]) }}</span>@endif.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                </x-slot:stats>

                <x-slot:tabs>
                    <x-backups-subnav active="snapshots" />
                </x-slot:tabs>

                {{-- One row per server with its newest image folded in. The old
                     layout scattered the same servers across three lists — a
                     "never imaged" chip row, an images table, and a "cannot be
                     imaged" chip row — so answering "is this box covered, and how
                     big was the last one" meant reading all three. --}}
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-server"
                        :title="__('Servers')"
                        :count="$servers->isNotEmpty() ? $servers->count() : null"
                        :note="__('Taking and deleting an image stays on the server workspace — open one to capture.')"
                    />

                    @if ($servers->isEmpty())
                        <div class="px-4 py-10 text-center sm:px-6">
                            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sand/50 text-brand-moss ring-1 ring-brand-ink/8">
                                <x-heroicon-o-server class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No servers yet.') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Add a server on a provider with an image API to capture full-disk snapshots.') }}</p>
                            <a
                                href="{{ route('servers.create') }}"
                                wire:navigate
                                class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                            >
                                {{ __('Add a server') }}
                                <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                            </a>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($servers as $server)
                                @php
                                    $imageCapable = $server->provider->supportsImageSnapshots();
                                    $imaged = $imagedServerIds->contains($server->id);
                                    $latest = $latestByServer->get($server->id);
                                    $count = (int) ($imageCounts[$server->id] ?? 0);
                                    $ownSchedules = $schedulesByTarget->get($server->id) ?? collect();
                                    $schedule = $ownSchedules->first();
                                    $next = $schedule ? ($nextRuns[$schedule->id] ?? null) : null;
                                    $trend = $trends[$server->id] ?? [];
                                    $trendMax = $trend === [] ? 0 : max($trend);
                                @endphp

                                {{-- Servers are ordered capable-first, so this is the
                                     one boundary between "you can act on this" and
                                     "this provider exposes no image API". --}}
                                @if (! $imageCapable && ($loop->first || $servers[$loop->index - 1]->provider->supportsImageSnapshots()))
                                    <li wire:key="incapable-divider" class="flex items-center gap-3 bg-brand-sand/20 px-3 py-1.5 sm:px-4">
                                        <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('No image API on their provider') }}</span>
                                        <span class="h-px flex-1 bg-brand-ink/10"></span>
                                        <span class="hidden text-2xs text-brand-mist sm:inline">{{ __('protect these with dumps and archives') }}</span>
                                        <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $metrics['incapable'] }}</span>
                                    </li>
                                @endif

                                <li
                                    wire:key="server-{{ $server->id }}"
                                    @class([
                                        'group grid gap-x-4 gap-y-3 border-l-[3px] px-3 py-3 transition-colors hover:bg-brand-sand/15 sm:px-4',
                                        'lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1.1fr)_minmax(0,1fr)_auto_auto] lg:items-center',
                                        'border-brand-sage' => $imageCapable && $imaged,
                                        'border-brand-gold' => $imageCapable && ! $imaged,
                                        'border-transparent' => ! $imageCapable,
                                    ])
                                >
                                    {{-- Identity --}}
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span @class([
                                            'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1',
                                            'bg-brand-forest/12 text-brand-forest ring-brand-forest/20' => $imageCapable && $imaged,
                                            'bg-brand-gold/20 text-amber-800 ring-brand-gold/30' => $imageCapable && ! $imaged,
                                            'bg-brand-sand/50 text-brand-mist ring-brand-ink/10' => ! $imageCapable,
                                        ])>
                                            <x-heroicon-o-server class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-ink">
                                                <a href="{{ route('servers.snapshots', $server) }}" wire:navigate class="hover:text-brand-forest hover:underline">
                                                    {{ $server->name }}
                                                </a>
                                            </p>
                                            <p class="truncate text-xs text-brand-moss">
                                                {{ $server->provider?->label() ?? \Illuminate\Support\Str::title((string) $server->provider?->value) }}
                                                @if ($latest?->region)
                                                    <span class="text-brand-mist">· {{ $latest->region }}</span>
                                                @endif
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Coverage state, or the recurring policy once
                                         one exists (M2). --}}
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
                                            </div>
                                            <p class="mt-1 truncate font-mono text-2xs text-brand-mist">
                                                {{ $schedule->cron_expression }}
                                                @if ($next)
                                                    <span class="font-sans text-brand-moss">· {{ __('next in :when', [
                                                        'when' => $next->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true),
                                                    ]) }}</span>
                                                @endif
                                            </p>
                                        @elseif ($imaged)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/20 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-brand-forest">
                                                <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                                {{ trans_choice(':count image|:count images', $count, ['count' => $count]) }}
                                            </span>
                                        @elseif ($imageCapable)
                                            <a
                                                href="{{ route('servers.snapshots', $server) }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-brand-ink/20 px-2.5 py-1 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-forest/40 hover:bg-white hover:text-brand-forest"
                                            >
                                                <x-heroicon-m-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Never imaged') }}
                                            </a>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-brand-sand/40 px-2.5 py-1 text-xs font-medium text-brand-mist">
                                                <x-heroicon-m-no-symbol class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Provider has no image API') }}
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Newest image --}}
                                    <div class="min-w-0 text-xs">
                                        @if ($latest)
                                            @php
                                                $latestLabel = match ($latest->status) {
                                                    \App\Models\ServerImage::STATUS_COMPLETED => __('Ready'),
                                                    \App\Models\ServerImage::STATUS_FAILED => __('Failed'),
                                                    default => __('Creating'),
                                                };
                                            @endphp
                                            <p class="truncate text-brand-ink">
                                                <span @class([
                                                    'font-medium',
                                                    'text-brand-rust' => $latest->status === \App\Models\ServerImage::STATUS_FAILED,
                                                ])>{{ $latest->created_at->diffForHumans(short: true) }}</span>
                                                @if ($latest->bytes)
                                                    <span class="font-mono tabular-nums text-brand-moss">· {{ \Illuminate\Support\Number::fileSize((int) $latest->bytes) }}</span>
                                                @endif
                                            </p>
                                            <p class="mt-0.5 truncate text-brand-mist" title="{{ $latest->name }}">
                                                {{ $latestLabel }} · {{ $latest->name }}
                                            </p>
                                        @else
                                            {{-- The coverage column already says
                                                 "Never imaged"; repeating it here
                                                 just doubles the same word. --}}
                                            <p class="text-brand-mist">—</p>
                                        @endif
                                    </div>

                                    {{-- Image-size trend. A disk that keeps growing is
                                         a restore-time and provider-bill signal long
                                         before it is a problem. --}}
                                    <div class="hidden lg:block">
                                        @if (count($trend) > 1)
                                            <div
                                                class="flex h-8 w-24 items-end gap-px"
                                                title="{{ trans_choice('Last :count image size|Last :count image sizes', count($trend), ['count' => count($trend)]) }}"
                                                role="img"
                                                aria-label="{{ __('Recent image sizes') }}"
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

                                    {{-- Actions. Capture lives on the server workspace
                                         by decision, so this links rather than
                                         duplicating the button. --}}
                                    <div class="flex flex-wrap items-center gap-1.5 lg:justify-end">
                                        @if ($imageCapable)
                                            <a
                                                href="{{ route('servers.snapshots', $server) }}"
                                                wire:navigate
                                                class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                                            >
                                                <x-heroicon-o-camera class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ $imaged ? __('Take image') : __('Take first image') }}
                                            </a>
                                            @if ($schedule)
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
                                        <a
                                            href="{{ route('servers.snapshots', $server) }}"
                                            wire:navigate
                                            class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-brand-ink/10 bg-white text-brand-mist shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
                                            title="{{ __('Open on server') }}"
                                        >
                                            <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                        </a>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Policies whose target server no longer exists. Rare, but they
                     would still be evaluated, so they cannot be silently dropped. --}}
                @if ($orphanSchedules->isNotEmpty())
                    <section class="border-b border-brand-ink/10 bg-brand-rust/[0.05]">
                        <x-workspace-panel-head
                            dense
                            tone="amber"
                            class="border-b border-brand-ink/10"
                            icon="heroicon-o-exclamation-triangle"
                            :title="__('Orphaned image policies')"
                            :count="$orphanSchedules->count()"
                            :note="__('These point at a server dply can no longer find.')"
                        />
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($orphanSchedules as $schedule)
                                <li wire:key="orphan-{{ $schedule->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-l-[3px] border-brand-rust px-3 py-2.5 sm:px-4">
                                    <span class="shrink-0 text-sm font-semibold text-brand-ink">{{ $schedule->targetLabel() }}</span>
                                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-mist">{{ $schedule->cron_expression }}</span>
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

                {{-- Capture history, grouped by day. --}}
                <section class="border-b border-brand-ink/10">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-clipboard-document-list"
                        :title="__('Image history')"
                        :note="__('Newest 50 across every server in this workspace.')"
                    />

                    @if ($images->isEmpty())
                        <x-empty-state
                            borderless
                            icon="heroicon-o-camera"
                            :title="__('No images yet')"
                            :description="__('A full-disk image restores a whole machine — kernel, packages, config and data — in one step. Take the first from any server\'s Snapshots tab; pair it with a database dump, which is the artifact that is application-consistent.')"
                        >
                            @if ($uncovered->isNotEmpty())
                                <x-slot:actions>
                                    <a
                                        href="{{ route('servers.snapshots', $uncovered->first()) }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                                    >
                                        <x-heroicon-o-camera class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Image :server', ['server' => $uncovered->first()->name]) }}
                                    </a>
                                </x-slot:actions>
                            @endif
                        </x-empty-state>
                    @else
                        @foreach ($images->groupBy(fn ($image) => $image->created_at->toDateString()) as $day => $dayImages)
                            @php
                                $dayDate = $dayImages->first()->created_at;
                                $dayBytes = $dayImages->sum(fn ($image) => (int) $image->bytes);
                                $dayFailed = $dayImages->where('status', \App\Models\ServerImage::STATUS_FAILED)->count();
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
                                        {{ $dayImages->count() }} · {{ \Illuminate\Support\Number::fileSize($dayBytes) }}
                                    </span>
                                </div>

                                {{-- No row rules inside a day: the tight column of
                                     status nodes carries the rhythm. --}}
                                <ul class="py-1">
                                    @foreach ($dayImages as $image)
                                        @php
                                            $tone = match ($image->status) {
                                                \App\Models\ServerImage::STATUS_COMPLETED => ['bg-brand-sage text-brand-cream', 'heroicon-m-check', __('Ready')],
                                                \App\Models\ServerImage::STATUS_FAILED => ['bg-brand-rust text-brand-cream', 'heroicon-m-x-mark', __('Failed')],
                                                default => ['bg-brand-gold text-brand-ink', 'heroicon-m-ellipsis-horizontal', __('Creating')],
                                            };
                                        @endphp
                                        <li wire:key="image-{{ $image->id }}" class="relative flex items-center gap-3 px-3 py-1.5 transition-colors hover:bg-brand-sand/20 sm:px-4">
                                            <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-[3px] ring-brand-cream {{ $tone[0] }}">
                                                <x-dynamic-component :component="$tone[1]" class="h-4 w-4" aria-hidden="true" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-brand-ink">
                                                    <span class="font-semibold">{{ $image->name }}</span>
                                                    <span class="text-brand-mist">{{ __('on') }}</span>
                                                    @if ($image->server)
                                                        <a href="{{ route('servers.snapshots', $image->server) }}" wire:navigate class="hover:text-brand-forest hover:underline">
                                                            {{ $image->server->name }}
                                                        </a>
                                                    @else
                                                        —
                                                    @endif
                                                </p>
                                                <p class="mt-0.5 truncate text-xs text-brand-mist">
                                                    <span @class([
                                                        'font-medium',
                                                        'text-brand-rust' => $image->status === \App\Models\ServerImage::STATUS_FAILED,
                                                        'text-brand-moss' => $image->status !== \App\Models\ServerImage::STATUS_FAILED,
                                                    ])>{{ $tone[2] }}</span>
                                                    · <span class="font-mono tabular-nums">{{ $image->bytes ? \Illuminate\Support\Number::fileSize((int) $image->bytes) : __('size unknown') }}</span>
                                                    · {{ \Illuminate\Support\Str::title($image->provider) }}
                                                    @if ($image->region)
                                                        · {{ $image->region }}
                                                    @endif
                                                    @if ($image->error_message)
                                                        · <span class="text-brand-rust">{{ \Illuminate\Support\Str::limit($image->error_message, 80) }}</span>
                                                    @endif
                                                </p>
                                            </div>
                                            <time
                                                class="shrink-0 font-mono text-xs tabular-nums text-brand-moss"
                                                datetime="{{ $image->created_at->toIso8601String() }}"
                                                title="{{ $image->created_at->format('Y-m-d H:i:s') }}"
                                            >
                                                {{ $image->created_at->diffForHumans(short: true) }}
                                            </time>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @endif
                </section>

                {{-- Images live in the customer's own provider account, so dply
                     stores no bytes for them and the provider bills them directly.
                     That's worth saying once, here, rather than in a docs page. --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss sm:px-4">
                    <p class="min-w-0 flex-1">
                        <span class="font-semibold text-brand-ink">{{ __('Where these live:') }}</span>
                        {{ __('images are stored in your own provider account and billed by them — dply keeps the index and the restore path. An image restores the machine; a database dump is the artifact that is application-consistent, so keep both.') }}
                    </p>
                    <a href="{{ route('backups.databases') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        {{ __('Pair with a dump') }}
                        <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            </x-profile-shell>
        @endif
    </div>
</div>
