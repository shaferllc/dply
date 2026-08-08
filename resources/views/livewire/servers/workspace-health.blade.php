@php
    $healthTabContext = compact('report', 'server');

    $overallTone = match ($report['overall'] ?? 'healthy') {
        'critical' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'warning' => 'bg-amber-50 text-amber-900 ring-amber-200',
        default => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    };

    $overallLabel = match ($report['overall'] ?? 'healthy') {
        'critical' => __('Needs attention'),
        'warning' => __('Watch closely'),
        default => __('Healthy'),
    };
@endphp

<x-server-workspace-layout
    :server="$server"
    active="health"
    :title="__('Health')"
    :description="__('Capacity, release pressure, deploy failures, certificates, and daemon drift — one cockpit for this server.')"
    hide-hero
>
    @if ($report['monitoring']['agent_reporting'] ?? false)
        <div wire:poll.{{ $pollSeconds }}s class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                        <x-heroicon-o-heart class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Health') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Capacity, releases, deploy failures, certificates, and daemon drift — one cockpit for this server.') }}
                        </p>
                    </div>
                </div>
                <div @class(['inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1', $overallTone])>
                    @switch($report['overall'] ?? 'healthy')
                        @case('critical')
                            <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @case('warning')
                            <x-heroicon-o-exclamation-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @default
                            <x-heroicon-o-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    @endswitch
                    {{ $overallLabel }}
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Health workspace sections')" scroll bare class="!mb-0">
                <x-server-workspace-tab
                    id="health-tab-overview"
                    icon="heroicon-o-heart"
                    :active="$healthTab === 'overview'"
                    wire:click="setHealthWorkspaceTab('overview')"
                >
                    {{ __('Overview') }}
                    @if ($report['alert_count'] > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-amber-900">{{ number_format($report['alert_count']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="health-tab-capacity"
                    icon="heroicon-o-chart-bar"
                    :active="$healthTab === 'capacity'"
                    wire:click="setHealthWorkspaceTab('capacity')"
                >
                    {{ __('Capacity') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="health-tab-releases"
                    icon="heroicon-o-rectangle-stack"
                    :active="$healthTab === 'releases'"
                    wire:click="setHealthWorkspaceTab('releases')"
                >
                    {{ __('Releases') }}
                    @if (($report['releases']['sites_over_keep'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-amber-900">{{ number_format((int) $report['releases']['sites_over_keep']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="health-tab-reliability"
                    icon="heroicon-o-shield-check"
                    :active="$healthTab === 'reliability'"
                    wire:click="setHealthWorkspaceTab('reliability')"
                >
                    {{ __('Reliability') }}
                    @php
                        $reliabilityCount = (int) ($report['deployments']['failed_count'] ?? 0)
                            + (int) ($report['certificates']['failed_count'] ?? 0)
                            + (int) ($report['certificates']['expiring_count'] ?? 0)
                            + (int) ($report['daemons']['inactive_count'] ?? 0);
                    @endphp
                    @if ($reliabilityCount > 0)
                        <span class="ml-1 rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-brand-moss">{{ number_format($reliabilityCount) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="health-tab-notifications"
                    icon="heroicon-o-bell"
                    :active="$healthTab === 'notifications'"
                    wire:click="setHealthWorkspaceTab('notifications')"
                >
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <div wire:loading.block wire:target="setHealthWorkspaceTab" class="px-5 py-6 sm:px-6" aria-busy="true">
            <span class="sr-only">{{ __('Loading…') }}</span>
            <div class="space-y-3" aria-hidden="true">
                <div class="flex items-start gap-3">
                    <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                @foreach (range(1, 4) as $row)
                    <div class="flex items-start gap-3 border-t border-brand-ink/10 pt-3">
                        <span class="mt-1 h-5 w-14 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-3/4 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="setHealthWorkspaceTab">
            @if ($healthTab === 'overview')
                @include('livewire.servers.partials.health._tab-overview', $healthTabContext)
            @endif

            @if ($healthTab === 'capacity')
                @include('livewire.servers.partials.health._tab-capacity', $healthTabContext)
            @endif

            @if ($healthTab === 'releases')
                @include('livewire.servers.partials.health._tab-releases', $healthTabContext)
            @endif

            @if ($healthTab === 'reliability')
                @include('livewire.servers.partials.health._tab-reliability', $healthTabContext)
            @endif

            @if ($healthTab === 'notifications')
                @include('livewire.servers.partials.health.notifications-tab')
            @endif
        </div>
    </section>

    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
