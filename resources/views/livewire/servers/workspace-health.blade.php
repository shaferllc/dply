@php
    $healthTabContext = compact('report', 'server');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="health"
    :title="__('Health')"
    :description="__('Capacity, release pressure, deploy failures, certificates, and daemon drift — one cockpit for this server.')"
>
    @if ($report['monitoring']['agent_reporting'] ?? false)
        <div wire:poll.{{ $pollSeconds }}s class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-slot:explainer>
        <p>{{ __('The health cockpit rolls up guest metrics, atomic release counts, recent failed deploys, certificate expiry, and inactive supervisor programs. It is read-only — use the linked workspace tabs to fix issues.') }}</p>
    </x-slot:explainer>

    <div class="space-y-6">
        <x-server-workspace-tablist :aria-label="__('Health workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
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

        <div class="space-y-6">
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
    </div>

    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
