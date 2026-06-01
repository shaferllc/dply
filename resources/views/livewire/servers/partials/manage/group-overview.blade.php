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
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
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

        <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['label' => __('Services running'), 'value' => $hasAnyData ? $servicesRunning.' / '.$servicesTotal : '—'],
                ['label' => __('Root disk'), 'value' => $rootDiskPct],
                ['label' => __('Memory'), 'value' => $memUsed],
                ['label' => __('Upgradable'), 'value' => $hasAnyData ? (string) ($upgrades ?? '0') : '—'],
                ['label' => __('Security updates'), 'value' => $hasAnyData ? (string) $securityCount : '—'],
                ['label' => __('Reboot'), 'value' => $reboot === true ? __('Pending') : ($reboot === false ? __('No') : '—')],
            ] as $stat)
                <div class="bg-white px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                    <p class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</p>
                    @if ($stat['label'] === __('Root disk') && $rootDiskUse)
                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ $rootDiskUse }}</p>
                    @endif
                    @if ($stat['label'] === __('Services running') && $servicesFailed > 0)
                        <p class="mt-0.5 text-[11px] font-medium text-red-700">
                            {{ trans_choice(':n failed|:n failed', $servicesFailed, ['n' => $servicesFailed]) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        @if (! $hasAnyData && $opsReady && ! $isDeployer)
            <div class="border-t border-brand-ink/10 px-6 py-4 text-sm text-brand-moss sm:px-7">
                <p>{{ __('Run Refresh state to populate disk, memory, services, and package counts from the live host.') }}</p>
            </div>
        @endif
    </section>

    {{-- Manage sections --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['slug' => 'tools', 'label' => __('Tools'), 'desc' => __('mise runtimes, Composer, Git, Docker'), 'icon' => 'heroicon-o-wrench-screwdriver', 'tone' => $tonePalette['sage']],
            ['slug' => 'configuration', 'label' => __('Configuration'), 'desc' => __('Config previews and clone server'), 'icon' => 'heroicon-o-document-text', 'tone' => $tonePalette['mist']],
            ['slug' => 'updates', 'label' => __('Updates'), 'desc' => __('Package upgrades and unattended-upgrades'), 'icon' => 'heroicon-o-arrow-path', 'tone' => $tonePalette['amber']],
            ['slug' => 'danger', 'label' => __('Danger'), 'desc' => __('Reboot, swap, and destructive actions'), 'icon' => 'heroicon-o-exclamation-triangle', 'tone' => $tonePalette['rose']],
        ] as $link)
            <a
                href="{{ route('servers.manage', ['server' => $server, 'section' => $link['slug']]) }}"
                wire:navigate
                class="dply-card block overflow-hidden transition hover:border-brand-sage/40 hover:bg-brand-sand/10"
            >
                <div class="flex items-start gap-3 px-5 py-4">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 {{ $link['tone'] }}">
                        <x-dynamic-component :component="$link['icon']" class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ $link['label'] }}</h3>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ $link['desc'] }}</p>
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    {{-- Related workspaces --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('servers.services', $server) }}" wire:navigate class="dply-card block overflow-hidden p-5 transition hover:border-brand-sage/40 hover:bg-brand-sand/10">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Services') }}</h3>
            <p class="mt-1 text-xs text-brand-moss">{{ __('systemd inventory, restart/stop/start') }}</p>
        </a>
        @feature('workspace.patch_advisor')
            <a href="{{ route('servers.patches', $server) }}" wire:navigate class="dply-card block overflow-hidden p-5 transition hover:border-brand-sage/40 hover:bg-brand-sand/10">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Patches') }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Security and package patch advisor') }}</p>
            </a>
        @endfeature
        <a href="{{ route('servers.webserver', $server) }}" wire:navigate class="dply-card block overflow-hidden p-5 transition hover:border-brand-sage/40 hover:bg-brand-sand/10">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Webserver') }}</h3>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Nginx/Caddy/Apache engine workspace') }}</p>
        </a>
    </div>

    @if ($opsReady && ! $isDeployer)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Actions') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Quick actions') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('The most-used actions, duplicated here for convenience. Each lives under its subsystem tab too.') }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 px-6 py-5 sm:px-7">
                @php
                    // Quick-actions list is gated server-side: WorkspaceManage::render() filters
                    // service_actions config keys against the systemd inventory so we don't
                    // surface "Reload NGINX" on a Valkey-only box. apt_update has no service
                    // prerequisite so it always passes through. Order here biases reload/restart
                    // before package + reboot for muscle-memory consistency.
                    $quickActionOrder = ['reload_nginx', 'restart_nginx', 'restart_php_fpm', 'reload_php_fpm', 'restart_redis', 'apt_update'];
                    $orderedKeys = array_values(array_filter($quickActionOrder, fn ($k) => in_array($k, $quickActionKeys ?? [], true)));
                @endphp
                @forelse ($orderedKeys as $key)
                    @if (! empty($serviceActions[$key]))
                        @php $action = $serviceActions[$key]; @endphp
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $key }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), false)"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                            {{ $action['label'] }}
                        </button>
                    @endif
                @empty
                @endforelse
                @if (! empty($dangerousActions['reboot']))
                    @php $rebootAction = $dangerousActions['reboot']; @endphp
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedAction', ['reboot'], @js($rebootAction['label']), @js($rebootAction['confirm']), @js($rebootAction['label']), true)"
                        class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900 hover:bg-red-100"
                    >
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                        {{ $rebootAction['label'] }}
                    </button>
                @endif
            </div>
        </section>
    @endif

    @if (! empty($recentActions) && $recentActions->count() > 0)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </span>
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
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                        </span>
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
