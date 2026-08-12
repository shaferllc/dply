@php
    $healthTabContext = compact('report', 'server');

    // Atomic releases are per-site deploy folders. A dedicated database / cache /
    // load-balancer box never hosts site code, so that tab can only ever render
    // "No atomic deploy sites" — an empty state promising something impossible.
    $hostsSiteCode = ! in_array(
        (string) ($server->meta['server_role'] ?? ''),
        ['redis', 'valkey', 'database', 'load_balancer'],
        true,
    );

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
        {{-- Dense head, matching Errors / Security / Databases. The icon-badge +
             title + prose stack this replaced restated the breadcrumb, and the
             verdict rode its own full-width row above the tab strip. The pill
             stays in the head so the verdict is readable from every sub-tab. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-heart"
            :tone="in_array($report['overall'] ?? 'healthy', ['critical', 'warning'], true) ? 'amber' : null"
            :title="__('Health')"
            :note="$hostsSiteCode
                ? __('Capacity, releases, deploy failures, certificates, and daemon drift — one cockpit for this server.')
                : __('Capacity, certificates, and daemon drift — one cockpit for this server.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <span @class(['inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-xs font-semibold ring-1', $overallTone])>
                    @switch($report['overall'] ?? 'healthy')
                        @case('critical')
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @case('warning')
                            <x-heroicon-m-exclamation-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @default
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    @endswitch
                    {{ $overallLabel }}
                </span>
            </x-slot:actions>
        </x-workspace-panel-head>

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
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-amber-900">{{ number_format($report['alert_count']) }}</span>
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
                @if ($hostsSiteCode)
                <x-server-workspace-tab
                    id="health-tab-releases"
                    icon="heroicon-o-rectangle-stack"
                    :active="$healthTab === 'releases'"
                    wire:click="setHealthWorkspaceTab('releases')"
                >
                    {{ __('Releases') }}
                    @if (($report['releases']['sites_over_keep'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-amber-900">{{ number_format((int) $report['releases']['sites_over_keep']) }}</span>
                    @endif
                </x-server-workspace-tab>
                @endif
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
                        <span class="ml-1 rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-brand-moss">{{ number_format($reliabilityCount) }}</span>
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

        {{-- One skeleton per tab, sized to what actually arrives: Overview and
             Capacity lead with a figure strip, Releases and Reliability are
             plain rows, Notifications is the routed-channel list plus the add
             form. One shared stub, reshaped per tab. --}}
        @php
            $bar = 'animate-pulse rounded bg-brand-ink/10';
            $healthSkeletons = [
                'overview' => ['stats' => 4, 'rows' => 3],
                'capacity' => ['stats' => 4, 'rows' => 3],
                'releases' => ['stats' => 0, 'rows' => 5],
                'reliability' => ['stats' => 0, 'rows' => 5],
                'notifications' => ['stats' => 0, 'rows' => 2, 'form' => true],
            ];
        @endphp
        @foreach ($healthSkeletons as $skeletonTab => $shape)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setHealthWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <span class="h-6 w-24 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
                @if ($shape['stats'] > 0)
                    <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4" aria-hidden="true">
                        @foreach (range(1, $shape['stats']) as $cell)
                            <div class="space-y-1.5 px-4 py-2 sm:px-5">
                                <div class="h-2 w-14 {{ $bar }}"></div>
                                <div class="h-3 w-10 {{ $bar }}"></div>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="divide-y divide-brand-ink/10" aria-hidden="true">
                    @foreach (range(1, $shape['rows']) as $row)
                        <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                            <div class="h-2.5 w-40 max-w-full {{ $bar }}"></div>
                            <span class="ml-auto h-2.5 w-16 shrink-0 {{ $bar }}"></span>
                        </div>
                    @endforeach
                </div>
                @if ($shape['form'] ?? false)
                    <div class="grid gap-3 border-t border-brand-ink/10 px-4 py-3.5 sm:grid-cols-2 sm:px-5" aria-hidden="true">
                        @foreach (range(1, 2) as $field)
                            <div class="space-y-1.5">
                                <div class="h-2.5 w-16 {{ $bar }}"></div>
                                <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        <div wire:loading.class="hidden" wire:target="setHealthWorkspaceTab">
            @if ($healthTab === 'overview')
                @include('livewire.servers.partials.health._tab-overview', $healthTabContext)
            @endif

            @if ($healthTab === 'capacity')
                @include('livewire.servers.partials.health._tab-capacity', $healthTabContext)
            @endif

            @if ($healthTab === 'releases' && $hostsSiteCode)
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
