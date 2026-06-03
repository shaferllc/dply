@php
    $m = $overviewMetrics ?? [
        'window_days' => 30,
        'total' => 0,
        'success_count' => 0,
        'failed_count' => 0,
        'success_rate' => null,
        'median_duration_ms' => null,
        'daily' => [],
        'top_failure_phase' => null,
    ];

    $maxDaily = collect($m['daily'])->max('total') ?: 1;
    $sparkCount = max(count($m['daily']), 1);
@endphp

<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Trends') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Last :days days', ['days' => $m['window_days']]) }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('How deploys for this site have been going recently.') }}</p>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-px bg-brand-ink/10 sm:grid-cols-4">
            <div class="bg-white px-6 py-5 sm:px-8">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Deploys') }}</dt>
                <dd class="mt-2 flex items-baseline gap-2">
                    <span class="font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $m['total'] }}</span>
                    <span class="text-[11px] text-brand-moss">{{ __(':window-day total', ['window' => $m['window_days']]) }}</span>
                </dd>
            </div>
            <div class="bg-white px-6 py-5 sm:px-8">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Success rate') }}</dt>
                <dd class="mt-2 flex items-baseline gap-2">
                    @if ($m['success_rate'] !== null)
                        <span @class([
                            'font-mono text-2xl font-semibold tabular-nums',
                            'text-emerald-700' => $m['success_rate'] >= 90,
                            'text-amber-700' => $m['success_rate'] >= 60 && $m['success_rate'] < 90,
                            'text-rose-700' => $m['success_rate'] < 60,
                        ])>{{ $m['success_rate'] }}%</span>
                        <span class="text-[11px] text-brand-moss">{{ $m['success_count'] }} / {{ $m['success_count'] + $m['failed_count'] }}</span>
                    @else
                        <span class="font-mono text-2xl font-semibold tabular-nums text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="bg-white px-6 py-5 sm:px-8">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Median duration') }}</dt>
                <dd class="mt-2 flex items-baseline gap-2">
                    @if ($m['median_duration_ms'] !== null)
                        <span class="font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($m['median_duration_ms'] / 1000, 1) }}<span class="text-base text-brand-moss">s</span></span>
                    @else
                        <span class="font-mono text-2xl font-semibold tabular-nums text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div class="bg-white px-6 py-5 sm:px-8">
                <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Top failure phase') }}</dt>
                <dd class="mt-2">
                    @if ($m['top_failure_phase'])
                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-inset ring-rose-200">{{ $m['top_failure_phase'] }}</span>
                    @elseif ($m['failed_count'] === 0 && $m['total'] > 0)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-inset ring-emerald-200">{{ __('No failures') }}</span>
                    @else
                        <span class="text-sm text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="border-t border-brand-ink/10 bg-white px-6 py-5 sm:px-8">
            <div class="flex items-baseline justify-between gap-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Deploys per day') }}</p>
                <p class="text-[11px] text-brand-mist">{{ __('peak :n', ['n' => $maxDaily]) }}</p>
            </div>
            <div class="mt-3 flex h-24 items-end gap-px overflow-hidden rounded-lg bg-brand-sand/30 px-1 py-1">
                @foreach ($m['daily'] as $day)
                    @php
                        $heightPct = $maxDaily > 0 ? round(($day['total'] / $maxDaily) * 100) : 0;
                        $failedRatio = $day['total'] > 0 ? ($day['failed'] / $day['total']) : 0;
                    @endphp
                    <div class="group relative flex h-full flex-1 flex-col justify-end" title="{{ $day['date'] }} · {{ $day['total'] }} deploys ({{ $day['success'] }} ok, {{ $day['failed'] }} failed)">
                        <div @class([
                            'w-full rounded-sm transition-colors',
                            'bg-emerald-500/70 group-hover:bg-emerald-600' => $day['total'] > 0 && $failedRatio < 0.5,
                            'bg-rose-500/70 group-hover:bg-rose-600' => $day['total'] > 0 && $failedRatio >= 0.5,
                            'bg-brand-mist/30' => $day['total'] === 0,
                        ]) style="height: {{ $day['total'] === 0 ? 4 : max(6, $heightPct) }}%"></div>
                    </div>
                @endforeach
            </div>
            @if ($sparkCount > 0)
                <div class="mt-2 flex justify-between text-[10px] text-brand-mist">
                    <span>{{ collect($m['daily'])->first()['date'] ?? '' }}</span>
                    <span>{{ collect($m['daily'])->last()['date'] ?? '' }}</span>
                </div>
            @endif
        </div>
    </section>
</div>
