@php
    $meta = $server->meta ?? [];
    $units = is_array($meta['manage_units'] ?? null) ? $meta['manage_units'] : [];
    $upgrades = $meta['inventory_upgradable_packages'] ?? null;
    $reboot = $meta['inventory_reboot_required'] ?? null;
    $checkedAt = $meta['inventory_checked_at'] ?? null;

    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
    ];

    $securityCount = 0;
    if (! empty($meta['inventory_upgradable_preview']) && is_string($meta['inventory_upgradable_preview'])) {
        foreach (explode("\n", $meta['inventory_upgradable_preview']) as $line) {
            if (preg_match('#/(\S+)\s+\S+\s+\S+#', $line, $m)) {
                if (preg_match('/-security|esm-/i', $m[1])) {
                    $securityCount++;
                }
            }
        }
    }

    $servicesTotal = count($units);
    $servicesRunning = 0;
    $servicesFailed = 0;
    foreach ($units as $u) {
        if (($u['active_state'] ?? null) === 'active') {
            $servicesRunning++;
        }
        if (($u['active_state'] ?? null) === 'failed') {
            $servicesFailed++;
        }
    }

    $extSnap = (string) ($meta['inventory_extended_snapshot'] ?? '');
    $extParts = $extSnap === '' ? [] : preg_split('/\R---\R/', $extSnap);
    $rootDiskLine = null;
    if (! empty($extParts[0])) {
        foreach (explode("\n", $extParts[0]) as $line) {
            if (preg_match('#\s/$#', $line)) {
                $rootDiskLine = preg_split('/\s+/', trim($line));
                break;
            }
        }
    }
    $memLine = null;
    if (! empty($extParts[2])) {
        foreach (explode("\n", $extParts[2]) as $line) {
            if (str_starts_with(trim($line), 'Mem:')) {
                $memLine = preg_split('/\s+/', trim($line));
                break;
            }
        }
    }
    $uptimeLine = ! empty($extParts[1]) ? trim($extParts[1]) : null;

    $hasAnyData = $checkedAt !== null;
    $reachable = $server->health_status === \App\Models\Server::HEALTH_REACHABLE;
    $unreachable = $server->health_status === \App\Models\Server::HEALTH_UNREACHABLE;

    $overall = 'ready';
    if (! $opsReady) {
        $overall = 'blocked';
    } elseif ($unreachable) {
        $overall = 'degraded';
    } elseif (! $hasAnyData) {
        $overall = 'stale';
    } elseif ($securityCount > 0 || $reboot === true) {
        $overall = 'attention';
    }

    $overallTone = match ($overall) {
        'blocked', 'stale' => $tonePalette['amber'],
        'degraded' => $tonePalette['rose'],
        'attention' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $rootDiskPct = ($rootDiskLine && count($rootDiskLine) >= 5) ? $rootDiskLine[4] : '—';
    $rootDiskUse = ($rootDiskLine && count($rootDiskLine) >= 3) ? $rootDiskLine[2].' / '.$rootDiskLine[1] : null;
    $memUsed = ($memLine && count($memLine) >= 4) ? $memLine[2].' / '.$memLine[1] : '—';

    // Numeric root-disk % for threshold coloring (e.g. "6%" -> 6).
    $rootPctNum = is_numeric(rtrim((string) $rootDiskPct, '%')) ? (int) rtrim((string) $rootDiskPct, '%') : null;

    // Per-metric status cards: tone drives the card color, sub is the small footnote.
    $statCardTones = [
        'emerald' => 'border-emerald-200 bg-emerald-50/60',
        'amber' => 'border-amber-200 bg-amber-50/60',
        'rose' => 'border-rose-200 bg-rose-50/60',
        'neutral' => 'border-brand-ink/10 bg-white',
    ];

    $overviewStats = [
        [
            'label' => __('Services running'),
            'value' => $hasAnyData ? $servicesRunning.' / '.$servicesTotal : '—',
            'sub' => $servicesFailed > 0 ? trans_choice(':n failed|:n failed', $servicesFailed, ['n' => $servicesFailed]) : ($hasAnyData ? __('All active') : __('No data')),
            'tone' => $servicesFailed > 0 ? 'rose' : ($hasAnyData && $servicesTotal > 0 ? 'emerald' : 'neutral'),
            'subTone' => $servicesFailed > 0 ? 'text-red-700 font-medium' : 'text-brand-mist',
        ],
        [
            'label' => __('Root disk'),
            'value' => $rootDiskPct,
            'sub' => $rootDiskUse ?: __('No data'),
            'tone' => $rootPctNum === null ? 'neutral' : ($rootPctNum >= 90 ? 'rose' : ($rootPctNum >= 80 ? 'amber' : 'emerald')),
            'subTone' => 'text-brand-mist',
        ],
        [
            'label' => __('Memory'),
            'value' => $memUsed,
            'sub' => $hasAnyData ? __('used / total') : __('No data'),
            'tone' => 'neutral',
            'subTone' => 'text-brand-mist',
        ],
        [
            'label' => __('Upgradable'),
            'value' => $hasAnyData ? (string) ($upgrades ?? '0') : '—',
            'sub' => __('packages'),
            'tone' => ! $hasAnyData ? 'neutral' : (((int) ($upgrades ?? 0)) > 0 ? 'amber' : 'emerald'),
            'subTone' => 'text-brand-mist',
        ],
        [
            'label' => __('Security updates'),
            'value' => $hasAnyData ? (string) $securityCount : '—',
            'sub' => $securityCount > 0 ? __('need attention') : ($hasAnyData ? __('up to date') : __('No data')),
            'tone' => ! $hasAnyData ? 'neutral' : ($securityCount > 0 ? 'rose' : 'emerald'),
            'subTone' => $securityCount > 0 ? 'text-red-700 font-medium' : 'text-brand-mist',
        ],
        [
            'label' => __('Reboot'),
            'value' => $reboot === true ? __('Pending') : ($reboot === false ? __('No') : '—'),
            'sub' => $reboot === true ? __('restart required') : ($reboot === false ? __('not required') : __('No data')),
            'tone' => $reboot === true ? 'amber' : ($reboot === false ? 'emerald' : 'neutral'),
            'subTone' => $reboot === true ? 'text-amber-800 font-medium' : 'text-brand-mist',
        ],
    ];
@endphp

<section class="space-y-6" aria-labelledby="manage-overview-title">
    <h2 id="manage-overview-title" class="sr-only">{{ __('Overview') }}</h2>

    {{-- Overall --}}
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $overallTone }}">
                        <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Manage overview') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                            @switch($overall)
                                @case('blocked')
                                    {{ __('SSH not ready for manage actions') }}
                                    @break
                                @case('degraded')
                                    {{ __('Server unreachable') }}
                                    @break
                                @case('stale')
                                    {{ __('No probe data yet') }}
                                    @break
                                @case('attention')
                                    {{ __('Attention needed — updates or reboot') }}
                                    @break
                                @default
                                    {{ __('Host state looks healthy') }}
                            @endswitch
                        </h3>
                        <p class="mt-1 text-sm text-brand-moss">
                            @if ($reachable)
                                {{ __('Reachable') }}
                            @elseif ($unreachable)
                                {{ __('Unreachable') }}
                            @else
                                {{ __('Health unknown') }}
                            @endif
                            @if ($uptimeLine)
                                · <span class="truncate" title="{{ $uptimeLine }}">{{ $uptimeLine }}</span>
                            @endif
                            @if ($checkedAt)
                                · {{ __('Refreshed :t', ['t' => \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans()]) }}
                            @endif
                        </p>
                    </div>
                </div>
                @if ($opsReady && ! $isDeployer)
                    <button
                        type="button"
                        wire:click="refreshServerInventoryDetails"
                        wire:loading.attr="disabled"
                        wire:target="refreshServerInventoryDetails"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                            {{ __('Refresh state') }}
                        </span>
                        <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Refreshing…') }}
                        </span>
                    </button>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 p-6 sm:grid-cols-3 sm:p-7 xl:grid-cols-6">
            @foreach ($overviewStats as $stat)
                <div @class(['rounded-2xl border px-4 py-3 shadow-sm', $statCardTones[$stat['tone']]])>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</p>
                    <p class="mt-1 truncate text-[11px] {{ $stat['subTone'] }}" title="{{ $stat['sub'] }}">{{ $stat['sub'] }}</p>
                </div>
            @endforeach
        </div>

        @if (! $hasAnyData && $opsReady && ! $isDeployer)
            <div class="border-t border-brand-ink/10 px-6 py-4 text-sm text-brand-moss sm:px-7">
                <p>{{ __('Run Refresh state to populate disk, memory, services, and package counts from the live host.') }}</p>
            </div>
        @endif
    </section>

    {{-- Quick actions card removed: it only duplicated buttons that live on their
         own subsystem tabs (nginx/php-fpm reload+restart → Services + Webserver;
         restart_redis → Services/Caches; apt_update + reboot → Updates/Patches).
         Overview is now read-only state; act on a subsystem from its tab above. --}}

    @if (! empty($recentActions) && $recentActions->count() > 0)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Activity') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent activity') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('The most recent manage actions queued for this server.') }}</p>
                </div>
            </div>
            <ul class="divide-y divide-brand-ink/10 px-6 py-2 text-sm sm:px-7">
                @foreach ($recentActions as $row)
                    @php
                        $statusTone = match ($row->status) {
                            'finished' => ['classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest', 'label' => __('Finished')],
                            'running' => ['classes' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-500', 'label' => __('Running')],
                            'queued' => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __('Queued')],
                            'failed' => ['classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600', 'label' => __('Failed')],
                            default => ['classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist', 'label' => __($row->status)],
                        };
                        $duration = $row->started_at && $row->finished_at
                            ? $row->started_at->diffForHumans($row->finished_at, ['short' => true, 'parts' => 1, 'syntax' => \Carbon\Carbon::DIFF_ABSOLUTE])
                            : null;
                    @endphp
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium {{ $statusTone['classes'] }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusTone['dot'] }}"></span>
                            {{ $statusTone['label'] }}
                        </span>
                        <span class="font-medium text-brand-ink">{{ $row->label }}</span>
                        @if ($duration)
                            <span class="text-xs text-brand-mist">{{ $duration }}</span>
                        @endif
                        @if ($row->error_message)
                            <span class="max-w-md truncate text-xs text-red-700" title="{{ $row->error_message }}">{{ $row->error_message }}</span>
                        @endif
                        <span class="ml-auto text-xs text-brand-moss">{{ $row->created_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($server->workspace)
        @feature('surface.projects')
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Project') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Project operations context') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('Manage actions on this server can affect the rest of the :project project. Use the project operations page for runbooks, activity review, and alert routing before making broader stack changes.', ['project' => $server->workspace->name]) }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-3">
                                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-semibold text-brand-forest hover:text-brand-sage">{{ __('Open project operations') }}</a>
                                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="text-sm font-semibold text-brand-forest hover:text-brand-sage">{{ __('Open project resources') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endfeature
    @endif
</section>
