@php
    /** @var array|null $history */
    /** @var \App\Models\SiteUptimeMonitor $monitor */
    $uptime = $history['uptime'] ?? ['24h' => null, '7d' => null, '30d' => null];
    $latency = $history['latency'] ?? [];
    $incidents = $history['incidents'] ?? collect();
    $hasData = $history['has_data'] ?? false;

    $severityStyles = [
        'outage' => ['dot' => 'bg-red-500', 'text' => 'text-red-700', 'label' => __('Outage')],
        'degraded' => ['dot' => 'bg-amber-500', 'text' => 'text-amber-700', 'label' => __('Degraded')],
    ];
@endphp

<div class="space-y-5">
    @if (! $hasData)
        <p class="text-sm text-brand-moss">{{ __('No checks recorded yet — history appears once this monitor has run a few times.') }}</p>
    @else
        {{-- Uptime % badges --}}
        <div class="grid grid-cols-3 gap-3">
            @foreach (['24h' => __('24h'), '7d' => __('7 days'), '30d' => __('30 days')] as $key => $label)
                @php $pct = $uptime[$key] ?? null; @endphp
                <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                    <p class="text-[10px] font-medium uppercase tracking-wide text-brand-moss/70">{{ $label }}</p>
                    <p class="mt-0.5 text-lg font-bold {{ $pct === null ? 'text-brand-mist' : ($pct >= 99.9 ? 'text-emerald-700' : ($pct >= 95 ? 'text-amber-700' : 'text-red-700')) }}">
                        {{ $pct === null ? '—' : number_format($pct, 2).'%' }}
                    </p>
                    <p class="text-[10px] text-brand-mist">{{ __('uptime') }}</p>
                </div>
            @endforeach
        </div>

        {{-- Latency sparkline (24h) --}}
        @if (! empty($latency))
            <div>
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Response time (ms) · 24h') }}</p>
                <x-metrics-line-chart :series="$latency" :yMax="null" colorClass="text-sky-600" format="load" heightClass="h-20" />
            </div>
        @endif

        {{-- Incident timeline --}}
        <div>
            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Recent incidents') }}</p>
            @if ($incidents->isEmpty())
                <p class="text-sm text-brand-moss">{{ __('No incidents recorded — this monitor has stayed operational.') }}</p>
            @else
                <ul class="space-y-2">
                    @foreach ($incidents as $incident)
                        @php
                            $sev = $severityStyles[$incident->severity] ?? $severityStyles['outage'];
                            $tz = config('app.timezone');
                            $started = $incident->started_at?->timezone($tz);
                            $resolved = $incident->resolved_at?->timezone($tz);
                            $duration = $incident->resolved_at !== null
                                ? $incident->started_at->diffForHumans($incident->resolved_at, true)
                                : $incident->started_at->diffForHumans(null, true);
                        @endphp
                        <li class="flex items-start gap-3 rounded-lg border border-brand-ink/10 bg-white px-3 py-2" wire:key="incident-{{ $incident->id }}">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $sev['dot'] }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold {{ $sev['text'] }}">{{ $sev['label'] }}</span>
                                    @if ($incident->isOngoing())
                                        <span class="inline-flex items-center rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 ring-1 ring-inset ring-red-200">{{ __('Ongoing') }}</span>
                                    @endif
                                    <span class="text-xs text-brand-moss">{{ $duration }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-brand-moss">
                                    {{ $started?->toDayDateTimeString() }}
                                    @if ($resolved) → {{ $resolved->toDayDateTimeString() }} @endif
                                </p>
                                @if ($incident->cause)
                                    <p class="mt-0.5 text-xs text-brand-mist truncate" title="{{ $incident->cause }}">{{ $incident->cause }}</p>
                                @endif
                            </div>
                            @if ($this->showWindowLogCorrelation)
                                <button type="button" wire:click="openLogsForIncident('{{ $incident->id }}')"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                                    title="{{ __('Host logs around this incident') }}">
                                    <x-heroicon-m-bars-3-bottom-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Logs') }}
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
