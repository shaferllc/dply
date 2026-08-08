@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
    ];

    $summary = $report['summary'] ?? [];
    $opsReady = (bool) ($report['ops_ready'] ?? false);
    $isDeployer = (bool) ($report['is_deployer'] ?? false);
    $sshRequiredForActive = (bool) ($report['ssh_required_for_active'] ?? true);
@endphp

<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    :description="__('Dply activity and system log tailing for this server — live SSH reads.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            icon="heroicon-o-document-text"
            :title="__('Logs')"
            :note="__('Dply activity and system log tailing for this server — live SSH reads.')"
            class="border-b border-brand-ink/10"
        />

        {{-- One line, not a four-line eyebrow/title/prose stack: the "READ-ONLY /
             Deployer role" heading pair only restated the sentence under it. --}}
        @if ($isDeployer)
            <div class="flex items-start gap-2 border-b border-amber-200/80 bg-amber-50/60 px-5 py-2.5 text-xs leading-relaxed text-amber-950 sm:px-6">
                <x-heroicon-o-eye class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                <p class="min-w-0">
                    <span class="font-semibold">{{ __('Read-only (deployer):') }}</span>
                    {{ __('Deployers can review Dply activity logs but cannot read server log files over SSH. Switch to Dply activity or ask an admin to grant broader access.') }}
                </p>
            </div>
        @endif

        @if (! $opsReady && $sshRequiredForActive && $logsTab !== 'activity')
            <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
            </div>
        @endif

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Logs workspace sections')" scroll bare class="!mb-0 w-full">
                <x-server-workspace-tab
                    id="logs-tab-viewer"
                    icon="heroicon-o-command-line"
                    :active="$logsTab === 'viewer'"
                    wire:click="setLogsWorkspaceTab('viewer')"
                >
                    {{ __('Viewer') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-overview"
                    icon="heroicon-o-chart-bar-square"
                    :active="$logsTab === 'overview'"
                    wire:click="setLogsWorkspaceTab('overview')"
                >
                    {{ __('Overview') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-sources"
                    icon="heroicon-o-queue-list"
                    :active="$logsTab === 'sources'"
                    wire:click="setLogsWorkspaceTab('sources')"
                >
                    {{ __('Sources') }}
                    @if (($summary['source_count'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-brand-moss">{{ number_format((int) $summary['source_count']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-shipping"
                    icon="heroicon-o-paper-airplane"
                    :active="$logsTab === 'shipping'"
                    wire:click="setLogsWorkspaceTab('shipping')"
                >
                    {{ __('dply Logs') }}
                    @if ($server->logAgent?->isRunning())
                        <span class="ml-1 inline-block h-2 w-2 rounded-full bg-emerald-500" title="{{ __('Log agent running') }}"></span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-alerts"
                    icon="heroicon-o-bell-alert"
                    :active="$logsTab === 'alerts'"
                    wire:click="setLogsWorkspaceTab('alerts')"
                >
                    {{ __('Alerts') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-activity"
                    icon="heroicon-o-clipboard-document-list"
                    :active="$logsTab === 'activity'"
                    wire:click="setLogsWorkspaceTab('activity')"
                >
                    {{ __('Activity') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="logs-tab-related"
                    icon="heroicon-o-link"
                    :active="$logsTab === 'related'"
                    wire:click="setLogsWorkspaceTab('related')"
                >
                    {{ __('Related') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        {{-- Skeleton swap, not a dim-and-lock. One shape per tab rather than the
             shared generic list stub: Activity arrives as an audit timeline
             (filters + trend bars + event feed) and Viewer as a dark tail pane,
             so a single stub resized on arrival for both. --}}
        @foreach (['viewer', 'overview', 'sources', 'shipping', 'alerts', 'activity'] as $skeletonTab)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setLogsWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @include('livewire.servers.partials.logs._tab-skeleton', ['tab' => $skeletonTab])
            </div>
        @endforeach

        <div class="relative min-w-0" wire:loading.class="hidden" wire:target="setLogsWorkspaceTab">
            @if ($logsTab === 'viewer')
                @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])
            @endif

            @if ($logsTab === 'overview')
                @include('livewire.servers.partials.logs._tab-overview', [
                    'report' => $report,
                    'tonePalette' => $tonePalette,
                    'server' => $server,
                ])
            @endif

            @if ($logsTab === 'sources')
                @include('livewire.servers.partials.logs._tab-sources', [
                    'report' => $report,
                    'tonePalette' => $tonePalette,
                    'server' => $server,
                ])
            @endif

            @if ($logsTab === 'shipping')
                @include('livewire.servers.partials.logs._tab-shipping', [
                    'server' => $server,
                    'agent' => $server->logAgent,
                    'logExplorer' => $logExplorer,
                    'logHistogram' => $logHistogram,
                    'logCorrelationEnabled' => $logCorrelationEnabled,
                    'shippingSubTab' => $shippingSubTab,
                ])
            @endif

            @if ($logsTab === 'alerts')
                @include('livewire.servers.partials.logs._tab-alerts', [
                    'server' => $server,
                    'rules' => $logAlertRules,
                    'alertingAvailable' => $logAlertingAvailable,
                ])
            @endif

            {{-- Activity is the server audit timeline (DB-backed, no SSH). Rendered only
                 while its tab is active so the AuditLog/trends queries stay deferred on
                 ordinary Logs hits; the nested component owns its own filter URL state. --}}
            @if ($logsTab === 'activity')
                <livewire:servers.workspace-activity :server="$server" :key="'logs-activity-'.$server->id" />
            @endif

            @if ($logsTab === 'related')
                @include('livewire.servers.partials.logs._tab-related', ['server' => $server])
            @endif
        </div>
    </section>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
