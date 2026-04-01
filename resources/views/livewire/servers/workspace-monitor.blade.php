@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
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

    @include('livewire.servers.partials.workspace-flashes', ['command_output' => $remote_output ?? null, 'command_error' => $remote_error ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => 14])

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
            {{ __('Checking SSH and Python on the server in the background. This page will update when the check finishes (requires a queue worker).') }}
        </div>
    @endif
    {{-- Always-visible install path when Python is missing (matches server_workspace / overview copy) --}}
    @if ($opsReady && ! $pyOk)
        <div class="rounded-2xl border-2 border-brand-sage/35 bg-gradient-to-b from-brand-sand/50 to-white p-6 sm:p-8 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Monitor setup') }}</p>
            <h2 class="mt-2 text-xl font-bold tracking-tight text-brand-ink">{{ __('Install monitor on this server') }}</h2>
            <p class="mt-3 max-w-3xl text-sm leading-relaxed text-brand-moss">
                {{ __('Dply installs Python if needed, deploys the monitor script, and starts collecting usage data. Once it is installed, this page will keep updating every minute.') }}
            </p>

            @if ($probePending)
                <p class="mt-5 text-sm font-medium text-brand-ink">{{ __('SSH check queued — running in the background.') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Ensure a queue worker is running. You can leave this page; open Metrics again to see the result.') }}</p>
            @elseif ($sshUnreachable)
                <div class="mt-5 rounded-xl border border-amber-200/90 bg-amber-50/90 p-4">
                    <p class="text-sm font-semibold text-amber-950">{{ __('SSH check failed — install is blocked until Dply can reach the server') }}</p>
                    <p class="mt-2 text-sm text-amber-900/90">{{ __('Fix SSH credentials and firewall, then Recheck. The same install is available under Services when SSH works.') }}</p>
                    @if (! empty($m['monitoring_probe_error']))
                        <pre class="mt-3 max-h-36 overflow-auto rounded-lg bg-white/80 p-3 text-xs text-brand-ink whitespace-pre-wrap">{{ $m['monitoring_probe_error'] }}</pre>
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
                <div class="mt-5 rounded-xl border border-amber-200/80 bg-amber-50/90 p-4 text-sm text-amber-950">
                    {{ __('Your role cannot run installs. Ask an admin to open this Metrics page or Services and use “Install Python for monitoring”, then Recheck.') }}
                    <div class="mt-3">
                        <button type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" class="{{ $btnSecondary }} !py-2">{{ __('Recheck status') }}</button>
                    </div>
                </div>
            @else
                <div class="mt-6 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center">
                    <button
                        type="button"
                        wire:click="openInstallMonitoringModal('step1')"
                        class="{{ $btnPrimary }} !px-6 !py-3 !text-sm"
                    >
                        <x-heroicon-o-arrow-down-tray class="h-5 w-5 shrink-0 opacity-90" />
                        {{ __('Install monitor') }}
                    </button>
                    <button type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" class="{{ $btnSecondary }}">
                        <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck status') }}</span>
                        <span wire:loading wire:target="queueMonitoringProbe">{{ __('Queueing…') }}</span>
                    </button>
                    <a href="{{ route('servers.services', $server) }}" wire:navigate class="text-sm font-semibold text-brand-sage hover:text-brand-forest">{{ __('Same install on Services') }} →</a>
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
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Monitor status') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('Monitoring is installed. The server pushes fresh metrics back to Dply every minute.') }}
                    </p>
                </div>
                @unless ($isDeployer)
                    <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                        <button type="button" wire:click="verifyGuestPush" wire:loading.attr="disabled" class="{{ $btnSecondary }}">
                            <span wire:loading.remove wire:target="verifyGuestPush">{{ __('Recheck monitor') }}</span>
                            <span wire:loading wire:target="verifyGuestPush">{{ __('Checking…') }}</span>
                        </button>
                        <button type="button" wire:click="repairMonitorNow" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
                            <span wire:loading.remove wire:target="repairMonitorNow">{{ __('Repair monitor now') }}</span>
                            <span wire:loading wire:target="repairMonitorNow">{{ __('Repairing…') }}</span>
                        </button>
                        <button type="button" wire:click="runMonitorCallbackDiagnostics" wire:loading.attr="disabled" class="{{ $btnSecondary }}">
                            <span wire:loading.remove wire:target="runMonitorCallbackDiagnostics">{{ __('Run callback diagnostics') }}</span>
                            <span wire:loading wire:target="runMonitorCallbackDiagnostics">{{ __('Running…') }}</span>
                        </button>
                        <button type="button" wire:click="inspectMetricsCallbackEnv" wire:loading.attr="disabled" class="{{ $btnSecondary }}">
                            <span wire:loading.remove wire:target="inspectMetricsCallbackEnv">{{ __('Inspect callback env') }}</span>
                            <span wire:loading wire:target="inspectMetricsCallbackEnv">{{ __('Inspecting…') }}</span>
                        </button>
                    </div>
                @endunless
            </div>

            <dl class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Status') }}</dt>
                    <dd class="mt-2 text-sm font-semibold text-brand-ink">
                        {{ __('Installed and running') }}
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Collection cadence') }}</dt>
                    <dd class="mt-2 text-sm font-semibold text-brand-ink">
                        {{ __('Every minute') }}
                    </dd>
                    <dd class="mt-1 text-xs text-brand-mist font-mono">{{ $guestPushCronExpression ?? '* * * * *' }}</dd>
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
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Observe') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __('Watch for capacity and drift') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('CPU, memory, disk, and load tell you whether the host is healthy before a deploy or incident response step.') }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Correlate') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __('Relate spikes to releases') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Deployment context on this page helps answer whether a resource spike came from new code, a scheduled job, or a server-level change.') }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Escalate') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __('Route findings to the team') }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Use organization and project notification routing so health changes reach the right people instead of living only on this screen.') }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-3">
                <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Sample freshness') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">
                        @if ($sampleAgeMinutes === null)
                            {{ __('Waiting for first callback') }}
                        @elseif ($sampleTimestampInFuture)
                            {{ __('Clock skew detected') }}
                        @elseif ($sampleAgeMinutes <= 2)
                            {{ __('Fresh') }}
                        @elseif ($sampleAgeMinutes <= 10)
                            {{ __('Aging') }}
                        @else
                            {{ __('Stale') }}
                        @endif
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        @if ($sampleAgeMinutes === null)
                            {{ __('No server sample has been stored yet.') }}
                        @elseif ($sampleTimestampInFuture)
                            {{ __('The latest sample timestamp is ahead of Dply. Check the server timezone or clock sync, then let a new sample arrive.') }}
                        @else
                            {{ trans_choice('Latest metric sample is :minutes minute old.|Latest metric sample is :minutes minutes old.', $sampleAgeMinutes, ['minutes' => $sampleAgeMinutes]) }}
                        @endif
                    </p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Server notification routes') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ trans_choice(':count saved|:count saved', $routingSummary['server_routes'], ['count' => $routingSummary['server_routes']]) }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Use server-scoped routes for host-specific incidents and service alerts.') }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Project escalation routes') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">
                        @if ($routingSummary['has_project'])
                            {{ trans_choice(':count saved|:count saved', $routingSummary['project_routes'], ['count' => $routingSummary['project_routes']]) }}
                        @else
                            {{ __('No project attached') }}
                        @endif
                    </p>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        @if ($routingSummary['has_project'])
                            {{ __('Project routes are where grouped health and deploy signals should land.') }}
                        @else
                            {{ __('Attach this server to a project if you want grouped health and deploy routing.') }}
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Current usage') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('These cards update from the server callback stream. New samples should arrive every minute.') }}
                    </p>
                </div>
            </div>

            @if ($latest)
                <p class="mt-4 text-xs text-brand-mist">
                    {{ __('Last sample') }}: {{ $latest->captured_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}
                </p>
            @endif

            <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('CPU') }}</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['cpu_pct']) ? number_format((float) $p['cpu_pct'], 1).'%' : '—' }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Memory') }}</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['mem_pct']) ? number_format((float) $p['mem_pct'], 1).'%' : '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-mist">{{ $fmtBytes(isset($p['mem_total_kb']) ? (int) $p['mem_total_kb'] * 1024 : null) }} {{ __('total') }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Disk') }} ({{ __('root') }})</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['disk_pct']) ? number_format((float) $p['disk_pct'], 1).'%' : '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-mist">
                        {{ $fmtBytes($p['disk_used_bytes'] ?? null) }} / {{ $fmtBytes($p['disk_total_bytes'] ?? null) }}
                    </dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Load avg') }}</dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums text-brand-ink">{{ isset($p['load_1m']) ? number_format((float) $p['load_1m'], 2) : '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-mist">
                        @if (isset($p['load_5m'], $p['load_15m']))
                            {{ number_format((float) $p['load_5m'], 2) }} / {{ number_format((float) $p['load_15m'], 2) }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="mt-6 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Headroom and pressure') }}</h3>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('These extra signals help explain whether slowdowns are capacity, disk, swap, uptime, or network related.') }}
                        </p>
                    </div>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Memory headroom') }}</dt>
                        <dd class="mt-2 text-base font-semibold text-brand-ink">{{ $fmtBytes($latestPayloadSummary['memory_available_bytes'] ?? null) }}</dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            {{ __('Available out of :total', ['total' => $fmtBytes($latestPayloadSummary['memory_total_bytes'] ?? null)]) }}
                        </dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Swap pressure') }}</dt>
                        <dd class="mt-2 text-base font-semibold text-brand-ink">
                            @if (isset($latestPayloadSummary['swap_pct']) && $latestPayloadSummary['swap_pct'] !== null)
                                {{ number_format((float) $latestPayloadSummary['swap_pct'], 1) }}%
                            @else
                                —
                            @endif
                        </dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            {{ $fmtBytes($latestPayloadSummary['swap_used_bytes'] ?? null) }} / {{ $fmtBytes($latestPayloadSummary['swap_total_bytes'] ?? null) }}
                        </dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Disk headroom') }}</dt>
                        <dd class="mt-2 text-base font-semibold text-brand-ink">{{ $fmtBytes($latestPayloadSummary['disk_free_bytes'] ?? null) }}</dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            {{ __('Inodes used: :pct', ['pct' => isset($latestPayloadSummary['inode_pct_root']) && $latestPayloadSummary['inode_pct_root'] !== null ? number_format((float) $latestPayloadSummary['inode_pct_root'], 1).'%' : '—']) }}
                        </dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Capacity fit') }}</dt>
                        <dd class="mt-2 text-base font-semibold text-brand-ink">
                            @if (isset($latestPayloadSummary['load_per_cpu_1m']) && $latestPayloadSummary['load_per_cpu_1m'] !== null)
                                {{ number_format((float) $latestPayloadSummary['load_per_cpu_1m'], 2) }} {{ __('load/core') }}
                            @else
                                —
                            @endif
                        </dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            {{ trans_choice(':count CPU core|:count CPU cores', (int) ($latestPayloadSummary['cpu_count'] ?? 0), ['count' => (int) ($latestPayloadSummary['cpu_count'] ?? 0)]) }}
                        </dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Uptime and traffic') }}</dt>
                        <dd class="mt-2 text-base font-semibold text-brand-ink">{{ $fmtDuration($latestPayloadSummary['uptime_seconds'] ?? null) }}</dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            {{ __('In :rx · Out :tx', ['rx' => $fmtRate($latestPayloadSummary['rx_bytes_per_sec'] ?? null), 'tx' => $fmtRate($latestPayloadSummary['tx_bytes_per_sec'] ?? null)]) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="{{ $card }} p-6 sm:p-8" wire:key="metrics-chart-{{ $latest?->id ?? 'none' }}">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent usage') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('One graph shows every metric on the same timeline (newest :n stored samples; left → right is oldest → newest).', ['n' => $chartPointLimit]) }}
                    </p>
                </div>
                @if ($chartFrom && $chartTo)
                    <p class="text-xs tabular-nums text-brand-mist">
                        {{ trans_choice(':count sample stored in Dply|:count samples stored in Dply', $storedSnapshotCount, ['count' => $storedSnapshotCount]) }}
                        <span class="text-brand-moss">·</span>
                        {{ $chartFrom->timezone($chartTimezone)->format('M j H:i') }}
                        —
                        {{ $chartTo->timezone($chartTimezone)->format('M j H:i') }}
                    </p>
                @elseif ($storedSnapshotCount > 0)
                    <p class="text-xs text-brand-mist">
                        {{ trans_choice(':count sample stored|:count samples stored', $storedSnapshotCount, ['count' => $storedSnapshotCount]) }}
                    </p>
                @endif
            </div>

            <div class="mt-6 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Site and deployment context') }}</h3>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('Server metrics stay host-level, but this panel helps relate spikes to sites and recent deployments running on the box.') }}
                        </p>
                    </div>
                    <p class="text-xs text-brand-mist">
                        {{ trans_choice(':count site on this server|:count sites on this server', (int) ($deploymentContext['site_count'] ?? 0), ['count' => (int) ($deploymentContext['site_count'] ?? 0)]) }}
                        <span class="text-brand-moss">·</span>
                        {{ trans_choice(':count active|:count active', (int) ($deploymentContext['active_site_count'] ?? 0), ['count' => (int) ($deploymentContext['active_site_count'] ?? 0)]) }}
                    </p>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Latest deployment') }}</dt>
                        <dd class="mt-2 text-sm font-semibold text-brand-ink">
                            @if (($deploymentContext['latest_deployment'] ?? null) !== null)
                                {{ strtoupper((string) ($deploymentContext['latest_deployment']->status ?? '')) }}
                            @else
                                {{ __('No deployments yet') }}
                            @endif
                        </dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            @if (($deploymentContext['latest_deployment'] ?? null) !== null)
                                {{ optional($deploymentContext['latest_deployment']->site)->name ?? __('Unknown site') }}
                                ·
                                {{ $deploymentContext['latest_deployment']->finished_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? __('In progress') }}
                            @else
                                {{ __('No attached site deployments have finished on this server yet.') }}
                            @endif
                        </dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Latest failed deploy') }}</dt>
                        <dd class="mt-2 text-sm font-semibold text-brand-ink">
                            @if (($deploymentContext['latest_failed_deployment'] ?? null) !== null)
                                {{ optional($deploymentContext['latest_failed_deployment']->site)->name ?? __('Unknown site') }}
                            @else
                                {{ __('No recent failures') }}
                            @endif
                        </dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            @if (($deploymentContext['latest_failed_deployment'] ?? null) !== null)
                                {{ $deploymentContext['latest_failed_deployment']->finished_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') ?? __('Failed before finish time was recorded') }}
                            @else
                                {{ __('Nothing on this server is currently flagged with a failed deployment record.') }}
                            @endif
                        </dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 p-4">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Most recent correlated event') }}</dt>
                        <dd class="mt-2 text-sm font-semibold text-brand-ink">
                            @if (($deploymentContext['latest_correlation'] ?? null) !== null)
                                {{ str($deploymentContext['latest_correlation']['type'] ?? 'event')->replace('_', ' ')->title() }}
                            @else
                                {{ __('No recent correlated activity') }}
                            @endif
                        </dd>
                        <dd class="mt-1 text-xs text-brand-mist">
                            @if (($deploymentContext['latest_correlation']['finished_at'] ?? null))
                                {{ \Illuminate\Support\Carbon::parse($deploymentContext['latest_correlation']['finished_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}
                            @elseif (($deploymentContext['latest_correlation']['completed_at'] ?? null))
                                {{ \Illuminate\Support\Carbon::parse($deploymentContext['latest_correlation']['completed_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}
                            @elseif (($deploymentContext['latest_correlation']['at'] ?? null))
                                {{ \Illuminate\Support\Carbon::parse($deploymentContext['latest_correlation']['at'])->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}
                            @else
                                {{ __('Useful when a spike follows a deploy, firewall change, cron run, or remote task.') }}
                            @endif
                        </dd>
                    </div>
                </dl>

                @if (! empty($deploymentContext['site_summaries'] ?? []))
                    <div class="mt-4 flex flex-wrap gap-3">
                        @foreach (($deploymentContext['site_summaries'] ?? []) as $siteSummary)
                            <div class="rounded-full border border-brand-ink/10 bg-white/80 px-3 py-1.5 text-xs text-brand-ink">
                                <span class="font-semibold">{{ $siteSummary['name'] }}</span>
                                <span class="text-brand-mist">· {{ strtoupper((string) ($siteSummary['last_deploy_status'] ?? $siteSummary['status'])) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($chartSnapshots->isEmpty())
                <p class="mt-6 text-sm text-brand-mist">{{ __('No history yet. Once the remote monitor callback starts posting samples, the graph will populate automatically.') }}</p>
            @else
                <div class="mt-6">
                    <x-metrics-combined-chart :snapshots="$chartSnapshots" />
                </div>
            @endif
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.install-monitoring-confirm-modal')
    </x-slot>
</x-server-workspace-layout>
