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
    @if ($servicesRemoteTaskId)
        <div wire:poll.{{ $pollRemoteTaskSeconds }}s="syncServicesRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif
    @if ($pyOk)
        <div wire:poll.{{ $pollAutoRefreshSeconds }}s class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $remote_output ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

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
        <div class="rounded-xl border border-sky-200/80 bg-sky-50/90 px-4 py-3 text-sm text-sky-950">
            {{ __('Checking SSH and Python on the server in the background. This page will update when the check finishes.') }}
        </div>
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
                <div class="mt-6 rounded-xl border border-amber-200/90 bg-amber-50/80 p-4">
                    <p class="text-sm font-semibold text-amber-950">{{ __('SSH check failed — install is blocked until Dply can reach the server') }}</p>
                    <p class="mt-2 text-sm text-amber-900/90">{{ __('Fix SSH credentials and firewall, then Recheck. The same install is available under Services when SSH works.') }}</p>
                    @if (! empty($m['monitoring_probe_error']))
                        <pre class="mt-3 max-h-36 overflow-auto rounded-lg bg-white/80 p-3 text-xs text-brand-ink whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $m['monitoring_probe_error'] }}</pre>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-3">
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
                     or the persistent ServerManageAction row picked up
                     in render(). The latter survives page reloads. --}}
                @php
                    $installStatus = (string) ($monitoringInstallAction?->status ?? 'queued');
                    $installStartedAt = $monitoringInstallAction?->started_at ?? $monitoringInstallAction?->created_at;
                    $installAgeMinutes = $installStartedAt?->diffInMinutes(now());
                    $installLabel = match ($installStatus) {
                        'queued' => __('Queued — waiting to start.'),
                        'running' => __('Running apt + deploying the metrics agent over SSH.'),
                        'failed' => __('Install failed. Check the queue worker output and try again.'),
                        default => __('Install in progress.'),
                    };
                @endphp
                <div wire:poll.5s class="mt-6 rounded-xl border border-sky-200/80 bg-sky-50/70 p-4">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-block size-4 shrink-0 animate-spin rounded-full border-2 border-sky-300 border-t-sky-700" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-sky-950">
                                {{ __('Installing monitor on this server…') }}
                                <span class="ml-1 text-[10px] font-medium uppercase tracking-wide text-sky-700/80">{{ strtoupper($installStatus) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-sky-900/85 leading-relaxed">{{ $installLabel }}</p>
                            @if ($installStartedAt)
                                <p class="mt-1 text-[11px] text-sky-900/70">
                                    {{ __('Started') }}: {{ $installStartedAt->diffForHumans() }}
                                    @if ($installAgeMinutes !== null && $installAgeMinutes >= 1)
                                        · {{ trans_choice(':count minute elapsed|:count minutes elapsed', (int) $installAgeMinutes, ['count' => (int) $installAgeMinutes]) }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
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
            // "Healthy" = agent SHA matches AND a recent fresh sample
            // exists. When healthy we hide the recovery toolbar entirely
            // — Repair / diagnostics only need to surface when something
            // is actually off (stale agent, missing sample, clock skew,
            // or push verifier reports drift).
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
        @endphp
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Monitor status') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('Monitoring is installed. The server pushes fresh metrics back to Dply every minute.') }}
                    </p>
                </div>
                @if (! $isDeployer && ! $monitorHealthy)
                    <div class="flex flex-wrap items-center gap-3 lg:justify-end" x-data="{ advancedOpen: false }">
                        <button type="button" wire:click="repairMonitorNow" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
                            <span wire:loading.remove wire:target="repairMonitorNow">{{ __('Repair monitor now') }}</span>
                            <span wire:loading wire:target="repairMonitorNow">{{ __('Repairing…') }}</span>
                        </button>
                        <div class="relative">
                            <button type="button" @click="advancedOpen = ! advancedOpen" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-2.5 py-2.5 text-brand-ink shadow-sm hover:bg-brand-sand/40" :aria-expanded="advancedOpen" aria-label="{{ __('Troubleshoot monitor') }}">
                                <x-heroicon-o-ellipsis-horizontal class="h-4 w-4 shrink-0" aria-hidden="true" />
                            </button>
                            <div x-show="advancedOpen" x-cloak @click.outside="advancedOpen = false" x-transition.opacity.duration.100ms class="absolute right-0 z-20 mt-2 w-64 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-lg">
                                <button type="button" @click="advancedOpen = false" wire:click="runMonitorCallbackDiagnostics" wire:loading.attr="disabled" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/40">
                                    <span wire:loading.remove wire:target="runMonitorCallbackDiagnostics">{{ __('Run callback diagnostics') }}</span>
                                    <span wire:loading wire:target="runMonitorCallbackDiagnostics">{{ __('Running…') }}</span>
                                </button>
                                <button type="button" @click="advancedOpen = false" wire:click="inspectMetricsCallbackEnv" wire:loading.attr="disabled" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/40">
                                    <span wire:loading.remove wire:target="inspectMetricsCallbackEnv">{{ __('Inspect callback env') }}</span>
                                    <span wire:loading wire:target="inspectMetricsCallbackEnv">{{ __('Inspecting…') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <dl class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Status') }}</dt>
                    <dd class="mt-2 text-sm font-semibold text-brand-ink">
                        {{ __('Installed and running') }}
                    </dd>
                    @if ($guestPushVerification !== null)
                        @php
                            $scriptCurrent = (bool) ($guestPushVerification['script_current'] ?? false);
                            $remoteSha = $guestPushVerification['remote_sha'] ?? null;
                        @endphp
                        <dd class="mt-3 flex items-center gap-1.5 text-xs">
                            @if ($scriptCurrent)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-900">
                                    <x-heroicon-s-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Agent up to date') }}
                                </span>
                            @elseif ($remoteSha === null)
                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-semibold text-brand-moss">
                                    {{ __('Agent version unknown') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-900" title="{{ __('Dply will redeploy the latest snapshot script the next time this server is healthy.') }}">
                                    <x-heroicon-s-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Agent update queued') }}
                                </span>
                            @endif
                        </dd>
                    @endif
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Last sample received') }}</dt>
                    <dd class="mt-2 text-sm font-semibold text-brand-ink">
                        @if ($lastGuestSampleAt)
                            {{ $lastGuestSampleAt->format('Y-m-d H:i:s T') }}
                        @else
                            {{ __('Waiting for first sample') }}
                        @endif
                    </dd>
                    <dd class="mt-1 text-xs text-brand-mist">
                        @if ($sampleAgeMinutes !== null)
                            @if ($sampleTimestampInFuture)
                                {{ __('Clock skew detected between this server and Dply.') }}
                            @else
                                {{ trans_choice('Age: :minutes minute|Age: :minutes minutes', $sampleAgeMinutes, ['minutes' => $sampleAgeMinutes]) }}
                            @endif
                        @else
                            {{ __('Freshness updates after the first callback arrives.') }}
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    @endif

    @if ($showMetricsPanels)
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Current usage') }}</h2>
                    @if ($latest)
                        <p class="mt-1 text-xs text-brand-mist">
                            {{ __('Last sample') }}: {{ $latest->captured_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}
                        </p>
                    @endif
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('CPU') }}</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['cpu_pct']) ? number_format((float) $p['cpu_pct'], 1).'%' : '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-mist">
                        {{ trans_choice(':count core|:count cores', (int) ($latestPayloadSummary['cpu_count'] ?? 0), ['count' => (int) ($latestPayloadSummary['cpu_count'] ?? 0)]) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Memory') }}</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['mem_pct']) ? number_format((float) $p['mem_pct'], 1).'%' : '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-mist">
                        {{ $fmtBytes($latestPayloadSummary['memory_available_bytes'] ?? null) }} {{ __('free of') }} {{ $fmtBytes(isset($p['mem_total_kb']) ? (int) $p['mem_total_kb'] * 1024 : null) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Disk') }} ({{ __('root') }})</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['disk_pct']) ? number_format((float) $p['disk_pct'], 1).'%' : '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-mist">
                        {{ $fmtBytes($latestPayloadSummary['disk_free_bytes'] ?? null) }} {{ __('free of') }} {{ $fmtBytes($p['disk_total_bytes'] ?? null) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Load avg') }}</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['load_1m']) ? number_format((float) $p['load_1m'], 2) : '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-mist">
                        @if (isset($p['load_5m'], $p['load_15m']))
                            {{ number_format((float) $p['load_5m'], 2) }} / {{ number_format((float) $p['load_15m'], 2) }} (5m / 15m)
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-brand-mist">
                {{ __('Uptime') }}: {{ $fmtDuration($latestPayloadSummary['uptime_seconds'] ?? null) }}
                <span class="text-brand-moss">·</span>
                {{ __('In :rx · Out :tx', ['rx' => $fmtRate($latestPayloadSummary['rx_bytes_per_sec'] ?? null), 'tx' => $fmtRate($latestPayloadSummary['tx_bytes_per_sec'] ?? null)]) }}
            </p>
        </div>

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

            <p class="mt-4 text-xs text-brand-moss">
                <a href="{{ route('servers.insights', $server) }}" wire:navigate class="font-medium hover:text-brand-ink">{{ __('View deploy correlations on Insights') }} →</a>
            </p>
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.install-monitoring-confirm-modal')
    </x-slot>
</x-server-workspace-layout>
