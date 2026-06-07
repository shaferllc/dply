@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="monitor"
    :title="__('Metrics')"
    :description="null"
>
    @if ($opsReady && $probePending)
        <div wire:poll.{{ $pollProbeSeconds }}s="syncMonitoringProbeStatus" class="hidden" aria-hidden="true"></div>
    @endif
    @if ($pyOk)
        <div wire:poll.{{ $pollAutoRefreshSeconds }}s class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Live + historical resource metrics (CPU, memory, disk, load, network) for this server. Samples are pushed from a small Python guest agent installed during provisioning; the workspace plots time-series and the latest snapshot.') }}</p>
        <p>{{ __('"Probe" is dply\'s SSH-based reachability check — different from the guest agent, which pushes samples on its own cadence. Both being green means metrics will keep flowing; either being red explains a stale dashboard.') }}</p>
    </x-explainer>

    @if ($server->workspace)
        @feature('surface.projects')
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Project') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Project health shortcut') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Metrics here are server-specific. Open the project operations page when you want to review grouped health, recent activity, and runbooks alongside the rest of this project.') }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                    <x-heroicon-m-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Project operations') }}
                                </a>
                                <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                    <x-heroicon-m-eye class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Project overview') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endfeature
    @endif

    @if ($opsReady && $probePending)
        <x-workspace-console-banner
            status="running"
            :message="__('Checking SSH and Python on :host …', ['host' => $server->getSshConnectionString()])"
            :subtitle="__('Running in the background — this page will update when the check finishes.')"
            :busy="true"
            poll-action="syncMonitoringProbeStatus"
            poll-interval="{{ $pollProbeSeconds }}s"
            :empty-message="__('No output yet — probe still running.')"
        />
    @endif
    @if ($opsReady && ! $pyOk)
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-arrow-down-tray class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Monitor') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Install monitor on this server') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Dply provisions the metrics agent over SSH so this page can stream usage data.') }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="p-6 sm:p-7">
                <ul class="space-y-2.5 text-sm text-brand-ink">
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

            @if ($probePending)
                <div class="mt-6 rounded-xl border border-sky-200/80 bg-sky-50/70 p-4">
                    <p class="text-sm font-medium text-sky-950">{{ __('SSH check queued — running in the background.') }}</p>
                    <p class="mt-1 text-xs text-sky-900/80">{{ __('You can leave this page; open Metrics again to see the result.') }}</p>
                </div>
            @elseif ($sshUnreachable)
                @php
                    $probeErrorLines = ! empty($m['monitoring_probe_error'])
                        ? explode("\n", (string) $m['monitoring_probe_error'])
                        : [];
                @endphp
                <div class="mt-6 space-y-3">
                    <x-workspace-console-banner
                        status="failed"
                        :message="__('SSH check failed — install is blocked until Dply can reach the server')"
                        :subtitle="__('Fix SSH credentials and firewall, then Recheck. The same install is available under Services when SSH works.')"
                        :output="$probeErrorLines"
                        :busy="false"
                        :default-expanded="count($probeErrorLines) > 0"
                        :empty-message="__('No probe error captured.')"
                    />
                    <div class="flex flex-wrap gap-3">
                        <x-secondary-button size="sm" href="{{ route('servers.settings', ['server' => $server, 'section' => 'connection']) }}" wire:navigate>{{ __('Server connection settings') }}</x-secondary-button>
                        <x-primary-button size="sm" type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck SSH') }}</span>
                            <span wire:loading wire:target="queueMonitoringProbe">{{ __('Queueing…') }}</span>
                        </x-primary-button>
                    </div>
                </div>
            @elseif ($isDeployer)
                <div class="mt-6 rounded-xl border border-amber-200/80 bg-amber-50/80 p-4 text-sm text-amber-950">
                    {{ __('Your role cannot run installs. Ask an admin to open this Metrics page or Services and use “Install Python for monitoring”, then Recheck.') }}
                    <div class="mt-3">
                        <x-secondary-button size="sm" type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" class="!py-2">{{ __('Recheck status') }}</x-secondary-button>
                    </div>
                </div>
            @elseif ($servicesRemoteTaskId || $monitoringInstallInProgress)
                @php
                    $installActionStatus = (string) ($monitoringInstallAction?->status ?? 'queued');
                    $installBannerStatus = $this->diagnosticsBannerStatus !== ''
                        ? $this->diagnosticsBannerStatus
                        : match ($installActionStatus) {
                            'running' => 'running',
                            'failed' => 'failed',
                            'completed' => 'completed',
                            default => 'queued',
                        };
                    $installBannerBusy = in_array($installBannerStatus, ['queued', 'running'], true);
                    $installStartedAt = $monitoringInstallAction?->started_at ?? $monitoringInstallAction?->created_at;
                    $installAgeMinutes = $installStartedAt?->diffInMinutes(now());

                    $installBannerHost = $server->getSshConnectionString();
                    $installBannerMessage = match ($installBannerStatus) {
                        'queued' => __('Install queued — waiting for a worker to pick it up…'),
                        'running' => __('Installing monitor on :host …', ['host' => $installBannerHost]),
                        'completed' => __('Monitor install finished.'),
                        'failed' => __('Monitor install failed.'),
                        default => __('Installing monitor on :host …', ['host' => $installBannerHost]),
                    };
                    $installBannerSubtitleParts = [];
                    $installBannerSubtitleParts[] = match ($installBannerStatus) {
                        'queued' => __('Queued — waiting to start.'),
                        'running' => __('Running apt + deploying the metrics agent over SSH.'),
                        'failed' => __('Install failed. Check the queue worker output and try again.'),
                        'completed' => __('Apt + agent deploy completed over SSH.'),
                        default => __('Install in progress.'),
                    };
                    if ($installStartedAt) {
                        $installBannerSubtitleParts[] = __('Started :time', ['time' => $installStartedAt->diffForHumans()]);
                        if ($installAgeMinutes !== null && $installAgeMinutes >= 1) {
                            $installBannerSubtitleParts[] = trans_choice(':count minute elapsed|:count minutes elapsed', (int) $installAgeMinutes, ['count' => (int) $installAgeMinutes]);
                        }
                    }
                    $installBannerSubtitle = implode(' · ', array_filter($installBannerSubtitleParts));
                @endphp
                <div class="mt-6">
                    <x-workspace-console-banner
                        :status="$installBannerStatus"
                        :message="$installBannerMessage"
                        :subtitle="$installBannerSubtitle"
                        :output="$this->diagnosticsBannerOutputLines"
                        :busy="$installBannerBusy"
                        :poll-action="$installBannerBusy ? 'syncServicesRemoteTaskFromCache' : null"
                        poll-interval="{{ $pollRemoteTaskSeconds }}s"
                        :default-expanded="true"
                    />
                </div>
            @else
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <x-primary-button
                        size="sm"
                        type="button"
                        wire:click="openInstallMonitoringModal('step1')"
                        wire:loading.attr="disabled"
                        wire:target="openInstallMonitoringModal,runInstallAction"
                    >
                        <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0" wire:loading.remove wire:target="openInstallMonitoringModal,runInstallAction" aria-hidden="true" />
                        <span class="inline-block size-4 shrink-0 animate-spin rounded-full border-2 border-brand-cream/40 border-t-brand-cream" wire:loading wire:target="openInstallMonitoringModal,runInstallAction" aria-hidden="true"></span>
                        <span wire:loading.remove wire:target="openInstallMonitoringModal,runInstallAction">{{ __('Install monitor') }}</span>
                        <span wire:loading wire:target="openInstallMonitoringModal,runInstallAction">{{ __('Installing…') }}</span>
                    </x-primary-button>
                    <x-secondary-button size="sm" type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck status') }}</span>
                        <span wire:loading wire:target="queueMonitoringProbe">{{ __('Queueing…') }}</span>
                    </x-secondary-button>
                </div>
                @if ($probeAt)
                    <p class="mt-4 text-xs text-brand-mist">{{ __('Last check') }}: {{ $probeAt->format('Y-m-d H:i:s T') }}</p>
                @endif
            @endif
            </div>
        </section>
    @endif

    @if (! $opsReady)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before metrics can be collected.') }}</p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($metrics_error)
        <section class="dply-card overflow-hidden border-rose-200">
            <div class="border-b border-brand-ink/10 bg-rose-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge tone="danger">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Metrics error') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Could not load metrics') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $metrics_error }}</p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($opsReady && $pyOk)
        @if ($bannerShow)
            @include('livewire.servers.partials.monitor._banner')
        @endif

        <x-server-workspace-tablist :aria-label="__('Monitor workspace sections')">
            <x-server-workspace-tab
                id="monitor-tab-status"
                :active="$monitor_workspace_tab === 'status'"
                wire:click="setMonitorWorkspaceTab('status')"
            >
                <span class="inline-flex items-center gap-1.5">
                    <span wire:loading.remove wire:target="setMonitorWorkspaceTab('status')">
                        <x-heroicon-o-chart-pie class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span wire:loading wire:target="setMonitorWorkspaceTab('status')">
                        <x-spinner class="h-4 w-4" />
                    </span>
                    {{ __('Status') }}
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="monitor-tab-history"
                :active="$monitor_workspace_tab === 'history'"
                wire:click="setMonitorWorkspaceTab('history')"
            >
                <span class="inline-flex items-center gap-1.5">
                    <span wire:loading.remove wire:target="setMonitorWorkspaceTab('history')">
                        <x-heroicon-o-chart-bar class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span wire:loading wire:target="setMonitorWorkspaceTab('history')">
                        <x-spinner class="h-4 w-4" />
                    </span>
                    {{ __('History') }}
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="monitor-tab-notifications"
                :active="$monitor_workspace_tab === 'notifications'"
                wire:click="setMonitorWorkspaceTab('notifications')"
            >
                <span class="inline-flex items-center gap-1.5">
                    <span wire:loading.remove wire:target="setMonitorWorkspaceTab('notifications')">
                        <x-heroicon-o-bell class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span wire:loading wire:target="setMonitorWorkspaceTab('notifications')">
                        <x-spinner class="h-4 w-4" />
                    </span>
                    {{ __('Notifications') }}
                    @if($routingSummary['server_routes'] > 0)
                        <span class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-brand-ink px-1.5 text-[10px] font-semibold text-brand-cream">
                            {{ $routingSummary['server_routes'] }}
                        </span>
                    @endif
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="monitor-tab-diagnostics"
                :active="$monitor_workspace_tab === 'diagnostics'"
                wire:click="setMonitorWorkspaceTab('diagnostics')"
            >
                <span class="inline-flex items-center gap-1.5">
                    <span wire:loading.remove wire:target="setMonitorWorkspaceTab('diagnostics')">
                        <x-heroicon-o-wrench-screwdriver class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span wire:loading wire:target="setMonitorWorkspaceTab('diagnostics')">
                        <x-spinner class="h-4 w-4" />
                    </span>
                    {{ __('Diagnostics') }}
                </span>
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setMonitorWorkspaceTab">

        @if ($monitor_workspace_tab === 'status')
            <x-server-workspace-tab-panel
                id="monitor-panel-status"
                labelled-by="monitor-tab-status"
                panel-class="space-y-6"
            >
                @include('livewire.servers.partials.monitor.status-tab')
            </x-server-workspace-tab-panel>
        @endif

        @if ($monitor_workspace_tab === 'history')
            <x-server-workspace-tab-panel
                id="monitor-panel-history"
                labelled-by="monitor-tab-history"
                panel-class="space-y-6"
            >
                @include('livewire.servers.partials.monitor.history-tab')
            </x-server-workspace-tab-panel>
        @endif

        @if ($monitor_workspace_tab === 'notifications')
            <x-server-workspace-tab-panel
                id="monitor-panel-notifications"
                labelled-by="monitor-tab-notifications"
                panel-class="space-y-6"
            >
                @include('livewire.servers.partials.monitor.notifications-tab')
            </x-server-workspace-tab-panel>
        @endif

        @if ($monitor_workspace_tab === 'diagnostics')
            <x-server-workspace-tab-panel
                id="monitor-panel-diagnostics"
                labelled-by="monitor-tab-diagnostics"
                panel-class="space-y-6"
            >
                @include('livewire.servers.partials.monitor.diagnostics-tab')
            </x-server-workspace-tab-panel>
        @endif

        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.install-monitoring-confirm-modal')

        {{-- Inline channel-create modal. Triggered from the Add subscription
             form's "Create new channel" link; auto-selects the new channel
             on success via the notification-channel-created Livewire event. --}}
        @include('livewire.partials.create-notification-channel-modal')
    </x-slot>
</x-server-workspace-layout>
