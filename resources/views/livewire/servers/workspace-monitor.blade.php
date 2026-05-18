@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $card = 'dply-card overflow-hidden';
    $m = $server->meta ?? [];
    $sshKnown = array_key_exists('monitoring_ssh_reachable', $m);
    $sshOk = (bool) ($m['monitoring_ssh_reachable'] ?? false);
    $pyOk = (bool) ($m['monitoring_python_installed'] ?? false);
    $sshUnreachable = $sshKnown && ! $sshOk;
    $probeAt = isset($m['monitoring_probe_at']) ? \Illuminate\Support\Carbon::parse($m['monitoring_probe_at'])->timezone(config('app.timezone')) : null;
    $lastGuestSampleAt = isset($monitorLastGuestSampleAt) && $monitorLastGuestSampleAt ? \Illuminate\Support\Carbon::parse($monitorLastGuestSampleAt)->timezone(config('app.timezone')) : null;
    $p = $latest?->payload ?? [];
    $fmtBytes = function (?int $b): string {
        if ($b === null || $b <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return number_format($v, $i > 0 ? 1 : 0).' '.$units[$i];
    };
    $fmtRate = function (?float $bytesPerSecond) use ($fmtBytes): string {
        if ($bytesPerSecond === null || $bytesPerSecond < 0) {
            return '—';
        }

        return $fmtBytes((int) round($bytesPerSecond)).'/s';
    };
    $fmtDuration = function (?int $seconds): string {
        if ($seconds === null || $seconds < 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    };
    $fmtAge = function (?int $minutes) use ($fmtDuration): string {
        if ($minutes === null || $minutes < 0) {
            return '—';
        }
        if ($minutes < 60) {
            return trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]);
        }

        return $fmtDuration($minutes * 60);
    };
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

    <x-explainer class="mb-4">
        <p>{{ __('Live + historical resource metrics (CPU, memory, disk, load, network) for this server. Samples are pushed from a small Python guest agent installed during provisioning; the workspace plots time-series and the latest snapshot.') }}</p>
        <p>{{ __('"Probe" is dply\'s SSH-based reachability check — different from the guest agent, which pushes samples on its own cadence. Both being green means metrics will keep flowing; either being red explains a stale dashboard.') }}</p>
    </x-explainer>

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project health shortcut') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('Metrics here are server-specific. Open the project operations page when you want to review grouped health, recent activity, and runbooks alongside the rest of this project.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project overview') }}</a>
            </div>
        </div>
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
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
            <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Install monitor on this server') }}</h2>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Dply provisions the metrics agent over SSH so this page can stream usage data.') }}
            </p>

            <ul class="mt-5 space-y-2.5 text-sm text-brand-ink">
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
                        <a href="{{ route('servers.settings', ['server' => $server, 'section' => 'connection']) }}" wire:navigate class="{{ $btnSecondary }}">{{ __('Server connection settings') }}</a>
                        <button type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
                            <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck SSH') }}</span>
                            <span wire:loading wire:target="queueMonitoringProbe">{{ __('Queueing…') }}</span>
                        </button>
                    </div>
                </div>
            @elseif ($isDeployer)
                <div class="mt-6 rounded-xl border border-amber-200/80 bg-amber-50/80 p-4 text-sm text-amber-950">
                    {{ __('Your role cannot run installs. Ask an admin to open this Metrics page or Services and use “Install Python for monitoring”, then Recheck.') }}
                    <div class="mt-3">
                        <button type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" class="{{ $btnSecondary }} !py-2">{{ __('Recheck status') }}</button>
                    </div>
                </div>
            @elseif ($servicesRemoteTaskId || $monitoringInstallInProgress)
                {{-- Install task is in flight (SSH apt + script deploy).
                     Driven by either the in-memory $servicesRemoteTaskId
                     (live cache stream) or the persistent ServerManageAction
                     row picked up in render(). The latter survives page
                     reloads — the action row stays queued/running until
                     the worker updates it. --}}
                @php
                    $installActionStatus = (string) ($monitoringInstallAction?->status ?? 'queued');
                    // Prefer the live cache status (queued/running/completed/failed)
                    // when we still have the in-memory task id; otherwise fall
                    // back to the action row status mapped onto banner copy.
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
                    <button
                        type="button"
                        wire:click="openInstallMonitoringModal('step1')"
                        wire:loading.attr="disabled"
                        wire:target="openInstallMonitoringModal,runInstallAction"
                        class="{{ $btnPrimary }}"
                    >
                        <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0" wire:loading.remove wire:target="openInstallMonitoringModal,runInstallAction" aria-hidden="true" />
                        <span class="inline-block size-4 shrink-0 animate-spin rounded-full border-2 border-brand-cream/40 border-t-brand-cream" wire:loading wire:target="openInstallMonitoringModal,runInstallAction" aria-hidden="true"></span>
                        <span wire:loading.remove wire:target="openInstallMonitoringModal,runInstallAction">{{ __('Install monitor') }}</span>
                        <span wire:loading wire:target="openInstallMonitoringModal,runInstallAction">{{ __('Installing…') }}</span>
                    </button>
                    <button type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" class="{{ $btnSecondary }}">
                        <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck status') }}</span>
                        <span wire:loading wire:target="queueMonitoringProbe">{{ __('Queueing…') }}</span>
                    </button>
                </div>
                @if ($probeAt)
                    <p class="mt-4 text-xs text-brand-mist">{{ __('Last check') }}: {{ $probeAt->format('Y-m-d H:i:s T') }}</p>
                @endif
            @endif
        </div>
    @endif

    @if (! $opsReady)
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before metrics can be collected.') }}
        </div>
    @endif

    @if ($metrics_error)
        <div class="rounded-2xl border border-red-200/80 bg-red-50/90 px-5 py-4 text-sm text-red-900">
            {{ $metrics_error }}
        </div>
    @endif

    @if ($opsReady && $pyOk)
        @php
            // Derive the four health signals that determine whether the
            // monitor is genuinely healthy. "script_current" alone is
            // misleading — we want the headline to flip to "stale" when
            // the agent stops pushing even if its SHA still matches.
            $monitorScriptCurrent = (bool) ($guestPushVerification['script_current'] ?? false);
            $monitorEnvDeployed = (bool) ($guestPushVerification['callback_env_deployed'] ?? false);
            $monitorCronCurrent = (bool) ($guestPushVerification['cron_current'] ?? false);
            $monitorSampleFresh = $sampleAgeMinutes !== null
                && $sampleAgeMinutes <= 10
                && ! $sampleTimestampInFuture;
            $monitorHealthy = $monitorScriptCurrent
                && $monitorEnvDeployed
                && $monitorCronCurrent
                && $monitorSampleFresh;

            $remoteSha = $guestPushVerification['remote_sha'] ?? null;

            // Headline copy keys off freshness first, since that's what
            // the operator actually cares about.
            if ($monitorHealthy) {
                $statusChipClasses = 'bg-emerald-50 text-emerald-900 ring-emerald-200';
                $statusChipIcon = 'heroicon-s-check-circle';
                $statusChipLabel = __('Healthy');
                $headlineCopy = __('Installed and running. The server pushes fresh metrics back to Dply every minute.');
            } elseif (! $monitorSampleFresh && $monitorScriptCurrent && $monitorEnvDeployed && $monitorCronCurrent) {
                $statusChipClasses = 'bg-amber-50 text-amber-900 ring-amber-200';
                $statusChipIcon = 'heroicon-s-exclamation-triangle';
                $statusChipLabel = __('Sample stale');
                $headlineCopy = __('Installed and running, but no fresh sample has arrived. The agent may have stopped pushing — open Diagnostics to repair the cron / callback env.');
            } elseif (! $monitorScriptCurrent && $remoteSha !== null) {
                $statusChipClasses = 'bg-amber-50 text-amber-900 ring-amber-200';
                $statusChipIcon = 'heroicon-s-arrow-path';
                $statusChipLabel = __('Agent update queued');
                $headlineCopy = __('A newer agent script is bundled with Dply. The next healthy callback will redeploy it; you can also repair manually under Diagnostics.');
            } else {
                $statusChipClasses = 'bg-rose-50 text-rose-900 ring-rose-200';
                $statusChipIcon = 'heroicon-s-x-circle';
                $statusChipLabel = __('Not configured');
                $headlineCopy = __('Monitor is installed but its callback wiring is incomplete. Open Diagnostics to repair.');
            }

            // Per-check chip data for the Status tab grid.
            $checks = [
                [
                    'label' => __('Agent script'),
                    'ok' => $monitorScriptCurrent,
                    'detail' => $monitorScriptCurrent
                        ? __('Up to date')
                        : ($remoteSha === null ? __('Version unknown') : __('Outdated — redeploy queued')),
                ],
                [
                    'label' => __('Callback env'),
                    'ok' => $monitorEnvDeployed,
                    'detail' => $monitorEnvDeployed ? __('Deployed') : __('Missing on host'),
                ],
                [
                    'label' => __('Cron line'),
                    'ok' => $monitorCronCurrent,
                    'detail' => $monitorCronCurrent ? __('Installed') : __('Missing or stale'),
                ],
                [
                    'label' => __('Last sample'),
                    'ok' => $monitorSampleFresh,
                    'detail' => $sampleTimestampInFuture
                        ? __('Clock skew detected')
                        : ($sampleAgeMinutes !== null
                            ? ($monitorSampleFresh
                                ? __(':age ago', ['age' => $fmtAge($sampleAgeMinutes)])
                                : __(':age ago — stale', ['age' => $fmtAge($sampleAgeMinutes)]))
                            : __('Waiting for first sample')),
                ],
            ];

            // Diagnostics banner inputs. Only renders when an action is in
            // flight or has produced output/error. Status comes from the
            // computed property which inspects the queued cache payload OR
            // the synchronous $remote_output / $remote_error slots.
            $bannerStatus = $this->diagnosticsBannerStatus;
            $bannerKind = $remote_output_kind;
            $bannerBusy = in_array($bannerStatus, ['queued', 'running'], true);
            $bannerShow = $bannerStatus !== '' && $bannerKind !== null;
            $bannerHost = $server->getSshConnectionString();
            $bannerMessage = match ([$bannerKind, $bannerStatus]) {
                ['repair', 'queued'] => __('Repair queued — waiting for a worker to pick it up…'),
                ['repair', 'running'] => __('Repairing monitor on :host …', ['host' => $bannerHost]),
                ['repair', 'completed'] => __('Monitor repair complete.'),
                ['repair', 'failed'] => __('Monitor repair failed.'),
                ['diagnostics', 'queued'] => __('Diagnostics queued — waiting for a worker to pick it up…'),
                ['diagnostics', 'running'] => __('Running callback diagnostics on :host …', ['host' => $bannerHost]),
                ['diagnostics', 'completed'] => __('Callback diagnostics finished.'),
                ['diagnostics', 'failed'] => __('Callback diagnostics failed.'),
                ['inspect', 'queued'] => __('Inspect queued — waiting for a worker to pick it up…'),
                ['inspect', 'running'] => __('Inspecting callback env on :host …', ['host' => $bannerHost]),
                ['inspect', 'completed'] => __('Callback env inspection finished.'),
                ['inspect', 'failed'] => __('Callback env inspection failed.'),
                default => '',
            };
            $bannerSubtitle = $bannerBusy
                ? __('Refreshing every :secs s · safe to leave this page — the job runs on the queue.', ['secs' => $pollRemoteTaskSeconds])
                : ($bannerStatus === 'failed' && is_string($remote_error) && $remote_error !== '' ? $remote_error : null);
        @endphp

        @if ($bannerShow)
            <x-workspace-console-banner
                :status="$bannerStatus"
                :message="$bannerMessage"
                :subtitle="$bannerSubtitle"
                :output="$this->diagnosticsBannerOutputLines"
                :busy="$bannerBusy"
                :dismiss-action="$bannerBusy ? null : 'dismissDiagnosticsBanner'"
                :poll-action="$bannerBusy ? 'syncServicesRemoteTaskFromCache' : null"
                poll-interval="{{ $pollRemoteTaskSeconds }}s"
                :default-expanded="true"
            />
        @endif

        <x-server-workspace-tablist :aria-label="__('Monitor workspace sections')">
            <x-server-workspace-tab
                id="monitor-tab-status"
                :active="$monitor_workspace_tab === 'status'"
                wire:click="setMonitorWorkspaceTab('status')"
            >
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-chart-pie class="h-4 w-4" aria-hidden="true" />
                    {{ __('Status') }}
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="monitor-tab-history"
                :active="$monitor_workspace_tab === 'history'"
                wire:click="setMonitorWorkspaceTab('history')"
            >
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-chart-bar class="h-4 w-4" aria-hidden="true" />
                    {{ __('History') }}
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="monitor-tab-notifications"
                :active="$monitor_workspace_tab === 'notifications'"
                wire:click="setMonitorWorkspaceTab('notifications')"
            >
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-bell class="h-4 w-4" aria-hidden="true" />
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
                    <x-heroicon-o-wrench-screwdriver class="h-4 w-4" aria-hidden="true" />
                    {{ __('Diagnostics') }}
                </span>
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        <x-server-workspace-tab-panel
            id="monitor-panel-status"
            labelled-by="monitor-tab-status"
            :hidden="$monitor_workspace_tab !== 'status'"
            panel-class="space-y-6"
        >
            <div class="{{ $card }}">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-chart-pie class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Monitor status') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ $headlineCopy }}
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ __('The server pushes fresh metrics back to Dply every minute.') }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold ring-1 {{ $statusChipClasses }}">
                                    <x-dynamic-component :component="$statusChipIcon" class="h-3 w-3" aria-hidden="true" />
                                    {{ $statusChipLabel }}
                                </span>
                                @if ($lastGuestSampleAt)
                                    <span class="text-brand-mist/60">·</span>
                                    <span>{{ __('last sample :time', ['time' => $lastGuestSampleAt->diffForHumans()]) }}</span>
                                @else
                                    <span class="text-brand-mist/60">·</span>
                                    <span>{{ __('no sample yet') }}</span>
                                @endif
                                {{-- "Installed and running" is preserved as a
                                     stable label so docs / search /
                                     screenshots that reference the old wording
                                     still resolve. The badge above is the
                                     canonical state — this is the descriptor. --}}
                                <span class="text-brand-mist/60">·</span>
                                <span>{{ __('Installed and running') }}</span>
                            </div>
                        </div>
                    </div>
                    @if (! $isDeployer)
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" wire:target="queueMonitoringProbe" class="{{ $btnSecondary }}">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="queueMonitoringProbe" aria-hidden="true" />
                                <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck status') }}</span>
                                <span wire:loading wire:target="queueMonitoringProbe">{{ __('Queueing…') }}</span>
                            </button>
                            @if (! $monitorHealthy)
                                <button type="button" wire:click="setMonitorWorkspaceTab('diagnostics')" class="{{ $btnSecondary }}">
                                    <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Open Diagnostics') }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                <dl class="grid grid-cols-1 gap-px bg-brand-ink/5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($checks as $c)
                        <div class="bg-white px-5 py-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $c['label'] }}</dt>
                            <dd class="mt-1.5 flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                                @if ($c['ok'])
                                    <x-heroicon-s-check-circle class="h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                                @else
                                    <x-heroicon-s-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                                @endif
                                <span>{{ $c['detail'] }}</span>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- Routing CTA Banner - shows when no notification routes configured --}}
            @if ($routingSummary['server_routes'] === 0 && $opsReady)
                <div class="rounded-2xl border border-amber-200/80 bg-amber-50/80 px-5 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                                <x-heroicon-o-bell-alert class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <div>
                                <p class="font-semibold text-amber-950">{{ __('No notification routes configured') }}</p>
                                <p class="mt-1 text-sm text-amber-800">
                                    {{ __('Add a channel to get alerts when metrics go stale or thresholds are breached.') }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button
                                type="button"
                                wire:click="setMonitorWorkspaceTab('notifications')"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors"
                            >
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Add route') }}
                            </button>
                            <a
                                href="{{ route('servers.settings', ['server' => $server, 'section' => 'alerts']) }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-2 rounded-lg border border-amber-300/80 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-amber-900 shadow-sm hover:bg-amber-100 transition-colors"
                            >
                                {{ __('Manage in Settings') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endif

            @if ($showMetricsPanels)
                @php
                    // Re-use $metricStatuses from the parent render (healthy /
                    // warning / critical / unknown) to color the fill bar +
                    // KPI text for each tile. Healthy stays neutral so the
                    // page doesn't light up green when nothing is wrong.
                    $kpiTone = function (string $status): array {
                        return match ($status) {
                            'critical' => ['bar' => 'bg-red-500', 'kpi' => 'text-red-700'],
                            'warning' => ['bar' => 'bg-amber-500', 'kpi' => 'text-amber-700'],
                            'healthy' => ['bar' => 'bg-emerald-500', 'kpi' => 'text-brand-ink'],
                            default => ['bar' => 'bg-brand-mist/60', 'kpi' => 'text-brand-ink'],
                        };
                    };
                    $cpuPct = isset($p['cpu_pct']) ? max(0, min(100, (float) $p['cpu_pct'])) : null;
                    $memPct = isset($p['mem_pct']) ? max(0, min(100, (float) $p['mem_pct'])) : null;
                    $diskPct = isset($p['disk_pct']) ? max(0, min(100, (float) $p['disk_pct'])) : null;
                    $loadPerCpu = isset($latestPayloadSummary['load_per_cpu_1m']) ? (float) $latestPayloadSummary['load_per_cpu_1m'] : null;
                    // Load is unbounded; render the bar as 0..100 % saturation
                    // (load/core, clamped). Anything past one core's worth is
                    // already "full" visually — the actual number tells the
                    // operator how far past saturation we are.
                    $loadFillPct = $loadPerCpu !== null ? max(0, min(100, $loadPerCpu * 100)) : null;
                    $cpuTone = $kpiTone($metricStatuses['cpu']);
                    $memTone = $kpiTone($metricStatuses['mem']);
                    $diskTone = $kpiTone($metricStatuses['disk']);
                    $loadTone = $kpiTone($metricStatuses['load']);
                @endphp
                <div class="{{ $card }} p-6 sm:p-8">
                    <header class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Current usage') }}</h2>
                            @if ($latest)
                                <p class="mt-1 text-xs text-brand-mist">
                                    {{ __('Last sample') }}: {{ \App\Support\Servers\ServerDateFormatter::format($latest->captured_at, $server) }}
                                </p>
                            @endif
                        </div>
                        <a href="{{ route('servers.insights', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-moss hover:text-brand-ink">
                            {{ __('View deploy correlations on Insights') }}
                            <x-heroicon-o-arrow-right class="h-3 w-3" aria-hidden="true" />
                        </a>
                    </header>

                    <dl class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        {{-- CPU --}}
                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <x-heroicon-o-cpu-chip class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                                    {{ __('CPU') }}
                                </dt>
                                <dd class="text-2xl font-semibold tabular-nums leading-none {{ $cpuTone['kpi'] }}">{{ $cpuPct !== null ? number_format($cpuPct, 1).'%' : '—' }}</dd>
                            </div>
                            <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                                <div class="h-1 rounded-full {{ $cpuTone['bar'] }}" style="width: {{ $cpuPct ?? 0 }}%"></div>
                            </div>
                            <dd class="mt-2 text-[11px] text-brand-mist">
                                {{ trans_choice(':count core|:count cores', (int) ($latestPayloadSummary['cpu_count'] ?? 0), ['count' => (int) ($latestPayloadSummary['cpu_count'] ?? 0)]) }}
                            </dd>
                        </div>

                        {{-- Memory --}}
                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <x-heroicon-o-circle-stack class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                                    {{ __('Memory') }}
                                </dt>
                                <dd class="text-2xl font-semibold tabular-nums leading-none {{ $memTone['kpi'] }}">{{ $memPct !== null ? number_format($memPct, 1).'%' : '—' }}</dd>
                            </div>
                            <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                                <div class="h-1 rounded-full {{ $memTone['bar'] }}" style="width: {{ $memPct ?? 0 }}%"></div>
                            </div>
                            <dd class="mt-2 text-[11px] text-brand-mist">
                                {{ $fmtBytes($latestPayloadSummary['memory_available_bytes'] ?? null) }} {{ __('free of') }} {{ $fmtBytes(isset($p['mem_total_kb']) ? (int) $p['mem_total_kb'] * 1024 : null) }}
                            </dd>
                        </div>

                        {{-- Disk root --}}
                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <x-heroicon-o-server-stack class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                                    {{ __('Disk') }} ({{ __('root') }})
                                </dt>
                                <dd class="text-2xl font-semibold tabular-nums leading-none {{ $diskTone['kpi'] }}">{{ $diskPct !== null ? number_format($diskPct, 1).'%' : '—' }}</dd>
                            </div>
                            <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                                <div class="h-1 rounded-full {{ $diskTone['bar'] }}" style="width: {{ $diskPct ?? 0 }}%"></div>
                            </div>
                            <dd class="mt-2 text-[11px] text-brand-mist">
                                {{ $fmtBytes($latestPayloadSummary['disk_free_bytes'] ?? null) }} {{ __('free of') }} {{ $fmtBytes($p['disk_total_bytes'] ?? null) }}
                            </dd>
                        </div>

                        {{-- Load avg --}}
                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <x-heroicon-o-chart-bar class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                                    {{ __('Load avg') }}
                                </dt>
                                <dd class="text-2xl font-semibold tabular-nums leading-none {{ $loadTone['kpi'] }}">{{ isset($p['load_1m']) ? number_format((float) $p['load_1m'], 2) : '—' }}</dd>
                            </div>
                            <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                                <div class="h-1 rounded-full {{ $loadTone['bar'] }}" style="width: {{ $loadFillPct ?? 0 }}%"></div>
                            </div>
                            <dd class="mt-2 text-[11px] text-brand-mist">
                                @if (isset($p['load_5m'], $p['load_15m']))
                                    {{ number_format((float) $p['load_5m'], 2) }} / {{ number_format((float) $p['load_15m'], 2) }} (5m / 15m)
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- Secondary stats: uptime + bandwidth. Promoted from a
                         single text line to a typed strip so each value is
                         glanceable. Borders match the tiles above so the
                         visual hierarchy reads "main KPIs · supporting stats". --}}
                    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-clock class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Uptime') }}</p>
                                <p class="text-sm font-semibold tabular-nums text-brand-ink">{{ $fmtDuration($latestPayloadSummary['uptime_seconds'] ?? null) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-sky-700 ring-1 ring-sky-200">
                                <x-heroicon-o-arrow-down class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-700">{{ __('Inbound') }}</p>
                                <p class="text-sm font-semibold tabular-nums text-brand-ink">{{ $fmtRate($latestPayloadSummary['rx_bytes_per_sec'] ?? null) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-violet-700 ring-1 ring-violet-200">
                                <x-heroicon-o-arrow-up class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-700">{{ __('Outbound') }}</p>
                                <p class="text-sm font-semibold tabular-nums text-brand-ink">{{ $fmtRate($latestPayloadSummary['tx_bytes_per_sec'] ?? null) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Threshold Configuration Card --}}
                @if (! $isDeployer)
                    <div class="{{ $card }} p-6 sm:p-8" x-data="{ editing: @js($editingThresholds) }" x-init="$watch('editing', value => { if (!value) $wire.editingThresholds = false; })">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Alert thresholds') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">
                                    {{ __('Values that trigger warning colors on KPIs and Insights alerts.') }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                @if ($editingThresholds)
                                    <button
                                        type="button"
                                        wire:click="cancelEditingThresholds"
                                        class="{{ $btnSecondary }}"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="saveThresholdSettings"
                                        wire:loading.attr="disabled"
                                        class="{{ $btnPrimary }}"
                                    >
                                        <span wire:loading.remove wire:target="saveThresholdSettings">{{ __('Save thresholds') }}</span>
                                        <span wire:loading wire:target="saveThresholdSettings">{{ __('Saving…') }}</span>
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="startEditingThresholds"
                                        class="{{ $btnSecondary }}"
                                    >
                                        <x-heroicon-o-pencil class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Edit thresholds') }}
                                    </button>
                                    @if ($thresholds['cpu'] !== (float) config('insights.thresholds.cpu_warn_pct', 85) ||
                                          $thresholds['mem'] !== (float) config('insights.thresholds.mem_warn_pct', 85) ||
                                          $thresholds['load'] !== (float) config('insights.thresholds.load_warn', 4.0))
                                        <button
                                            type="button"
                                            wire:click="resetThresholdsToDefaults"
                                            wire:confirm="{{ __('Revert to organization defaults?') }}"
                                            class="{{ $btnSecondary }}"
                                        >
                                            {{ __('Reset to defaults') }}
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($editingThresholds)
                            <form wire:submit="saveThresholdSettings" class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
                                <div>
                                    <x-input-label for="threshold-cpu" value="{{ __('CPU warning %') }}" />
                                    <div class="mt-1 flex items-center gap-2">
                                        <input
                                            type="number"
                                            id="threshold-cpu"
                                            wire:model="thresholdCpuInput"
                                            min="1"
                                            max="99"
                                            step="1"
                                            class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                        />
                                        <span class="text-sm text-brand-moss">%</span>
                                    </div>
                                    <x-input-error :messages="$errors->get('thresholdCpuInput')" class="mt-2" />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Default: :value%', ['value' => config('insights.thresholds.cpu_warn_pct', 85)]) }}</p>
                                </div>
                                <div>
                                    <x-input-label for="threshold-mem" value="{{ __('Memory warning %') }}" />
                                    <div class="mt-1 flex items-center gap-2">
                                        <input
                                            type="number"
                                            id="threshold-mem"
                                            wire:model="thresholdMemInput"
                                            min="1"
                                            max="99"
                                            step="1"
                                            class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                        />
                                        <span class="text-sm text-brand-moss">%</span>
                                    </div>
                                    <x-input-error :messages="$errors->get('thresholdMemInput')" class="mt-2" />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Default: :value%', ['value' => config('insights.thresholds.mem_warn_pct', 85)]) }}</p>
                                </div>
                                <div>
                                    <x-input-label for="threshold-load" value="{{ __('Load warning') }}" />
                                    <div class="mt-1">
                                        <input
                                            type="number"
                                            id="threshold-load"
                                            wire:model="thresholdLoadInput"
                                            min="0.1"
                                            max="100"
                                            step="0.1"
                                            class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                        />
                                    </div>
                                    <x-input-error :messages="$errors->get('thresholdLoadInput')" class="mt-2" />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Default: :value', ['value' => config('insights.thresholds.load_warn', 4.0)]) }}</p>
                                </div>
                            </form>
                        @else
                            <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('CPU warning') }}</dt>
                                    <dd class="mt-1 flex items-baseline gap-1">
                                        <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $thresholds['cpu'] }}</span>
                                        <span class="text-sm text-brand-moss">%</span>
                                    </dd>
                                </div>
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Memory warning') }}</dt>
                                    <dd class="mt-1 flex items-baseline gap-1">
                                        <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $thresholds['mem'] }}</span>
                                        <span class="text-sm text-brand-moss">%</span>
                                    </dd>
                                </div>
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Load warning') }}</dt>
                                    <dd class="mt-1 flex items-baseline gap-1">
                                        <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $thresholds['load'] }}</span>
                                    </dd>
                                </div>
                            </dl>
                            @if ($thresholds['cpu'] !== (float) config('insights.thresholds.cpu_warn_pct', 85) ||
                                  $thresholds['mem'] !== (float) config('insights.thresholds.mem_warn_pct', 85) ||
                                  $thresholds['load'] !== (float) config('insights.thresholds.load_warn', 4.0))
                                <p class="mt-4 text-xs text-brand-sage">
                                    <x-heroicon-o-information-circle class="inline h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Using custom server thresholds. Organization defaults shown in help text.') }}
                                </p>
                            @endif
                        @endif
                    </div>
                @endif
            @endif
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="monitor-panel-history"
            labelled-by="monitor-tab-history"
            :hidden="$monitor_workspace_tab !== 'history'"
            panel-class="space-y-6"
        >
            @if ($showMetricsPanels)
                @php
                    $rangeLabels = [
                        '1h' => __('1h'),
                        '6h' => __('6h'),
                        '24h' => __('24h'),
                        '7d' => __('7d'),
                        '30d' => __('30d'),
                    ];
                    $statusTextClass = function (string $status): string {
                        return match ($status) {
                            'critical' => 'text-red-600',
                            'warning' => 'text-amber-600',
                            'healthy' => 'text-emerald-600',
                            default => 'text-brand-mist',
                        };
                    };
                    $statusKpiClass = function (string $status): string {
                        return match ($status) {
                            'critical' => 'text-red-700',
                            'warning' => 'text-amber-700',
                            default => 'text-brand-ink',
                        };
                    };
                    $latestPayload = is_array($latest?->payload) ? $latest->payload : [];
                    $rxRate = $latestPayload['rx_bytes_per_sec'] ?? null;
                    $txRate = $latestPayload['tx_bytes_per_sec'] ?? null;
                    $networkSeriesRx = $rangeMetricSeries['rx_bytes_per_sec'] ?? [];
                    $networkSeriesTx = $rangeMetricSeries['tx_bytes_per_sec'] ?? [];
                    // For the network panel y-axis we want ONE shared scale across rx + tx.
                    $networkMaxValue = 0.0;
                    foreach (array_merge($networkSeriesRx, $networkSeriesTx) as $row) {
                        $networkMaxValue = max($networkMaxValue, (float) ($row['max'] ?? 0));
                    }
                    if ($networkMaxValue <= 0) {
                        $networkMaxValue = 1024.0;
                    }
                @endphp

                <div class="{{ $card }} p-6 sm:p-8" wire:key="metrics-chart-{{ $metricsRange }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent usage') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Per-metric history across the selected window. Filled band shows the min/max for each bucket; line is the average.') }}
                            </p>
                            @if ($chartFrom && $chartTo)
                                <p class="mt-2 text-xs tabular-nums text-brand-mist">
                                    {{ $chartFrom->timezone($chartTimezone)->format('M j H:i') }}
                                    —
                                    {{ $chartTo->timezone($chartTimezone)->format('M j H:i') }}
                                    <span class="text-brand-moss">·</span>
                                    {{ trans_choice(':count sample|:count samples', $rangeSampleCount, ['count' => $rangeSampleCount]) }}
                                </p>
                            @endif
                        </div>

                        {{-- Segmented time-range selector with localStorage persistence
                             keyed per server so each box remembers its last view. --}}
                        <div
                            x-data="{
                                range: @js($metricsRange),
                                storageKey: @js('dply.metrics-range:'.$server->id),
                                init() {
                                    try {
                                        const saved = window.localStorage?.getItem(this.storageKey);
                                        if (saved && saved !== this.range && @js($metricsRangeOptions).includes(saved)) {
                                            this.range = saved;
                                            this.$wire.setMetricsRange(saved);
                                        }
                                    } catch (e) { /* ignore */ }
                                },
                                pick(r) {
                                    this.range = r;
                                    try { window.localStorage?.setItem(this.storageKey, r); } catch (e) { /* ignore */ }
                                    this.$wire.setMetricsRange(r);
                                },
                            }"
                            x-init="init()"
                            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/10 bg-white p-1 shadow-sm"
                            role="group"
                            aria-label="{{ __('Time range') }}"
                        >
                            @foreach ($metricsRangeOptions as $opt)
                                <button
                                    type="button"
                                    @click="pick(@js($opt))"
                                    :class="range === @js($opt) ? 'bg-brand-ink text-brand-cream' : 'bg-transparent text-brand-moss hover:bg-brand-sand/40'"
                                    class="rounded-md px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide transition-colors"
                                >
                                    {{ $rangeLabels[$opt] ?? $opt }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if ($rangeSampleCount === 0)
                        <p class="mt-6 text-sm text-brand-mist">{{ __('No history in this range yet. Once samples come in, panels will populate automatically.') }}</p>
                    @else
                        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                            {{-- CPU --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-cpu-chip class="h-5 w-5 shrink-0 {{ $statusTextClass($metricStatuses['cpu']) }}" aria-hidden="true" />
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('CPU') }}</h3>
                                    </div>
                                    <p class="text-lg font-semibold tabular-nums {{ $statusKpiClass($metricStatuses['cpu']) }}">
                                        {{ isset($latestPayload['cpu_pct']) ? number_format((float) $latestPayload['cpu_pct'], 1).'%' : '—' }}
                                    </p>
                                </header>
                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                    {{ trans_choice(':count core|:count cores', (int) ($latestPayload['cpu_count'] ?? 0), ['count' => (int) ($latestPayload['cpu_count'] ?? 0)]) }}
                                    @if (! empty($latestPayload['load_per_cpu_1m']))
                                        <span class="text-brand-moss">· {{ number_format((float) $latestPayload['load_per_cpu_1m'], 2) }} {{ __('load/core') }}</span>
                                    @endif
                                </p>
                                <div class="mt-3">
                                    <x-metrics-line-chart
                                        :series="$rangeMetricSeries['cpu_pct'] ?? []"
                                        :y-min="0"
                                        :y-max="100"
                                        :threshold-warn="$thresholds['cpu']"
                                        color-class="text-brand-forest"
                                        format="percent"
                                    />
                                </div>
                            </section>

                            {{-- Memory --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-circle-stack class="h-5 w-5 shrink-0 {{ $statusTextClass($metricStatuses['mem']) }}" aria-hidden="true" />
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Memory') }}</h3>
                                    </div>
                                    <p class="text-lg font-semibold tabular-nums {{ $statusKpiClass($metricStatuses['mem']) }}">
                                        {{ isset($latestPayload['mem_pct']) ? number_format((float) $latestPayload['mem_pct'], 1).'%' : '—' }}
                                    </p>
                                </header>
                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                    @if (! empty($latestPayload['mem_total_kb']))
                                        {{ $fmtBytes((int) $latestPayload['mem_total_kb'] * 1024) }} {{ __('total') }}
                                    @endif
                                    @if (isset($latestPayload['swap_used_kb'], $latestPayload['swap_total_kb']) && (int) $latestPayload['swap_total_kb'] > 0)
                                        <span class="text-brand-moss">· {{ __('swap') }} {{ $fmtBytes((int) $latestPayload['swap_used_kb'] * 1024) }} / {{ $fmtBytes((int) $latestPayload['swap_total_kb'] * 1024) }}</span>
                                    @endif
                                </p>
                                <div class="mt-3">
                                    <x-metrics-line-chart
                                        :series="$rangeMetricSeries['mem_pct'] ?? []"
                                        :y-min="0"
                                        :y-max="100"
                                        :threshold-warn="$thresholds['mem']"
                                        color-class="text-amber-600"
                                        format="percent"
                                    />
                                </div>
                            </section>

                            {{-- Disk --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-server-stack class="h-5 w-5 shrink-0 {{ $statusTextClass($metricStatuses['disk']) }}" aria-hidden="true" />
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Disk') }} ({{ __('root') }})</h3>
                                    </div>
                                    <p class="text-lg font-semibold tabular-nums {{ $statusKpiClass($metricStatuses['disk']) }}">
                                        {{ isset($latestPayload['disk_pct']) ? number_format((float) $latestPayload['disk_pct'], 1).'%' : '—' }}
                                    </p>
                                </header>
                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                    @if (isset($latestPayload['disk_used_bytes'], $latestPayload['disk_total_bytes']))
                                        {{ $fmtBytes((int) $latestPayload['disk_used_bytes']) }} / {{ $fmtBytes((int) $latestPayload['disk_total_bytes']) }}
                                    @endif
                                    @if (! empty($latestPayload['inode_pct_root']))
                                        <span class="text-brand-moss">· {{ __('inodes') }} {{ number_format((float) $latestPayload['inode_pct_root'], 1) }}%</span>
                                    @endif
                                </p>
                                <div class="mt-3">
                                    <x-metrics-line-chart
                                        :series="$rangeMetricSeries['disk_pct'] ?? []"
                                        :y-min="0"
                                        :y-max="100"
                                        :threshold-warn="$thresholds['disk']"
                                        color-class="text-emerald-600"
                                        format="percent"
                                    />
                                </div>
                            </section>

                            {{-- Load --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-chart-bar class="h-5 w-5 shrink-0 {{ $statusTextClass($metricStatuses['load']) }}" aria-hidden="true" />
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Load avg') }}</h3>
                                    </div>
                                    <p class="text-lg font-semibold tabular-nums {{ $statusKpiClass($metricStatuses['load']) }}">
                                        {{ isset($latestPayload['load_1m']) ? number_format((float) $latestPayload['load_1m'], 2) : '—' }}
                                    </p>
                                </header>
                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                    @if (isset($latestPayload['load_5m'], $latestPayload['load_15m']))
                                        {{ number_format((float) $latestPayload['load_5m'], 2) }} / {{ number_format((float) $latestPayload['load_15m'], 2) }} (5m / 15m)
                                    @endif
                                </p>
                                <div class="mt-3">
                                    <x-metrics-line-chart
                                        :series="$rangeMetricSeries['load_1m'] ?? []"
                                        :y-min="0"
                                        :y-max="null"
                                        :threshold-warn="$thresholds['load']"
                                        color-class="text-brand-ink"
                                        format="load"
                                    />
                                </div>
                            </section>

                            {{-- Network: lg:col-span-2 row, two overlaid lines (rx/tx) --}}
                            <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5 lg:col-span-2">
                                <header class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-signal class="h-5 w-5 shrink-0 text-sky-600" aria-hidden="true" />
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Network') }}</h3>
                                    </div>
                                    @php
                                        $rxBps = is_numeric($rxRate) ? (float) $rxRate : 0;
                                        $txBps = is_numeric($txRate) ? (float) $txRate : 0;
                                    @endphp
                                    <p class="text-[11px] text-brand-mist">
                                        <span class="text-sky-700">↓ {{ $fmtRate($rxBps) }}</span>
                                        <span class="text-brand-moss">·</span>
                                        <span class="text-violet-700">↑ {{ $fmtRate($txBps) }}</span>
                                    </p>
                                </header>
                                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-700">↓ {{ __('Inbound') }}</p>
                                        <x-metrics-line-chart
                                            :series="$networkSeriesRx"
                                            :y-min="0"
                                            :y-max="$networkMaxValue"
                                            color-class="text-sky-600"
                                            format="bytes-per-sec"
                                        />
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-violet-700">↑ {{ __('Outbound') }}</p>
                                        <x-metrics-line-chart
                                            :series="$networkSeriesTx"
                                            :y-min="0"
                                            :y-max="$networkMaxValue"
                                            color-class="text-violet-600"
                                            format="bytes-per-sec"
                                        />
                                    </div>
                                </div>
                            </section>

                            {{-- Disk I/O: same shape as Network — two side-by-side
                                 charts sharing a y-axis so a write spike doesn't
                                 dwarf a smaller read line. Empty until the agent
                                 on the box is the new build that ships io_read_bps. --}}
                            @php
                                $ioReadSeries = $rangeMetricSeries['io_read_bps'] ?? [];
                                $ioWriteSeries = $rangeMetricSeries['io_write_bps'] ?? [];
                                $ioMaxValue = 0.0;
                                foreach (array_merge($ioReadSeries, $ioWriteSeries) as $row) {
                                    $ioMaxValue = max($ioMaxValue, (float) ($row['max'] ?? 0));
                                }
                                if ($ioMaxValue <= 0) {
                                    $ioMaxValue = 1024.0;
                                }
                                $ioReadBps = is_numeric($latestPayload['io_read_bps'] ?? null) ? (float) $latestPayload['io_read_bps'] : null;
                                $ioWriteBps = is_numeric($latestPayload['io_write_bps'] ?? null) ? (float) $latestPayload['io_write_bps'] : null;
                                $hasIoData = ($ioReadSeries !== [] || $ioWriteSeries !== []) || $ioReadBps !== null || $ioWriteBps !== null;
                            @endphp
                            @if ($hasIoData)
                                <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5 lg:col-span-2">
                                    <header class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <x-heroicon-o-arrows-up-down class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Disk I/O') }}</h3>
                                        </div>
                                        <p class="text-[11px] text-brand-mist">
                                            <span class="text-emerald-700">↻ {{ $ioReadBps !== null ? $fmtRate($ioReadBps) : '—' }}</span>
                                            <span class="text-brand-moss">·</span>
                                            <span class="text-amber-700">⇡ {{ $ioWriteBps !== null ? $fmtRate($ioWriteBps) : '—' }}</span>
                                        </p>
                                    </header>
                                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-emerald-700">↻ {{ __('Read') }}</p>
                                            <x-metrics-line-chart
                                                :series="$ioReadSeries"
                                                :y-min="0"
                                                :y-max="$ioMaxValue"
                                                color-class="text-emerald-600"
                                                format="bytes-per-sec"
                                            />
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-amber-700">⇡ {{ __('Write') }}</p>
                                            <x-metrics-line-chart
                                                :series="$ioWriteSeries"
                                                :y-min="0"
                                                :y-max="$ioMaxValue"
                                                color-class="text-amber-600"
                                                format="bytes-per-sec"
                                            />
                                        </div>
                                    </div>
                                </section>
                            @endif

                            {{-- Per-disk usage: a compact list under the main Disk
                                 panel. Only renders when the agent shipped a
                                 disks[] array; older agents see only the Disk %
                                 panel. --}}
                            @php
                                $disks = is_array($latestPayload['disks'] ?? null) ? $latestPayload['disks'] : [];
                            @endphp
                            @if (! empty($disks) && count($disks) > 1)
                                <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5 lg:col-span-2">
                                    <header class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <x-heroicon-o-server-stack class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Mounted filesystems') }}</h3>
                                        </div>
                                        <p class="text-[11px] text-brand-mist">{{ trans_choice(':count mount|:count mounts', count($disks), ['count' => count($disks)]) }}</p>
                                    </header>
                                    <ul class="mt-3 space-y-1.5">
                                        @foreach ($disks as $disk)
                                            @php
                                                $pct = (float) ($disk['pct'] ?? 0);
                                                $barColor = $pct >= 95 ? 'bg-red-500' : ($pct >= 85 ? 'bg-amber-500' : 'bg-emerald-500');
                                            @endphp
                                            <li class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3 text-xs">
                                                <span class="font-mono font-medium text-brand-ink min-w-0 sm:w-48 truncate" title="{{ $disk['device'] ?? '' }} · {{ $disk['fs_type'] ?? '' }}">{{ $disk['mount'] ?? '—' }}</span>
                                                <div class="flex-1 min-w-0">
                                                    <div class="h-1.5 w-full rounded-full bg-brand-sand/40">
                                                        <div class="h-1.5 rounded-full {{ $barColor }}" style="width: {{ min(100, max(0, $pct)) }}%"></div>
                                                    </div>
                                                </div>
                                                <span class="tabular-nums text-brand-moss whitespace-nowrap">
                                                    {{ number_format($pct, 1) }}%
                                                    <span class="text-brand-mist">·
                                                        {{ $fmtBytes((int) ($disk['used_bytes'] ?? 0)) }} / {{ $fmtBytes((int) ($disk['total_bytes'] ?? 0)) }}
                                                    </span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endif

                            {{-- Top processes: latest snapshot only (point-in-time);
                                 not bucketed because the row identity changes per
                                 sample and we want the live "what's hot right now"
                                 lens, not a chart. --}}
                            @php
                                $topCpu = is_array($latestPayload['top_cpu'] ?? null) ? $latestPayload['top_cpu'] : [];
                                $topMem = is_array($latestPayload['top_mem'] ?? null) ? $latestPayload['top_mem'] : [];
                            @endphp
                            @if (! empty($topCpu) || ! empty($topMem))
                                <section class="rounded-2xl border border-brand-ink/10 bg-white p-4 sm:p-5 lg:col-span-2">
                                    <header class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <x-heroicon-o-list-bullet class="h-5 w-5 shrink-0 text-brand-forest" aria-hidden="true" />
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Top processes') }}</h3>
                                        </div>
                                        <p class="text-[11px] text-brand-mist">
                                            @if ($latest)
                                                {{ __('Sampled') }} {{ $latest->captured_at->diffForHumans() }}
                                            @endif
                                        </p>
                                    </header>
                                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('By CPU') }}</p>
                                            <ul class="space-y-1">
                                                @forelse ($topCpu as $row)
                                                    <li class="flex items-center justify-between gap-3 text-xs">
                                                        <span class="min-w-0 flex-1 truncate font-mono text-brand-ink" title="PID {{ $row['pid'] ?? '?' }} · {{ $row['user'] ?? '?' }}">{{ $row['command'] ?? '—' }}</span>
                                                        <span class="tabular-nums text-brand-moss">{{ number_format((float) ($row['cpu_pct'] ?? 0), 1) }}%</span>
                                                    </li>
                                                @empty
                                                    <li class="text-xs text-brand-mist">{{ __('No data.') }}</li>
                                                @endforelse
                                            </ul>
                                        </div>
                                        <div>
                                            <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('By memory') }}</p>
                                            <ul class="space-y-1">
                                                @forelse ($topMem as $row)
                                                    <li class="flex items-center justify-between gap-3 text-xs">
                                                        <span class="min-w-0 flex-1 truncate font-mono text-brand-ink" title="PID {{ $row['pid'] ?? '?' }} · {{ $row['user'] ?? '?' }}">{{ $row['command'] ?? '—' }}</span>
                                                        <span class="tabular-nums text-brand-moss">{{ number_format((float) ($row['mem_pct'] ?? 0), 1) }}%</span>
                                                    </li>
                                                @empty
                                                    <li class="text-xs text-brand-mist">{{ __('No data.') }}</li>
                                                @endforelse
                                            </ul>
                                        </div>
                                    </div>
                                </section>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </x-server-workspace-tab-panel>

        {{-- Notifications Tab Panel --}}
        <x-server-workspace-tab-panel
            id="monitor-panel-notifications"
            labelled-by="monitor-tab-notifications"
            :hidden="$monitor_workspace_tab !== 'notifications'"
            panel-class="space-y-6"
        >
            @php
                $subscriptionsByChannel = $serverNotifSubscriptions->groupBy('notification_channel_id');
                $serverEventLabels = $serverEventLabels ?? [];
            @endphp

            <div class="{{ $card }}">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-bell class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Notification routing') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('Pick which notification channels should receive alerts for this server\'s events. Each row binds one channel to one event.') }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('servers.settings', ['server' => $server, 'section' => 'alerts']) }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors">
                        {{ __('Manage in Settings') }}
                        <x-heroicon-o-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>

                {{-- Current subscriptions list --}}
                <div class="px-6 py-5 sm:px-8">
                    @if ($subscriptionsByChannel->isEmpty())
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-6 text-center">
                            <x-heroicon-o-bell-slash class="mx-auto h-8 w-8 text-brand-mist" aria-hidden="true" />
                            <p class="mt-3 text-sm text-brand-moss">
                                {{ __('No notification subscriptions yet for this server.') }}
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ __('Add a subscription below to get alerts when metrics go stale or thresholds are breached.') }}
                            </p>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                            @foreach ($subscriptionsByChannel as $channelId => $subs)
                                @php $channel = $subs->first()->channel; @endphp
                                <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-brand-ink">{{ $channel?->label ?? __('(deleted channel)') }}</p>
                                        <p class="text-xs text-brand-moss">
                                            {{ ucfirst((string) ($channel?->type ?? '—')) }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @foreach ($subs as $sub)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2 py-1 text-[11px] font-medium text-brand-ink ring-1 ring-inset ring-brand-ink/10">
                                                {{ $serverEventLabels[$sub->event_key] ?? $sub->event_key }}
                                                @if (! $isDeployer)
                                                    <button
                                                        type="button"
                                                        wire:click="removeServerNotificationSubscription(@js($sub->id))"
                                                        wire:confirm="{{ __('Remove this subscription?') }}"
                                                        class="text-brand-moss hover:text-red-700"
                                                        aria-label="{{ __('Remove subscription') }}"
                                                    >×</button>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Add subscription form --}}
                @if (! $isDeployer)
                    <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8">
                        <p class="text-sm font-medium text-brand-ink">{{ __('Add subscription') }}</p>
                        <form wire:submit="addServerNotificationSubscription" class="mt-4 space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="notif-add-channel" value="{{ __('Channel') }}" />
                                    <select
                                        id="notif-add-channel"
                                        wire:model="notifAddChannelId"
                                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    >
                                        <option value="">{{ __('Select a channel…') }}</option>
                                        @foreach ($assignableChannels as $channel)
                                            <option value="{{ $channel->id }}">{{ $channel->label }} ({{ ucfirst($channel->type) }})</option>
                                        @endforeach
                                    </select>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        @if ($assignableChannels->isEmpty())
                                            <p class="text-xs text-brand-moss">
                                                {{ __('No assignable channels found.') }}
                                            </p>
                                        @endif
                                        <button
                                            type="button"
                                            wire:click="openCreateChannelModal"
                                            class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-ink hover:text-brand-sage"
                                        >
                                            <x-heroicon-o-plus-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Create new channel') }}
                                        </button>
                                        <span class="text-[10px] text-brand-mist">·</span>
                                        <a
                                            href="{{ route('profile.notification-channels') }}"
                                            class="text-xs text-brand-mist hover:text-brand-ink"
                                        >
                                            {{ __('Manage all in Settings →') }}
                                        </a>
                                    </div>
                                    <x-input-error :messages="$errors->get('notifAddChannelId')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label value="{{ __('Events') }}" />
                                    <div class="mt-1 space-y-1.5">
                                        @foreach ($serverEventLabels as $key => $label)
                                            <label class="flex items-center gap-2 text-sm text-brand-ink">
                                                <input
                                                    type="checkbox"
                                                    wire:model="notifAddEventKeys"
                                                    value="{{ $key }}"
                                                    class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                                />
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <x-input-error :messages="$errors->get('notifAddEventKeys')" class="mt-2" />
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <x-primary-button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    :disabled="$assignableChannels->isEmpty()"
                                >
                                    {{ __('Add subscription') }}
                                </x-primary-button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Routing summary card --}}
            <div class="{{ $card }} p-6 sm:p-8">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Routing summary') }}</h3>
                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Server routes') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $routingSummary['server_routes'] }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Project routes') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $routingSummary['project_routes'] }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Available channels') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $assignableChannels->count() }}</dd>
                    </div>
                </dl>
            </div>
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="monitor-panel-diagnostics"
            labelled-by="monitor-tab-diagnostics"
            :hidden="$monitor_workspace_tab !== 'diagnostics'"
            panel-class="space-y-6"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-wrench-screwdriver class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Diagnostics & repair') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Inspect what the agent is doing on the host and re-deploy its callback wiring when samples stop arriving. Output streams under the page header.') }}
                        </p>
                    </div>
                </div>

                @if ($isDeployer)
                    <div class="mt-6 rounded-xl border border-amber-200/80 bg-amber-50/80 p-4 text-sm text-amber-950">
                        {{ __('Your role cannot run repairs or diagnostics. Ask an admin to open this Metrics page if the monitor needs attention.') }}
                    </div>
                @else
                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Repair monitor wiring') }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Re-deploys the agent script, callback env, and cron over SSH. Use when samples have stopped arriving but SSH still works.') }}
                            </p>
                            <button type="button" wire:click="repairMonitorNow" wire:loading.attr="disabled" wire:target="repairMonitorNow" class="{{ $btnPrimary }} mt-4">
                                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="repairMonitorNow" aria-hidden="true" />
                                <span wire:loading.remove wire:target="repairMonitorNow">{{ __('Repair monitor now') }}</span>
                                <span wire:loading wire:target="repairMonitorNow">{{ __('Repairing…') }}</span>
                            </button>
                        </div>

                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Run callback diagnostics') }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Runs the snapshot script locally and probes the callback URL from the host. Useful when repair finishes but samples still don\'t arrive.') }}
                            </p>
                            <button type="button" wire:click="runMonitorCallbackDiagnostics" wire:loading.attr="disabled" wire:target="runMonitorCallbackDiagnostics" class="{{ $btnSecondary }} mt-4">
                                <x-heroicon-o-bug-ant class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="runMonitorCallbackDiagnostics" aria-hidden="true" />
                                <span wire:loading.remove wire:target="runMonitorCallbackDiagnostics">{{ __('Run callback diagnostics') }}</span>
                                <span wire:loading wire:target="runMonitorCallbackDiagnostics">{{ __('Running…') }}</span>
                            </button>
                        </div>

                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Inspect callback env') }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Prints the agent\'s metrics-callback.env file with the token redacted. Verifies the URL the agent is POSTing to.') }}
                            </p>
                            <button type="button" wire:click="inspectMetricsCallbackEnv" wire:loading.attr="disabled" wire:target="inspectMetricsCallbackEnv" class="{{ $btnSecondary }} mt-4">
                                <x-heroicon-o-document-magnifying-glass class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="inspectMetricsCallbackEnv" aria-hidden="true" />
                                <span wire:loading.remove wire:target="inspectMetricsCallbackEnv">{{ __('Inspect callback env') }}</span>
                                <span wire:loading wire:target="inspectMetricsCallbackEnv">{{ __('Inspecting…') }}</span>
                            </button>
                        </div>

                        <div class="rounded-xl border border-brand-ink/10 bg-white p-4 sm:p-5">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Re-verify guest push') }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Re-reads the script SHA, env, and cron from the host and queues repair jobs for anything missing.') }}
                            </p>
                            <button type="button" wire:click="verifyGuestPush" wire:loading.attr="disabled" wire:target="verifyGuestPush" class="{{ $btnSecondary }} mt-4">
                                <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="verifyGuestPush" aria-hidden="true" />
                                <span wire:loading.remove wire:target="verifyGuestPush">{{ __('Re-verify guest push') }}</span>
                                <span wire:loading wire:target="verifyGuestPush">{{ __('Verifying…') }}</span>
                            </button>
                        </div>
                    </div>

                    @if ($probeAt || $guestPushCronExpression)
                        <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
                            @if ($probeAt)
                                <p>{{ __('Last SSH/Python probe') }}: <span class="font-mono text-brand-ink">{{ $probeAt->format('Y-m-d H:i:s T') }}</span></p>
                            @endif
                            @if ($guestPushCronExpression)
                                <p class="mt-1">{{ __('Push cron') }}: <span class="font-mono text-brand-ink">{{ $guestPushCronExpression }}</span></p>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
        </x-server-workspace-tab-panel>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.install-monitoring-confirm-modal')

        {{-- Inline channel-create modal. Triggered from the Add subscription
             form's "Create new channel" link; auto-selects the new channel
             on success via the notification-channel-created Livewire event. --}}
        @include('livewire.partials.create-notification-channel-modal')
    </x-slot>
</x-server-workspace-layout>
