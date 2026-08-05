<x-server-workspace-layout
    :server="$server"
    active="monitor"
    :title="__('Metrics')"
    :description="null"
    hide-hero
>
    @if ($opsReady && $probePending)
        <div wire:poll.{{ $pollProbeSeconds }}s="syncMonitoringProbeStatus" class="hidden" aria-hidden="true"></div>
    @endif
    @if ($pyOk)
        <div wire:poll.{{ $pollAutoRefreshSeconds }}s class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

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

    @if ($opsReady && $pyOk && $bannerShow)
        @include('livewire.servers.partials.monitor._banner')
    @endif

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-sky-200">
                        <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Metrics') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Live usage, history charts, alert routing, and agent diagnostics for this server.') }}
                        </p>
                    </div>
                </div>
                @if ($opsReady && $pyOk)
                    <span @class(['inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1', $statusChipClasses])>
                        <x-dynamic-component :component="$statusChipIcon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $statusChipLabel }}
                    </span>
                @endif
            </div>
        </div>

        @if ($server->workspace)
            @feature('surface.projects')
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Project') }}</p>
                            <p class="mt-0.5 text-sm text-brand-moss">
                                {{ __('Metrics here are server-specific. Open project operations for grouped health and runbooks.') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
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
            @endfeature
        @endif

        @if (! $opsReady)
            <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-800 ring-1 ring-amber-200">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before metrics can be collected.') }}</p>
                    </div>
                </div>
            </div>
        @elseif ($metrics_error)
            <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Metrics error') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Could not load metrics') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $metrics_error }}</p>
                    </div>
                </div>
            </div>
        @elseif (! $pyOk)
            <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-arrow-down-tray class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Monitor') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Install monitor on this server') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Dply provisions the metrics agent over SSH so this page can stream usage data.') }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="px-5 py-5 sm:px-6">
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
                            <x-secondary-button size="sm" href="{{ route('servers.settings', $server) }}" wire:navigate>{{ __('Server connection settings') }}</x-secondary-button>
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
        @else
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-server-workspace-tablist :aria-label="__('Monitor workspace sections')" scroll class="!mb-0 border-0 bg-transparent p-0 shadow-none">
                    <x-server-workspace-tab
                        id="monitor-tab-status"
                        icon="heroicon-o-chart-pie"
                        :active="$monitor_workspace_tab === 'status'"
                        wire:click="setMonitorWorkspaceTab('status')"
                    >
                        {{ __('Status') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="monitor-tab-history"
                        icon="heroicon-o-chart-bar"
                        :active="$monitor_workspace_tab === 'history'"
                        wire:click="setMonitorWorkspaceTab('history')"
                    >
                        {{ __('History') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="monitor-tab-notifications"
                        icon="heroicon-o-bell"
                        :active="$monitor_workspace_tab === 'notifications'"
                        wire:click="setMonitorWorkspaceTab('notifications')"
                    >
                        {{ __('Notifications') }}
                        @if($routingSummary['server_routes'] > 0)
                            <span class="ml-1 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-brand-ink px-1.5 text-[10px] font-semibold text-brand-cream">
                                {{ $routingSummary['server_routes'] }}
                            </span>
                        @endif
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="monitor-tab-diagnostics"
                        icon="heroicon-o-wrench-screwdriver"
                        :active="$monitor_workspace_tab === 'diagnostics'"
                        wire:click="setMonitorWorkspaceTab('diagnostics')"
                    >
                        {{ __('Diagnostics') }}
                    </x-server-workspace-tab>
                </x-server-workspace-tablist>
            </div>

            <div wire:loading.block wire:target="setMonitorWorkspaceTab" class="px-5 py-6 sm:px-6" aria-busy="true">
                <span class="sr-only">{{ __('Loading…') }}</span>
                <div class="space-y-3" aria-hidden="true">
                    <div class="flex items-start gap-3">
                        <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                    @foreach (range(1, 3) as $row)
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

            <div wire:loading.remove wire:target="setMonitorWorkspaceTab">
                @if ($monitor_workspace_tab === 'status')
                    <x-server-workspace-tab-panel
                        id="monitor-panel-status"
                        labelled-by="monitor-tab-status"
                        panel-class=""
                    >
                        @include('livewire.servers.partials.monitor.status-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($monitor_workspace_tab === 'history')
                    <x-server-workspace-tab-panel
                        id="monitor-panel-history"
                        labelled-by="monitor-tab-history"
                        panel-class=""
                    >
                        @include('livewire.servers.partials.monitor.history-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($monitor_workspace_tab === 'notifications')
                    <x-server-workspace-tab-panel
                        id="monitor-panel-notifications"
                        labelled-by="monitor-tab-notifications"
                        panel-class=""
                    >
                        @include('livewire.servers.partials.monitor.notifications-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($monitor_workspace_tab === 'diagnostics')
                    <x-server-workspace-tab-panel
                        id="monitor-panel-diagnostics"
                        labelled-by="monitor-tab-diagnostics"
                        panel-class=""
                    >
                        @include('livewire.servers.partials.monitor.diagnostics-tab')
                    </x-server-workspace-tab-panel>
                @endif
            </div>
        @endif
    </section>

    <x-slot name="modals">
        @include('livewire.servers.partials.install-monitoring-confirm-modal')

        {{-- Inline channel-create modal. Triggered from the Add subscription
             form's "Create new channel" link; auto-selects the new channel
             on success via the notification-channel-created Livewire event. --}}
        @include('livewire.partials.create-notification-channel-modal')
    </x-slot>
</x-server-workspace-layout>
