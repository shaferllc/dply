@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];

    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="patches"
    :title="__('Patches')"
    :description="__('Pending apt updates, package inventory, apt actions, unattended-upgrades, and reboot guidance for this server.')"
>
    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Refresh scan re-runs the inventory probe over SSH. Apt actions queue in the background — output streams in the banner above. Run Refresh scan again after upgrades to update the package list.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        @include('livewire.partials.console-action-banner-static', [
            'run' => $patchConsoleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])

        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view patch state but cannot run apt actions or change unattended-upgrades settings.') }}</p>
                    </div>
                </div>
            </section>
        @endif

        @if (! $opsReady)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before apt actions work.') }}</p>
                    </div>
                </div>
            </section>
        @endif
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overall') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                        @switch($report['overall'])
                            @case('critical') {{ __('Action needed') }} @break
                            @case('warning') {{ __('Review updates') }} @break
                            @default {{ __('Up to date') }}
                        @endswitch
                    </h2>
                    <p class="mt-1 text-sm text-brand-moss">
                        @if ($report['inventory']['checked_at'])
                            {{ __('Last scan :time', ['time' => $report['inventory']['checked_at']->diffForHumans()]) }}
                            @if ($report['inventory']['stale'])
                                · <span class="font-medium text-amber-800">{{ __('stale') }}</span>
                            @endif
                        @else
                            {{ __('No inventory scan on record yet.') }}
                        @endif
                        @if ($report['os']['pretty'])
                            · {{ $report['os']['pretty'] }}
                        @endif
                    </p>
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
                            {{ __('Refresh scan') }}
                        </span>
                        <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                            {{ __('Scanning…') }}
                        </span>
                    </button>
                @endif
            </div>

            @if ($report['alert_count'] > 0)
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['alerts'] as $alert)
                        @php
                            $alertTone = match ($alert['severity']) {
                                'critical' => $tonePalette['rose'],
                                'warning' => $tonePalette['amber'],
                                default => $tonePalette['sage'],
                            };
                        @endphp
                        <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $alertTone }}">
                                    @if ($alert['severity'] === 'critical')
                                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                                </div>
                            </div>
                            @if ($alert['href'] && $alert['link_label'])
                                <a href="{{ $alert['href'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    {{ $alert['link_label'] }}
                                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
                    {{ __('No patch or reboot alerts from the latest inventory scan.') }}
                </div>
            @endif
        </section>

        @if ($opsReady && ! $isDeployer)
            @php
                $aptActionGroups = [
                    __('Prepare') => [
                        'apt_update' => [
                            'icon' => 'heroicon-o-arrow-path',
                            'tone' => 'sage',
                        ],
                    ],
                    __('Apply updates') => [
                        'apt_upgrade' => [
                            'icon' => 'heroicon-o-arrow-trending-up',
                            'tone' => 'amber',
                            'destructive' => true,
                        ],
                        'apt_dist_upgrade' => [
                            'icon' => 'heroicon-o-rocket-launch',
                            'tone' => 'rose',
                            'destructive' => true,
                        ],
                    ],
                    __('Housekeeping') => [
                        'apt_autoremove' => [
                            'icon' => 'heroicon-o-trash',
                            'tone' => 'neutral',
                        ],
                        'apt_clean' => [
                            'icon' => 'heroicon-o-archive-box',
                            'tone' => 'neutral',
                        ],
                    ],
                ];

                $aptToneStyles = [
                    'sage' => [
                        'icon' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
                        'card' => 'border-brand-ink/10 bg-white hover:border-brand-sage/35 hover:bg-brand-sage/5',
                        'cta' => 'text-brand-forest',
                    ],
                    'amber' => [
                        'icon' => 'bg-amber-50 text-amber-900 ring-amber-200',
                        'card' => 'border-amber-200/80 bg-amber-50/30 hover:border-amber-300 hover:bg-amber-50/70',
                        'cta' => 'text-amber-900',
                    ],
                    'rose' => [
                        'icon' => 'bg-rose-50 text-rose-800 ring-rose-200',
                        'card' => 'border-rose-200/70 bg-rose-50/20 hover:border-rose-300 hover:bg-rose-50/50',
                        'cta' => 'text-rose-800',
                    ],
                    'neutral' => [
                        'icon' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
                        'card' => 'border-brand-ink/10 bg-brand-cream/20 hover:border-brand-ink/20 hover:bg-brand-sand/30',
                        'cta' => 'text-brand-ink',
                    ],
                ];
            @endphp

            <section id="patch-apt-actions" class="dply-card scroll-mt-24 overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Actions') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Apt actions') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Queued over SSH — output streams in the banner above. Run Refresh scan after upgrades to update the package list.') }}
                        </p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sage/15 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-server-stack class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Debian / Ubuntu') }}
                    </span>
                </div>

                <div class="space-y-8 px-6 py-6 sm:px-7">
                    @foreach ($aptActionGroups as $groupLabel => $groupActions)
                        <div>
                            <h3 class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $groupLabel }}</h3>
                            <div @class([
                                'mt-3 grid gap-3',
                                'max-w-md' => count($groupActions) === 1,
                                'sm:grid-cols-2' => count($groupActions) === 2,
                                'sm:grid-cols-2 lg:grid-cols-3' => count($groupActions) > 2,
                            ])>
                                @foreach ($groupActions as $key => $meta)
                                    @if (! empty($serviceActions[$key]))
                                        @php
                                            $a = $serviceActions[$key];
                                            $tone = $aptToneStyles[$meta['tone']] ?? $aptToneStyles['neutral'];
                                            $destructive = (bool) ($meta['destructive'] ?? false);
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('runAllowlistedManageAction', ['{{ $key }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $destructive ? 'true' : 'false' }})"
                                            class="group flex h-full w-full flex-col items-start gap-4 rounded-2xl border p-4 text-left shadow-sm transition duration-150 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40 {{ $tone['card'] }}"
                                        >
                                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tone['icon'] }}">
                                                @switch($meta['icon'])
                                                    @case('heroicon-o-arrow-path')
                                                        <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                                                        @break
                                                    @case('heroicon-o-arrow-trending-up')
                                                        <x-heroicon-o-arrow-trending-up class="h-5 w-5" aria-hidden="true" />
                                                        @break
                                                    @case('heroicon-o-rocket-launch')
                                                        <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                                                        @break
                                                    @case('heroicon-o-trash')
                                                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                                                        @break
                                                    @case('heroicon-o-archive-box')
                                                        <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                                                        @break
                                                @endswitch
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-sm font-semibold text-brand-ink">{{ $a['label'] }}</span>
                                                @if (! empty($a['description']))
                                                    <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ $a['description'] }}</span>
                                                @endif
                                            </span>
                                            <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $tone['cta'] }}">
                                                <span class="opacity-70 transition group-hover:opacity-100">{{ __('Run action') }}</span>
                                                <x-heroicon-m-chevron-right class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" aria-hidden="true" />
                                            </span>
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="dply-card overflow-hidden lg:col-span-2">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Inventory') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Packages & OS detection') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Inventory probe snapshot — read-only package list, not an install plan.') }}
                        </p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                        <x-heroicon-o-eye class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Read-only') }}
                    </span>
                </div>

                <div class="space-y-6 px-6 py-5 sm:px-7">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Upgradable') }}</p>
                            <p class="mt-2 flex items-baseline gap-2">
                                <span class="text-3xl font-semibold tabular-nums text-brand-ink">{{ $report['packages']['total'] ?? '—' }}</span>
                                @if ($report['packages']['total'] !== null)
                                    <span class="text-xs text-brand-moss">{{ __('packages') }}</span>
                                @endif
                            </p>
                        </div>

                        <div @class([
                            'rounded-2xl border p-4 shadow-sm',
                            'border-red-200/80 bg-red-50/40' => ($report['packages']['security'] ?? 0) > 0,
                            'border-brand-ink/10 bg-white' => ($report['packages']['security'] ?? 0) === 0,
                        ])>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Security') }}</p>
                            <p class="mt-2 flex items-baseline gap-2">
                                <span @class([
                                    'text-3xl font-semibold tabular-nums',
                                    'text-red-800' => ($report['packages']['security'] ?? 0) > 0,
                                    'text-brand-ink' => ($report['packages']['security'] ?? 0) === 0,
                                ])>{{ $report['packages']['security'] ?? 0 }}</span>
                                <span class="text-xs text-brand-moss">{{ __('flagged') }}</span>
                            </p>
                        </div>

                        <div @class([
                            'rounded-2xl border p-4 shadow-sm',
                            'border-amber-200/80 bg-amber-50/40' => $report['reboot']['required'] === true,
                            'border-brand-ink/10 bg-white' => $report['reboot']['required'] !== true,
                        ])>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Reboot pending') }}</p>
                            <p class="mt-2 text-lg font-semibold text-brand-ink">
                                @if ($report['reboot']['required'] === true)
                                    <span class="text-amber-900">{{ __('Yes') }}</span>
                                @elseif ($report['reboot']['required'] === false)
                                    <span class="text-emerald-700">{{ __('No') }}</span>
                                @else
                                    <span class="text-brand-moss">—</span>
                                @endif
                            </p>
                        </div>

                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Last apt update') }}</p>
                            <p class="mt-2 text-sm font-semibold leading-snug text-brand-ink">
                                @if ($report['inventory']['last_apt_update'])
                                    {{ $report['inventory']['last_apt_update']->diffForHumans() }}
                                @else
                                    <span class="text-brand-moss">{{ __('Unknown') }}</span>
                                @endif
                            </p>
                            @if ($report['inventory']['last_apt_update'])
                                <p class="mt-1 text-[11px] text-brand-moss">
                                    {{ $report['inventory']['last_apt_update']->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-col gap-4 rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:flex-row sm:items-start sm:p-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <dl class="grid min-w-0 flex-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('OS label (in Dply)') }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-brand-ink">
                                    {{ $osVersions[$report['os']['label'] ?? ''] ?? ($report['os']['label'] ?? '—') }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Detected on server') }}</dt>
                                <dd class="mt-1 text-sm text-brand-ink">
                                    @if ($report['os']['pretty'])
                                        <span class="font-semibold">{{ $report['os']['pretty'] }}</span>
                                        @if ($report['os']['key'])
                                            <span class="block text-xs text-brand-moss">{{ $osVersions[$report['os']['key']] ?? $report['os']['key'] }}</span>
                                        @endif
                                    @else
                                        <span class="text-brand-moss">—</span>
                                    @endif
                                </dd>
                                @if ($opsReady && ! $isDeployer && $report['os']['key'] && ($report['os']['label'] ?? '') !== ($report['os']['key'] ?? ''))
                                    <button
                                        type="button"
                                        wire:click="applyDetectedOsFromInventory"
                                        class="mt-2 inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                                    >{{ __('Use detected label') }}</button>
                                @endif
                            </div>
                        </dl>
                    </div>

                    @if (count($report['packages']['rows']) > 0)
                        <div x-data="{ filter: 'all', q: '' }">
                            <div class="flex flex-col gap-4 border-b border-brand-ink/10 pb-4 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Outdated packages') }}</h3>
                                    <p class="mt-1 text-xs text-brand-moss">
                                        {{ trans_choice(':count listed from apt.|:count listed from apt.', count($report['packages']['rows']), ['count' => count($report['packages']['rows'])]) }}
                                        @if ($report['packages']['security'] > 0)
                                            <span class="font-medium text-red-700">{{ trans_choice(':n security update.|:n security updates.', $report['packages']['security'], ['n' => $report['packages']['security']]) }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="inline-flex rounded-xl border border-brand-ink/15 bg-white p-1 text-xs shadow-sm">
                                        <button type="button" x-on:click="filter = 'all'" :class="filter === 'all' ? 'bg-brand-sage/15 text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink'" class="rounded-lg px-3 py-1.5 font-semibold transition">{{ __('All') }}</button>
                                        <button type="button" x-on:click="filter = 'security'" :class="filter === 'security' ? 'bg-red-100 text-red-800 shadow-sm' : 'text-brand-moss hover:text-brand-ink'" class="rounded-lg px-3 py-1.5 font-semibold transition">{{ __('Security') }}</button>
                                    </div>
                                    <label class="relative block">
                                        <span class="sr-only">{{ __('Filter by name') }}</span>
                                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" aria-hidden="true" />
                                        <input
                                            type="search"
                                            x-model="q"
                                            placeholder="{{ __('Filter packages…') }}"
                                            class="w-full min-w-[11rem] rounded-xl border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 sm:w-52"
                                        />
                                    </label>
                                </div>
                            </div>

                            <div class="mt-4 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                                <div class="max-h-[32rem] overflow-auto">
                                    <table class="min-w-full text-left text-xs">
                                        <thead class="sticky top-0 z-10 bg-brand-cream/95 text-[11px] uppercase tracking-[0.12em] text-brand-mist backdrop-blur-sm">
                                            <tr class="border-b border-brand-ink/10">
                                                <th class="px-4 py-3 font-semibold">{{ __('Package') }}</th>
                                                <th class="px-4 py-3 font-semibold">{{ __('Current') }}</th>
                                                <th class="px-4 py-3 font-semibold">{{ __('New') }}</th>
                                                <th class="px-4 py-3 font-semibold">{{ __('Source') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-brand-ink/5">
                                            @foreach ($report['packages']['rows'] as $row)
                                                <tr
                                                    x-show="(filter === 'all' || {{ $row['is_security'] ? 'true' : 'false' }}) && (q === '' || @js($row['name']).toLowerCase().includes(q.toLowerCase()))"
                                                    @class([
                                                        'bg-red-50/30' => $row['is_security'],
                                                        'hover:bg-brand-sand/20' => ! $row['is_security'],
                                                        'hover:bg-red-50/50' => $row['is_security'],
                                                    ])
                                                >
                                                    <td class="px-4 py-3 align-top">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="font-mono text-sm font-medium text-brand-ink">{{ $row['name'] }}</span>
                                                            @if ($row['is_security'])
                                                                <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-red-800 ring-1 ring-red-200">{{ __('Security') }}</span>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td class="max-w-[14rem] px-4 py-3 align-top">
                                                        <span class="block truncate font-mono text-[11px] leading-relaxed text-brand-moss" title="{{ $row['current_version'] ?? '—' }}">{{ $row['current_version'] ?? '—' }}</span>
                                                    </td>
                                                    <td class="max-w-[14rem] px-4 py-3 align-top">
                                                        <span class="block truncate font-mono text-[11px] font-medium leading-relaxed text-brand-ink" title="{{ $row['new_version'] ?? '—' }}">{{ $row['new_version'] ?? '—' }}</span>
                                                    </td>
                                                    <td class="px-4 py-3 align-top">
                                                        <div class="flex flex-wrap gap-1">
                                                            @foreach (explode(',', (string) ($row['sources'] ?? '')) as $sourcePart)
                                                                @php $sourcePart = trim($sourcePart); @endphp
                                                                @if ($sourcePart !== '')
                                                                    <span @class([
                                                                        'inline-flex rounded-md px-1.5 py-0.5 font-mono text-[10px] ring-1',
                                                                        'bg-red-50 text-red-800 ring-red-200' => str_contains($sourcePart, 'security'),
                                                                        'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! str_contains($sourcePart, 'security'),
                                                                    ])>{{ $sourcePart }}</span>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            @if ($report['packages']['preview_truncated'])
                                <p class="mt-3 flex items-start gap-2 rounded-xl border border-amber-200/80 bg-amber-50/50 px-3 py-2 text-xs text-amber-950">
                                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Package preview was truncated on the server — run Refresh scan for the full list.') }}
                                </p>
                            @endif
                        </div>
                    @elseif (! $report['supports_apt'] && ! $report['inventory']['never_scanned'])
                        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-5 py-8 text-center">
                            <p class="text-sm text-brand-moss">{{ __('apt inventory is not available on this OS yet — only Debian/Ubuntu hosts are supported in v1.') }}</p>
                        </div>
                    @elseif ($report['inventory']['never_scanned'])
                        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-5 py-8 text-center">
                            <p class="text-sm text-brand-moss">{{ __('No inventory scan on record yet — run Refresh scan to populate package data.') }}</p>
                        </div>
                    @endif

                    @php
                        $extSections = [];
                        if (! empty($extendedSnapshot)) {
                            $parts = preg_split('/\R---\R/', $extendedSnapshot);
                            $extLabels = [
                                __('Disk usage (df -h)'),
                                __('Uptime / load'),
                                __('Memory (free -h)'),
                                __('fail2ban'),
                            ];
                            foreach ($parts as $i => $body) {
                                $body = trim((string) $body);
                                if ($body === '') {
                                    continue;
                                }
                                $extSections[] = [
                                    'label' => $extLabels[$i] ?? __('Section :n', ['n' => $i + 1]),
                                    'body' => $body,
                                ];
                            }
                        }
                    @endphp

                    @if (! empty($extSections))
                        <div id="patch-host-snapshot">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Host snapshot') }}</h3>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Disk, memory, uptime, and fail2ban from the extended inventory probe.') }}</p>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach ($extSections as $section)
                                    <div class="rounded-xl border border-brand-ink/10 bg-white p-3 shadow-sm">
                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $section['label'] }}</div>
                                        <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono text-[11px] leading-relaxed text-brand-ink">{{ $section['body'] }}</pre>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div id="patch-scan-settings" class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="max-w-xl">
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Scan settings') }}</h3>
                                <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                    {{ __('Basic: OS + apt list. Extended: also captures disk, memory, uptime, and fail2ban (shown above when available).') }}
                                </p>
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
                                        {{ __('Refresh scan') }}
                                    </span>
                                    <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                                        {{ __('Scanning…') }}
                                    </span>
                                </button>
                            @endif
                        </div>

                        @if (! $isDeployer)
                            <form wire:submit="saveInventoryDepthPreference" class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div class="min-w-[min(100%,20rem)] flex-1">
                                    <label for="patch-inventory-depth" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Scan depth') }}</label>
                                    <select
                                        id="patch-inventory-depth"
                                        wire:model="settingsInventoryDepth"
                                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    >
                                        @foreach ($inventoryDepths as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('settingsInventoryDepth')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                                <x-primary-button type="submit" class="!py-2.5" wire:loading.attr="disabled">{{ __('Save depth') }}</x-primary-button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Reboot') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Reboot & uptime') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Kernel reboot flag and live uptime from the extended probe.') }}</p>
                    </div>
                </div>
                <div class="space-y-4 px-6 py-4 sm:px-7">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($report['reboot']['required'] === true)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900">
                                {{ __('Reboot required') }}
                            </span>
                        @elseif ($report['reboot']['required'] === false)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-emerald-800">
                                {{ __('No reboot pending') }}
                            </span>
                        @else
                            <span class="text-sm text-brand-moss">{{ __('Reboot status unknown — refresh the inventory scan.') }}</span>
                        @endif
                    </div>

                    @if ($report['uptime']['raw'])
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 font-mono text-xs text-brand-ink">
                            {{ $report['uptime']['raw'] }}
                        </div>
                    @endif

                    @if ($report['reboot']['required'] === true)
                        <div class="flex flex-wrap gap-3">
                            @feature('workspace.server_maintenance')
                                <a href="{{ route('servers.maintenance', $server) }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    {{ __('Plan maintenance window') }}
                                </a>
                            @endfeature
                            @if ($opsReady && ! $isDeployer && ! empty($dangerousActions['reboot']))
                                @php $r = $dangerousActions['reboot']; @endphp
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('runAllowlistedManageAction', ['reboot'], @js($r['label']), @js($r['confirm']), @js($r['label']), true)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-900 hover:bg-red-100"
                                >
                                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                                    {{ $r['label'] }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            <section id="patch-unattended-upgrades" class="dply-card scroll-mt-24 overflow-hidden">
                @php
                    $unattendedEnabled = $report['unattended']['enabled'];
                    $unattendedPresent = $report['unattended']['present'];

                    $statusPill = match (true) {
                        ! $unattendedPresent => ['label' => __('Not installed'), 'classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist'],
                        $unattendedEnabled === true => ['label' => __('Enabled'), 'classes' => 'bg-brand-sage/15 text-brand-forest', 'dot' => 'bg-brand-forest'],
                        $unattendedEnabled === false => ['label' => __('Disabled'), 'classes' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-500'],
                        default => ['label' => __('Unknown'), 'classes' => 'bg-brand-ink/10 text-brand-moss', 'dot' => 'bg-brand-mist'],
                    };

                    $statusSummary = match (true) {
                        ! $unattendedPresent => __('The unattended-upgrades package was not detected on the last scan.'),
                        $unattendedEnabled === true => __('Security updates can apply automatically in the background.'),
                        $unattendedEnabled === false => __('Automatic security updates are turned off on this server.'),
                        default => __('Enable state could not be determined — run Refresh scan, then enable or disable below.'),
                    };

                    $showEnableAction = $unattendedPresent && $unattendedEnabled !== true;
                    $showDisableAction = $unattendedPresent && $unattendedEnabled === true;
                @endphp

                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Automatic') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Unattended-upgrades') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Server-side automatic security updates (Debian/Ubuntu).') }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $statusPill['classes'] }}">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusPill['dot'] }}"></span>
                        {{ $statusPill['label'] }}
                    </span>
                </div>

                <div class="space-y-5 px-6 py-5 sm:px-7">
                    <div @class([
                        'flex items-start gap-4 rounded-2xl border p-4',
                        'border-brand-sage/30 bg-brand-sage/5' => $unattendedEnabled === true,
                        'border-amber-200/70 bg-amber-50/30' => $unattendedEnabled === false,
                        'border-brand-ink/10 bg-brand-sand/15' => $unattendedEnabled !== true && $unattendedEnabled !== false,
                    ])>
                        <span @class([
                            'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1',
                            'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => $unattendedEnabled === true,
                            'bg-amber-50 text-amber-900 ring-amber-200' => $unattendedEnabled === false,
                            'bg-white text-brand-moss ring-brand-ink/10' => $unattendedEnabled !== true && $unattendedEnabled !== false,
                        ])>
                            @if ($unattendedEnabled === true)
                                <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                            @elseif ($unattendedEnabled === false)
                                <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                            @else
                                <x-heroicon-o-question-mark-circle class="h-5 w-5" aria-hidden="true" />
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-brand-ink">{{ $statusPill['label'] }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $statusSummary }}</p>
                        </div>
                    </div>

                    @if (! empty($report['unattended']['snippet']))
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('/etc/apt/apt.conf.d/20auto-upgrades') }}</p>
                            <pre class="mt-2 max-h-32 overflow-auto rounded-xl border border-brand-ink/10 bg-brand-ink/[0.03] p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ $report['unattended']['snippet'] }}</pre>
                        </div>
                    @endif

                    @if ($opsReady && ! $isDeployer)
                        @if ($showEnableAction && ! empty($serviceActions['unattended_upgrades_enable']))
                            @php $enableAction = $serviceActions['unattended_upgrades_enable']; @endphp
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedManageAction', ['unattended_upgrades_enable'], @js($enableAction['label']), @js($enableAction['confirm']), @js($enableAction['label']), false)"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-forest/20 bg-brand-forest px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-ink sm:w-auto"
                            >
                                <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                                {{ $enableAction['label'] }}
                            </button>
                        @elseif ($showDisableAction && ! empty($serviceActions['unattended_upgrades_disable']))
                            @php $disableAction = $serviceActions['unattended_upgrades_disable']; @endphp
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedManageAction', ['unattended_upgrades_disable'], @js($disableAction['label']), @js($disableAction['confirm']), @js($disableAction['label']), false)"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-semibold text-amber-950 shadow-sm transition hover:bg-amber-50 sm:w-auto"
                            >
                                <x-heroicon-o-no-symbol class="h-4 w-4" aria-hidden="true" />
                                {{ $disableAction['label'] }}
                            </button>
                        @elseif (! $unattendedPresent)
                            <p class="text-xs leading-relaxed text-brand-moss">{{ __('Install the unattended-upgrades package on the server, then run Refresh scan to manage it here.') }}</p>
                        @endif
                    @endif

                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:p-5">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-brand-moss ring-1 ring-brand-ink/10">
                                <x-heroicon-o-calendar-days class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Cadence preference') }}</p>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-mist">{{ __('Recorded in Dply for future scheduling — does not change unattended-upgrades on the server today.') }}</p>
                            </div>
                        </div>

                        <form wire:submit="saveManageMetadata" class="mt-4 space-y-4">
                            <div>
                                <label for="patch_auto_updates_interval" class="sr-only">{{ __('Cadence preference') }}</label>
                                <select
                                    id="patch_auto_updates_interval"
                                    wire:model="manage_auto_updates_interval"
                                    @disabled($isDeployer)
                                    class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                                >
                                    @foreach ($autoUpdateIntervals as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('manage_auto_updates_interval')
                                    <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                @enderror
                            </div>
                            <x-primary-button type="submit" class="!py-2.5" :disabled="$isDeployer">{{ __('Save preference') }}</x-primary-button>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</x-server-workspace-layout>
