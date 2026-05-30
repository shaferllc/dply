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
    :description="__('Dply activity and system log tailing for this server — live SSH reads with Reverb streaming.')"
    :pageHeaderToolbar="true"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Logs from systemd, nginx/caddy, PHP-FPM, and dply\'s own activity stream — read live from the server over SSH. Use the Viewer tab to tail lines; Overview and Sources summarize what is available on this host.') }}</p>
        <p>{{ __('Time ranges are server-side filters: "Last 5 minutes" reads only the recent slice of each file; broader ranges page through more of the file. Sources that rotate (e.g. nginx access.log) honor the rotation — older entries roll off naturally.') }}</p>
    </x-explainer>

    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    <div class="space-y-6">
        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Deployers can review Dply activity logs but cannot read server log files over SSH. Switch to Dply activity or ask an admin to grant broader access.') }}
                        </p>
                    </div>
                </div>
            </section>
        @endif

        @if (! $opsReady && $sshRequiredForActive)
            @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
        @endif

        <x-server-workspace-tablist :aria-label="__('Logs workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
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
                id="logs-tab-related"
                icon="heroicon-o-link"
                :active="$logsTab === 'related'"
                wire:click="setLogsWorkspaceTab('related')"
            >
                {{ __('Related') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

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

        @if ($logsTab === 'related')
            @include('livewire.servers.partials.logs._tab-related', ['server' => $server])
        @endif
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
