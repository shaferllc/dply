@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];

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
>
    @if ($manageRemoteTaskId)
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="space-y-6">
        @include('livewire.partials.console-action-banner-static', [
            'run' => $patchConsoleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])

        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
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
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before apt actions work.') }}</p>
                    </div>
                </div>
            </section>
        @endif

        <x-server-workspace-tablist :aria-label="__('Patches workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
            <x-server-workspace-tab
                id="patches-tab-overview"
                icon="heroicon-o-chart-bar-square"
                :active="$patchesTab === 'overview'"
                wire:click="setPatchesWorkspaceTab('overview')"
            >
                {{ __('Overview') }}
                @if ($report['alert_count'] > 0)
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

        <div class="space-y-6">
            @if ($patchesTab === 'overview')
                @include('livewire.servers.partials.patches._tab-overview', $patchTabContext)
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
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
             shared with the Notifications tab so an operator can add a channel without
             leaving the page; the new channel is auto-selected on success. --}}
        @include('livewire.partials.create-notification-channel-modal')
    </x-slot>
</x-server-workspace-layout>
