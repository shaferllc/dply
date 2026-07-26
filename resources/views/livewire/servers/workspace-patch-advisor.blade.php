@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];

    $overallTone = match ($report['overall'] ?? 'ok') {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $overallLabel = match ($report['overall'] ?? 'ok') {
        'critical' => __('Action needed'),
        'warning' => __('Review updates'),
        default => __('Up to date'),
    };

    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;

    $patchTabContext = compact(
        'report',
        'tonePalette',
        'server',
        'opsReady',
        'isDeployer',
        'osVersions',
        'inventoryDepths',
        'serviceActions',
        'dangerousActions',
        'autoUpdateIntervals',
        'extendedSnapshot',
    );
@endphp

<x-server-workspace-layout
    :server="$server"
    active="patches"
    :title="__('Patches')"
    :description="__('Pending apt updates, package inventory, apt actions, unattended-upgrades, and reboot guidance for this server.')"
    hide-hero
>
    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @include('livewire.partials.console-action-banner-static', [
        'run' => $patchConsoleRun,
        'kindLabels' => (array) config('console_actions.kinds', []),
    ])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Patches') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Pending apt updates, package inventory, apt actions, unattended-upgrades, and reboot guidance for this server.') }}
                        </p>
                    </div>
                </div>
                <div @class(['inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1', $overallTone])>
                    @switch($report['overall'] ?? 'ok')
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

        <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Patches workspace sections')" scroll class="!mb-0 border-0 bg-transparent p-0 shadow-none">
                <x-server-workspace-tab
                    id="patches-tab-overview"
                    icon="heroicon-o-chart-bar-square"
                    :active="$patchesTab === 'overview'"
                    wire:click="setPatchesWorkspaceTab('overview')"
                >
                    {{ __('Overview') }}
                    @if (($report['alert_count'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-amber-900">{{ number_format($report['alert_count']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="patches-tab-packages"
                    icon="heroicon-o-server-stack"
                    :active="$patchesTab === 'packages'"
                    wire:click="setPatchesWorkspaceTab('packages')"
                >
                    {{ __('Packages') }}
                    @if (($report['packages']['total'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-brand-moss">{{ number_format((int) $report['packages']['total']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="patches-tab-actions"
                    icon="heroicon-o-wrench-screwdriver"
                    :active="$patchesTab === 'actions'"
                    wire:click="setPatchesWorkspaceTab('actions')"
                >
                    {{ __('Actions') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="patches-tab-settings"
                    icon="heroicon-o-cog-6-tooth"
                    :active="$patchesTab === 'settings'"
                    wire:click="setPatchesWorkspaceTab('settings')"
                >
                    {{ __('Settings') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="patches-tab-notifications"
                    icon="heroicon-o-bell"
                    :active="$patchesTab === 'notifications'"
                    wire:click="setPatchesWorkspaceTab('notifications')"
                >
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <div wire:loading.block wire:target="setPatchesWorkspaceTab" class="px-5 py-6 sm:px-6" aria-busy="true">
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

        <div wire:loading.remove wire:target="setPatchesWorkspaceTab">
            @if ($patchesTab === 'overview')
                <div>
                    @if ($isDeployer)
                        <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
                            <div class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-900 ring-1 ring-amber-200">
                                    <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view patch state but cannot run apt actions or change unattended-upgrades settings.') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if (! $opsReady)
                        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                            @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
                        </div>
                    @endif

                    @include('livewire.servers.partials.patches._tab-overview', $patchTabContext)
                </div>
            @endif

            @if ($patchesTab === 'packages')
                @include('livewire.servers.partials.patches._tab-packages', $patchTabContext)
            @endif

            @if ($patchesTab === 'actions')
                @include('livewire.servers.partials.patches._tab-actions', $patchTabContext)
            @endif

            @if ($patchesTab === 'settings')
                @include('livewire.servers.partials.patches._tab-settings', $patchTabContext)
            @endif

            @if ($patchesTab === 'notifications')
                @include('livewire.servers.partials.patches._tab-notifications')
            @endif
        </div>
    </section>

    @include('livewire.partials.create-notification-channel-modal')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</x-server-workspace-layout>
