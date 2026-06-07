<div class="{{ $card }}">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25 sm:inline-flex">
                <x-heroicon-o-chart-pie class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Monitor') }}</p>
                <h2 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ __('Monitor status') }}</h2>
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
                <x-secondary-button size="sm" type="button" wire:click="queueMonitoringProbe" wire:loading.attr="disabled" wire:target="queueMonitoringProbe">
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="queueMonitoringProbe" aria-hidden="true" />
                    <span wire:loading.remove wire:target="queueMonitoringProbe">{{ __('Recheck status') }}</span>
                    <span wire:loading wire:target="queueMonitoringProbe">{{ __('Queueing…') }}</span>
                </x-secondary-button>
                @if (! $monitorHealthy)
                    <x-secondary-button size="sm" type="button" wire:click="setMonitorWorkspaceTab('diagnostics')">
                        <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Open Diagnostics') }}
                    </x-secondary-button>
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
        <div class="{{ $card }}" x-data="{ editing: @js($editingThresholds) }" x-init="$watch('editing', value => { if (!value) $wire.editingThresholds = false; })">
            <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Thresholds') }}</p>
                        <h2 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ __('Alert thresholds') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Values that trigger warning colors on KPIs and Insights alerts.') }}
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    @if ($editingThresholds)
                        <x-secondary-button
                            size="sm"
                            type="button"
                            wire:click="cancelEditingThresholds"
                        >
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-primary-button
                            size="sm"
                            type="button"
                            wire:click="saveThresholdSettings"
                            wire:loading.attr="disabled"
                        >
                            <span wire:loading.remove wire:target="saveThresholdSettings">{{ __('Save thresholds') }}</span>
                            <span wire:loading wire:target="saveThresholdSettings">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    @else
                        <x-secondary-button
                            size="sm"
                            type="button"
                            wire:click="startEditingThresholds"
                        >
                            <x-heroicon-o-pencil class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Edit thresholds') }}
                        </x-secondary-button>
                        @if ($thresholds['cpu'] !== (float) config('insights.thresholds.cpu_warn_pct', 85) ||
                              $thresholds['mem'] !== (float) config('insights.thresholds.mem_warn_pct', 85) ||
                              $thresholds['load'] !== (float) config('insights.thresholds.load_warn', 4.0))
                            <x-secondary-button
                                size="sm"
                                type="button"
                                wire:click="resetThresholdsToDefaults"
                                wire:confirm="{{ __('Revert to organization defaults?') }}"
                            >
                                {{ __('Reset to defaults') }}
                            </x-secondary-button>
                        @endif
                    @endif
                </div>
            </div>

            <div class="px-6 py-6 sm:px-8">
            @if ($editingThresholds)
                <form wire:submit="saveThresholdSettings" class="grid grid-cols-1 gap-5 sm:grid-cols-3">
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
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
        </div>
    @endif
@endif
