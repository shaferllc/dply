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
@endphp

<x-server-workspace-layout
    :server="$server"
    active="monitor"
    :title="__('Metrics')"
    :description="__('When Python is missing, use Step 1 below or Services → Install Python for monitoring. With Python ready, use Refresh now to sample the server. After the first successful collect, the guest can push metrics on a schedule (see metrics-callback.env on the server). History stays in Dply.')"
>
    @if ($opsReady && $probePending)
        <div wire:poll.{{ $pollProbeSeconds }}s="syncMonitoringProbeStatus" class="hidden" aria-hidden="true"></div>
    @endif

    @if ($servicesRemoteTaskId)
        <div wire:poll.{{ $pollRemoteTaskSeconds }}s="syncServicesRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif
    @if ($autoRefresh)
        <div wire:poll.{{ $pollAutoRefreshSeconds }}s="collectMetrics" class="hidden" aria-hidden="true"></div>
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
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Step 1 — Enable metrics') }}</p>
            <h2 class="mt-2 text-xl font-bold tracking-tight text-brand-ink">{{ __('Install monitoring on this server') }}</h2>
            <p class="mt-3 max-w-3xl text-sm leading-relaxed text-brand-moss">
                {{ __('Dply samples CPU, memory, disk, and load over SSH using a small Python script installed under ~/.dply/bin/. Use the button below to install Python 3 (Debian/Ubuntu via apt) and copy that script. After it finishes, press Refresh now in the next section.') }}
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
                        {{ __('Install Python for monitoring') }}
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

    <div class="{{ $card }} p-6 sm:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Current usage') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">
                    @if ($canCollectMetrics)
                        {{ __('Each refresh stores a data point for the chart below.') }}
                    @else
                        {{ __('Fix the monitoring status above to collect new samples.') }}
                    @endif
                </p>
                @if ($guestPushCronExpression && $canCollectMetrics)
                    <p class="mt-2 text-xs text-brand-mist font-mono">{{ __('Guest push crontab schedule') }}: {{ $guestPushCronExpression }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-brand-ink @if (! $canCollectMetrics) opacity-50 @endif">
                    <input type="checkbox" wire:model.live="autoRefresh" @disabled(! $canCollectMetrics) class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/30 disabled:cursor-not-allowed" />
                    {{ __('Auto-refresh every 60s') }}
                </label>
                <button
                    type="button"
                    wire:click="collectMetrics"
                    wire:loading.attr="disabled"
                    @disabled(! $opsReady || ! $canCollectMetrics)
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="collectMetrics">{{ __('Refresh now') }}</span>
                    <span wire:loading wire:target="collectMetrics">{{ __('Collecting…') }}</span>
                </button>
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

        @if ($chartSnapshots->isEmpty())
            <p class="mt-6 text-sm text-brand-mist">{{ __('No history yet. Choose Refresh now (or enable auto-refresh) to record samples and build the graph.') }}</p>
        @else
            <div class="mt-6">
                <x-metrics-combined-chart :snapshots="$chartSnapshots" />
            </div>
        @endif
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.install-monitoring-confirm-modal')
    </x-slot>
</x-server-workspace-layout>
