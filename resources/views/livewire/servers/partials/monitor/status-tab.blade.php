{{-- Nested inside the merged Metrics card — no second outer card/header. --}}
<div>
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-chart-pie"
        :title="__('Monitor status')"
        :note="$headlineCopy.' · '.__('The server pushes fresh metrics back to Dply every minute.')"
    >
        <x-slot:actions>
            <span class="inline-flex h-6 items-center gap-1 rounded-full px-2 text-xs font-semibold ring-1 {{ $statusChipClasses }}">
                <x-dynamic-component :component="$statusChipIcon" class="h-3 w-3" aria-hidden="true" />
                {{ $statusChipLabel }}
            </span>
            @if ($lastGuestSampleAt)
                <span class="hidden text-2xs text-brand-mist sm:inline">{{ __('last sample :time', ['time' => $lastGuestSampleAt->diffForHumans()]) }}</span>
            @endif
            <span class="hidden text-2xs text-brand-mist sm:inline">{{ __('Installed and running') }}</span>
            @if (! $isDeployer)
                <button type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" wire:target="queueMonitoringProbe" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="queueMonitoringProbe" aria-hidden="true" />
                    <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck') }}</span>
                    <span wire:loading wire:target="queueMonitoringProbe">{{ __('…') }}</span>
                </button>
                @if (! $monitorHealthy)
                    <button type="button" wire:click="setMonitorWorkspaceTab('diagnostics')" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Diagnostics') }}
                    </button>
                @endif
            @endif
        </x-slot:actions>
    </x-workspace-panel-head>

    <dl class="grid grid-cols-1 gap-px border-b border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($checks as $c)
            <div class="bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $c['label'] }}</dt>
                <dd class="mt-0.5 flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
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

    @if ($routingSummary['server_routes'] === 0 && $opsReady)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200/80 bg-amber-50/50 px-3 py-1.5 sm:px-4">
            <p class="min-w-0 text-xs text-amber-900">
                <span class="font-semibold">{{ __('No notification routes') }}</span>
                <span class="text-amber-800/90">— {{ __('add a channel for stale metrics and threshold alerts.') }}</span>
            </p>
            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    wire:click="setMonitorWorkspaceTab('notifications')"
                    class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Add route') }}
                </button>
                <a
                    href="{{ route('servers.notifications', $server) }}"
                    wire:navigate
                    class="inline-flex h-6 items-center rounded-md border border-amber-300/80 bg-white px-2 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-100"
                >
                    {{ __('Manage') }}
                </a>
            </div>
        </div>
    @endif

    @if ($showMetricsPanels)
        @php
            $cpuPct = isset($p['cpu_pct']) ? max(0, min(100, (float) $p['cpu_pct'])) : null;
            $memPct = isset($p['mem_pct']) ? max(0, min(100, (float) $p['mem_pct'])) : null;
            $diskPct = isset($p['disk_pct']) ? max(0, min(100, (float) $p['disk_pct'])) : null;
            $loadPerCpu = isset($latestPayloadSummary['load_per_cpu_1m']) ? (float) $latestPayloadSummary['load_per_cpu_1m'] : null;
            $loadFillPct = $loadPerCpu !== null ? max(0, min(100, $loadPerCpu * 100)) : null;
            $cpuTone = $kpiTone($metricStatuses['cpu']);
            $memTone = $kpiTone($metricStatuses['mem']);
            $diskTone = $kpiTone($metricStatuses['disk']);
            $loadTone = $kpiTone($metricStatuses['load']);
        @endphp
        <div class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-chart-bar"
                :title="__('Current usage')"
                :note="$latest ? __('Last sample :time', ['time' => \App\Support\Servers\ServerDateFormatter::format($latest->captured_at, $server)]) : null"
            >
                <x-slot:actions>
                    <a href="{{ route('servers.insights', $server) }}" wire:navigate class="inline-flex h-6 items-center gap-1 text-xs font-medium text-brand-moss hover:text-brand-ink">
                        {{ __('View deploy correlations on Insights') }}
                        <x-heroicon-o-arrow-right class="h-3 w-3" aria-hidden="true" />
                    </a>
                </x-slot:actions>
            </x-workspace-panel-head>

            <div class="px-3 py-2.5 sm:px-4">
            <dl class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="inline-flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <x-heroicon-o-cpu-chip class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                            {{ __('CPU') }}
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums leading-none {{ $cpuTone['kpi'] }}">{{ $cpuPct !== null ? number_format($cpuPct, 1).'%' : '—' }}</dd>
                    </div>
                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                        <div class="h-1 rounded-full {{ $cpuTone['bar'] }}" style="width: {{ $cpuPct ?? 0 }}%"></div>
                    </div>
                    <dd class="mt-1 text-2xs text-brand-mist">
                        {{ trans_choice(':count core|:count cores', (int) ($latestPayloadSummary['cpu_count'] ?? 0), ['count' => (int) ($latestPayloadSummary['cpu_count'] ?? 0)]) }}
                    </dd>
                </div>

                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="inline-flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <x-heroicon-o-circle-stack class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                            {{ __('Memory') }}
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums leading-none {{ $memTone['kpi'] }}">{{ $memPct !== null ? number_format($memPct, 1).'%' : '—' }}</dd>
                    </div>
                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                        <div class="h-1 rounded-full {{ $memTone['bar'] }}" style="width: {{ $memPct ?? 0 }}%"></div>
                    </div>
                    <dd class="mt-1 text-2xs text-brand-mist">
                        {{ $fmtBytes($latestPayloadSummary['memory_available_bytes'] ?? null) }} {{ __('free of') }} {{ $fmtBytes(isset($p['mem_total_kb']) ? (int) $p['mem_total_kb'] * 1024 : null) }}
                    </dd>
                </div>

                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="inline-flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <x-heroicon-o-server-stack class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                            {{ __('Disk') }} ({{ __('root') }})
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums leading-none {{ $diskTone['kpi'] }}">{{ $diskPct !== null ? number_format($diskPct, 1).'%' : '—' }}</dd>
                    </div>
                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                        <div class="h-1 rounded-full {{ $diskTone['bar'] }}" style="width: {{ $diskPct ?? 0 }}%"></div>
                    </div>
                    <dd class="mt-1 text-2xs text-brand-mist">
                        {{ $fmtBytes($latestPayloadSummary['disk_free_bytes'] ?? null) }} {{ __('free of') }} {{ $fmtBytes($p['disk_total_bytes'] ?? null) }}
                    </dd>
                </div>

                <div class="rounded-lg border border-brand-ink/10 bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="inline-flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <x-heroicon-o-chart-bar class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                            {{ __('Load avg') }}
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums leading-none {{ $loadTone['kpi'] }}">{{ isset($p['load_1m']) ? number_format((float) $p['load_1m'], 2) : '—' }}</dd>
                    </div>
                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-brand-ink/8" aria-hidden="true">
                        <div class="h-1 rounded-full {{ $loadTone['bar'] }}" style="width: {{ $loadFillPct ?? 0 }}%"></div>
                    </div>
                    <dd class="mt-1 text-2xs text-brand-mist">
                        @if (isset($p['load_5m'], $p['load_15m']))
                            {{ number_format((float) $p['load_5m'], 2) }} / {{ number_format((float) $p['load_15m'], 2) }} (5m / 15m)
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Uptime') }}</p>
                        <p class="text-sm font-semibold tabular-nums text-brand-ink">{{ $fmtDuration($latestPayloadSummary['uptime_seconds'] ?? null) }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-sky-700 ring-1 ring-sky-200">
                        <x-heroicon-o-arrow-down class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-sky-700">{{ __('Inbound') }}</p>
                        <p class="text-sm font-semibold tabular-nums text-brand-ink">{{ $fmtRate($latestPayloadSummary['rx_bytes_per_sec'] ?? null) }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-violet-700 ring-1 ring-violet-200">
                        <x-heroicon-o-arrow-up class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-violet-700">{{ __('Outbound') }}</p>
                        <p class="text-sm font-semibold tabular-nums text-brand-ink">{{ $fmtRate($latestPayloadSummary['tx_bytes_per_sec'] ?? null) }}</p>
                    </div>
                </div>
            </div>
            </div>
        </div>

        @if (! $isDeployer)
            <div x-data="{ editing: @js($editingThresholds) }" x-init="$watch('editing', value => { if (!value) $wire.editingThresholds = false; })">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-adjustments-horizontal"
                    :title="__('Alert thresholds')"
                    :note="__('Values that trigger warning colors on KPIs and Insights alerts.')"
                >
                    <x-slot:actions>
                        @if ($editingThresholds)
                            <button type="button" wire:click="cancelEditingThresholds" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                {{ __('Cancel') }}
                            </button>
                            <button type="button" wire:click="saveThresholdSettings" wire:loading.attr="disabled" class="inline-flex h-6 items-center rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">
                                <span wire:loading.remove wire:target="saveThresholdSettings">{{ __('Save') }}</span>
                                <span wire:loading wire:target="saveThresholdSettings">{{ __('Saving…') }}</span>
                            </button>
                        @else
                            <button type="button" wire:click="startEditingThresholds" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-pencil class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Edit') }}
                            </button>
                            @if ($thresholds['cpu'] !== (float) config('insights.thresholds.cpu_warn_pct', 85) ||
                                  $thresholds['mem'] !== (float) config('insights.thresholds.mem_warn_pct', 85) ||
                                  $thresholds['load'] !== (float) config('insights.thresholds.load_warn', 4.0))
                                <button type="button" wire:click="resetThresholdsToDefaults" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    {{ __('Reset') }}
                                </button>
                            @endif
                        @endif
                    </x-slot:actions>
                </x-workspace-panel-head>

                <div class="px-3 py-2.5 sm:px-4">
                    @if ($editingThresholds)
                        <form wire:submit="saveThresholdSettings" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
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
                                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
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
                                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
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
                                        class="block w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                    />
                                </div>
                                <x-input-error :messages="$errors->get('thresholdLoadInput')" class="mt-2" />
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Default: :value', ['value' => config('insights.thresholds.load_warn', 4.0)]) }}</p>
                            </div>
                        </form>
                    @else
                        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('CPU warning') }}</dt>
                                <dd class="mt-1 flex items-baseline gap-1">
                                    <span class="text-lg font-semibold tabular-nums text-brand-ink">{{ $thresholds['cpu'] }}</span>
                                    <span class="text-sm text-brand-moss">%</span>
                                </dd>
                            </div>
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Memory warning') }}</dt>
                                <dd class="mt-1 flex items-baseline gap-1">
                                    <span class="text-lg font-semibold tabular-nums text-brand-ink">{{ $thresholds['mem'] }}</span>
                                    <span class="text-sm text-brand-moss">%</span>
                                </dd>
                            </div>
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Load warning') }}</dt>
                                <dd class="mt-1 flex items-baseline gap-1">
                                    <span class="text-lg font-semibold tabular-nums text-brand-ink">{{ $thresholds['load'] }}</span>
                                </dd>
                            </div>
                        </dl>
                        @if ($thresholds['cpu'] !== (float) config('insights.thresholds.cpu_warn_pct', 85) ||
                              $thresholds['mem'] !== (float) config('insights.thresholds.mem_warn_pct', 85) ||
                              $thresholds['load'] !== (float) config('insights.thresholds.load_warn', 4.0))
                            <p class="mt-4 text-xs text-brand-sage">
                                <x-heroicon-o-information-circle class="inline h-4 w-4" aria-hidden="true" />
                                {{ __('Using custom server thresholds. Organization defaults shown in help text.') }}
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
