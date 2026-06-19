@props([
    'histogram',
])

@php
    $h = is_array($histogram) ? $histogram : [];
    $buckets = $h['buckets'] ?? [];
    $events = $h['events'] ?? [];
    $max = max(1, (int) ($h['max'] ?? 1));
    $gran = $h['granularity'] ?? 'hour';
    $focused = (bool) ($h['focused'] ?? false);
    $available = (bool) ($h['available'] ?? false);
    $isLeaf = $gran === 'minute';

    $grains = ['day' => __('Day'), 'hour' => __('Hour'), 'minute' => __('Minute')];
    $eventTone = [
        'deploy' => 'bg-brand-forest',
        'error' => 'bg-rose-500',
        'incident' => 'bg-amber-500',
    ];
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
        <div class="flex items-center gap-3">
            <x-icon-badge>
                <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Events vs logs') }}</h3>
                <p class="text-xs text-brand-moss">{{ __('Log volume over time with deploys, errors and incidents overlaid.') }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($focused)
                <button type="button" wire:click="resetLogHistogram" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-moss hover:bg-brand-sand/30">
                    <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" /> {{ __('Zoom out') }}
                </button>
            @endif
            <div class="inline-flex overflow-hidden rounded-lg border border-brand-ink/15">
                @foreach ($grains as $key => $label)
                    <button type="button" wire:click="setLogHistogramGranularity('{{ $key }}')"
                        @class([
                            'px-2.5 py-1 text-xs font-semibold transition',
                            'bg-brand-forest text-white' => $gran === $key,
                            'bg-white text-brand-ink hover:bg-brand-sand/30' => $gran !== $key,
                        ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="px-6 py-5 sm:px-7">
        @unless ($available)
            <div class="flex h-44 items-center justify-center rounded-xl bg-brand-sand/30 text-sm text-brand-mist">
                {{ __('Log store unavailable — no histogram to show.') }}
            </div>
        @else
            <div x-data="{ tip: null }" class="relative">
                {{-- Chart area --}}
                <div class="relative h-48 rounded-xl bg-brand-sand/20 px-2 pt-3">
                    {{-- Event markers (vertical guides + dots along the top) --}}
                    <div class="pointer-events-none absolute inset-x-2 top-3 bottom-6">
                        @foreach ($events as $event)
                            <div class="absolute top-0 bottom-0" style="left: {{ $event['x_pct'] }}%">
                                <div class="absolute inset-y-0 w-px -translate-x-1/2 {{ $eventTone[$event['type']] ?? 'bg-brand-ink/30' }} opacity-25"></div>
                                <button type="button"
                                    class="pointer-events-auto absolute top-0 h-2.5 w-2.5 -translate-x-1/2 -translate-y-1 rounded-full ring-2 ring-white {{ $eventTone[$event['type']] ?? 'bg-brand-ink/40' }}"
                                    @mouseenter="tip = { label: @js($event['label']), time: @js($event['time']), x: {{ $event['x_pct'] }} }"
                                    @mouseleave="tip = null"
                                    aria-label="{{ $event['label'] }}"
                                ></button>
                            </div>
                        @endforeach
                    </div>

                    {{-- Bars --}}
                    <div class="flex h-[calc(100%-1.5rem)] items-end gap-px">
                        @foreach ($buckets as $b)
                            @php
                                $errH = round(($b['errors'] / $max) * 100, 2);
                                $warnH = round(($b['warns'] / $max) * 100, 2);
                                $otherH = round(($b['others'] / $max) * 100, 2);
                                $hasData = $b['total'] > 0;
                                $title = $b['label'].' · '.number_format($b['total']).' lines'
                                    .($b['errors'] ? ' · '.number_format($b['errors']).' err' : '')
                                    .($b['warns'] ? ' · '.number_format($b['warns']).' warn' : '');
                            @endphp
                            <button type="button"
                                wire:click="drillLogHistogram('{{ $b['start'] }}')"
                                title="{{ $title }}"
                                @class([
                                    'group relative flex h-full min-w-0 flex-1 flex-col justify-end',
                                    'cursor-pointer' => $hasData || true,
                                ])>
                                <span class="flex flex-col justify-end overflow-hidden rounded-sm transition group-hover:opacity-80" style="height: {{ max($errH + $warnH + $otherH, $hasData ? 2 : 0) }}%">
                                    @if ($errH > 0)
                                        <span class="block bg-rose-500" style="height: {{ $errH }}%"></span>
                                    @endif
                                    @if ($warnH > 0)
                                        <span class="block bg-amber-400" style="height: {{ $warnH }}%"></span>
                                    @endif
                                    @if ($otherH > 0)
                                        <span class="block bg-brand-sage/70" style="height: {{ $otherH }}%"></span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>

                    {{-- Event tooltip --}}
                    <div x-cloak x-show="tip !== null" x-transition.opacity.duration.100ms
                        class="pointer-events-none absolute top-0 z-10 w-56 rounded-lg border border-brand-ink/10 bg-white/95 p-3 text-xs shadow-lg backdrop-blur"
                        :style="tip ? (tip.x > 60 ? `right: ${100 - tip.x}%;` : `left: ${tip.x}%;`) : ''">
                        <p class="font-semibold text-brand-ink" x-text="tip?.label"></p>
                        <p class="mt-0.5 font-mono text-[11px] text-brand-moss" x-text="tip ? tip.time + ' (UTC)' : ''"></p>
                    </div>
                </div>

                {{-- X-axis labels (first / middle / last bucket) --}}
                @php
                    $n = count($buckets);
                    $ticks = $n > 0 ? array_values(array_unique([0, intdiv($n - 1, 2), $n - 1])) : [];
                @endphp
                <div class="relative mt-1 h-4 text-[10px] tabular-nums text-brand-mist">
                    @foreach ($ticks as $i)
                        <span class="absolute -translate-x-1/2 whitespace-nowrap" style="left: {{ $buckets[$i]['x_pct'] }}%">{{ $buckets[$i]['label'] }}</span>
                    @endforeach
                </div>

                {{-- Legend + hint --}}
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-[11px] text-brand-moss">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-brand-sage/70"></span>{{ __('Logs') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-amber-400"></span>{{ __('Warnings') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-rose-500"></span>{{ __('Errors') }}</span>
                    <span class="ml-1 inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-brand-forest ring-2 ring-white"></span>{{ __('Deploy') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white"></span>{{ __('Error') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500 ring-2 ring-white"></span>{{ __('Incident') }}</span>
                </div>
                <p class="mt-2 text-[11px] text-brand-mist">
                    {{ $isLeaf
                        ? __('Click a minute to load its log lines below.')
                        : __('Click a bar to zoom in (:grain → finer). Hover a dot for the event.', ['grain' => $grains[$gran] ?? $gran]) }}
                </p>
            </div>
        @endunless
    </div>
</section>
