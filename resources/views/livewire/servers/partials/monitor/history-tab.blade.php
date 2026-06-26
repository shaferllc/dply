    @if ($showMetricsPanels)
        @php
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
            <x-empty-state
                class="mt-6"
                borderless
                icon="heroicon-o-chart-bar"
                :title="__('No history in this range yet')"
                :description="__('Once the monitor agent reports samples, these panels populate automatically — try a wider window like 24H or 7D.')"
            />
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
