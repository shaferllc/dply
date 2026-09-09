{{--
    Lazy-load skeleton for Metrics. Mirrors workspace-monitor.blade.php exactly:
    the dense panel head, then whichever of the three page states this server is
    actually in. Keep the two in step — this file previously mirrored the old
    merged card (tall icon-badge head + h-8 pill tab strip) that the dense
    x-workspace-panel-head + x-server-workspace-tablist replaced, so the card
    visibly resized and the tabs jumped when the real render swapped in.

    State matters: a server that isn't ops-ready, or that has no metrics agent
    installed, never renders the tab strip at all (see the @if chain in
    workspace-monitor.blade.php), so neither does its skeleton. Both flags come
    off the server row, same derivation as the page.
--}}
@php
    // Mirrors MonitorWorkspaceViewData ($pyOk) and
    // InteractsWithServerWorkspace::serverOpsReady().
    $m = $server->meta ?? [];
    $pyOk = (bool) ($m['monitoring_python_installed'] ?? false);
    $opsReady = $server->isReady()
        && $server->isVmHost()
        && filled($server->ip_address)
        && filled($server->ssh_private_key);

    // Mirrors currentUserIsDeployer() — deployers get no Alert thresholds band.
    $user = auth()->user();
    $isDeployer = $user !== null && ($user->currentOrganization()?->userIsDeployer($user) ?? false);

    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="monitor"
    :title="__('Metrics')"
    :description="null"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading metrics…') }}</span>

        {{-- The same dense head the real page renders. Title and note are known
             before load, so only the status chip and Operations pill pulse. --}}
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-chart-bar"
            :title="__('Metrics')"
            :note="__('Live usage, history, alert routing, and agent diagnostics.')"
        >
            <x-slot:actions>
                @if ($opsReady && $pyOk)
                    <span class="h-6 w-24 rounded-full {{ $bar }}" aria-hidden="true"></span>
                @endif
                @if ($server->workspace)
                    @feature('surface.projects')
                        <span class="h-6 w-24 rounded-md {{ $bar }}" aria-hidden="true"></span>
                    @endfeature
                @endif
            </x-slot:actions>
        </x-workspace-panel-head>

        @if (! $opsReady)
            {{-- Fully static on the real page — nothing to pulse. --}}
            <x-workspace-panel-head
                dense
                tone="amber"
                class="border-b border-brand-ink/10"
                icon="heroicon-o-clock"
                :title="__('Waiting on provisioning')"
                :note="__('Provisioning and SSH must be ready before metrics can be collected.')"
            />
        @elseif (! $pyOk)
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-arrow-down-tray"
                :title="__('Install monitor on this server')"
                :note="__('Dply provisions the metrics agent over SSH so this page can stream usage data.')"
            />
            <div class="px-3 py-2.5 sm:px-4">
                {{-- The three selling points are static copy; only the row under
                     them varies (probe banner / SSH failure / install console /
                     the install buttons), so one action-height stub stands in. --}}
                <ul class="space-y-1.5 text-xs text-brand-ink">
                    <li class="flex items-start gap-2.5">
                        <x-heroicon-o-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        <span>{{ __('Installs Python and the metrics agent over SSH') }}</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <x-heroicon-o-arrow-path class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        <span>{{ __('Updates charts on this page every minute') }}</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <x-heroicon-o-bell-alert class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        <span>{{ __('Feeds Insights for threshold alerts and digest emails') }}</span>
                    </li>
                </ul>
                <div class="mt-3 flex flex-wrap items-center gap-3" aria-hidden="true">
                    <span class="h-8 w-36 rounded-lg {{ $bar }}"></span>
                    <span class="h-8 w-32 rounded-lg {{ $bar }}"></span>
                </div>
            </div>
        @else
            {{-- Tab strip: same wrapper padding and the same tab metrics as
                 x-server-workspace-tab (rounded-lg px-2.5 py-1, leading-none).
                 Labels and icons are known, and monitor_workspace_tab always
                 boots to 'status', so the strip renders for real. --}}
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
                <nav class="dply-tablist-scroll flex w-full min-w-0 max-w-full flex-nowrap items-center gap-1 overflow-x-auto [&>*]:shrink-0">
                    @foreach ([
                        ['heroicon-o-chart-pie', __('Status')],
                        ['heroicon-o-chart-bar', __('History')],
                        ['heroicon-o-bell', __('Notifications')],
                        ['heroicon-o-wrench-screwdriver', __('Diagnostics')],
                    ] as $i => [$tabIcon, $tabLabel])
                        <span @class([
                            'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-semibold leading-none',
                            'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                            'text-brand-moss' => $i !== 0,
                        ])>
                            <x-dynamic-component :component="$tabIcon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $tabLabel }}
                        </span>
                    @endforeach
                </nav>
            </div>

            {{-- Status tab body. The amber "no notification routes" strip is
                 omitted — it needs a routing count we don't have here, and a
                 stub for a band that usually isn't there costs more than it
                 saves. --}}
            <div aria-hidden="true">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <h2 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                        <x-heroicon-o-chart-pie class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Monitor status') }}
                    </h2>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <div class="ml-auto flex shrink-0 items-center gap-1.5">
                        <span class="h-6 w-20 rounded-full {{ $bar }}"></span>
                        @unless ($isDeployer)
                            <span class="h-6 w-20 rounded-md {{ $bar }}"></span>
                        @endunless
                    </div>
                </div>

                {{-- Agent health checks — labels are fixed, only the detail line
                     and its state icon come from the probe. --}}
                <dl class="grid grid-cols-1 gap-px border-b border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([__('Agent script'), __('Callback env'), __('Cron line'), __('Last sample')] as $label)
                        <div class="bg-white px-3 py-2">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                            <dd class="mt-0.5 flex items-center gap-1.5">
                                <span class="h-4 w-4 shrink-0 rounded-full {{ $bar }}"></span>
                                <span class="h-3 w-20 {{ $bar }}"></span>
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <div class="border-b border-brand-ink/10">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                        <h2 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Current usage') }}
                        </h2>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                        <span class="ml-auto h-4 w-48 shrink-0 {{ $bar }}"></span>
                    </div>

                    <div class="px-3 py-2.5 sm:px-4">
                        {{-- KPI cards: icon + label are fixed, so only the value,
                             the meter fill and the sub-line pulse. --}}
                        <dl class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                            @foreach ([
                                ['heroicon-o-cpu-chip', __('CPU')],
                                ['heroicon-o-circle-stack', __('Memory')],
                                ['heroicon-o-server-stack', __('Disk').' ('.__('root').')'],
                                ['heroicon-o-chart-bar', __('Load avg')],
                            ] as [$kpiIcon, $kpiLabel])
                                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="inline-flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                            <x-dynamic-component :component="$kpiIcon" class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                                            {{ $kpiLabel }}
                                        </dt>
                                        <dd class="h-4 w-12 {{ $bar }}"></dd>
                                    </div>
                                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8">
                                        <div class="h-1 w-1/3 animate-pulse rounded-full bg-brand-ink/15"></div>
                                    </div>
                                    <dd class="mt-1 h-2.5 w-24 max-w-full {{ $bar }}"></dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach ([
                                ['heroicon-o-clock', __('Uptime'), 'text-brand-mist ring-brand-ink/10', 'text-brand-mist'],
                                ['heroicon-o-arrow-down', __('Inbound'), 'text-sky-700 ring-sky-200', 'text-sky-700'],
                                ['heroicon-o-arrow-up', __('Outbound'), 'text-violet-700 ring-violet-200', 'text-violet-700'],
                            ] as [$netIcon, $netLabel, $netBadge, $netText])
                                <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white ring-1 {{ $netBadge }}">
                                        <x-dynamic-component :component="$netIcon" class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-2xs font-semibold uppercase tracking-wide {{ $netText }}">{{ $netLabel }}</p>
                                        <div class="mt-1 h-3.5 w-16 {{ $bar }}"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                @unless ($isDeployer)
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                        <h2 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Alert thresholds') }}
                        </h2>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist">{{ __('Values that trigger warning colors on KPIs and Insights alerts.') }}</p>
                        <span class="ml-auto h-6 w-16 shrink-0 rounded-md {{ $bar }}"></span>
                    </div>
                    <div class="px-3 py-2.5 sm:px-4">
                        {{-- Read-only threshold cards (the edit form only opens on
                             click, so it can't be the loading state). --}}
                        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach ([__('CPU warning'), __('Memory warning'), __('Load warning')] as $thresholdLabel)
                                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ $thresholdLabel }}</dt>
                                    <dd class="mt-1 h-6 w-12 {{ $bar }}"></dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endunless
            </div>
        @endif
    </section>
</x-server-workspace-layout>
