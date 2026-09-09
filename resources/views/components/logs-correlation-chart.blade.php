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

{{-- Flush section, not a dply-card. Sibling panels in the Logs workspace
     (Shipped logs, dply Logs, the aggregator block) are plain
     `border-b` sections; the card's radius + shadow made this one float
     out of the stack. --}}
<section class="overflow-hidden border-b border-brand-ink/10">
    {{-- Dense head, matching the workspace panels elsewhere: title and note on one
         line instead of a 5rem-tall icon-badge block above a two-line stack. --}}
    <x-workspace-panel-head
        dense
        icon="heroicon-o-chart-bar"
        :title="__('Events vs logs')"
        :note="__('Log volume over time with deploys, errors and incidents overlaid.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
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
            <button type="button" wire:click="toggleLogCorrelation" title="{{ __('Hide graph') }}" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-moss hover:bg-brand-sand/30">
                <x-heroicon-o-eye-slash class="h-3.5 w-3.5" aria-hidden="true" /> {{ __('Hide') }}
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div @class(['px-6 sm:px-7', 'py-3' => $available, 'py-2.5' => ! $available])>
        @unless ($available)
            {{-- Was an h-44 block reserving chart-sized space for a one-line
                 message. Nothing is going to fill it while the store is
                 unreachable, so collapse to a single row. --}}
            <div class="flex items-center gap-2 rounded-lg bg-brand-sand/30 px-3 py-2 text-xs text-brand-moss">
                <x-heroicon-o-signal-slash class="h-3.5 w-3.5 shrink-0 text-brand-ink/40" aria-hidden="true" />
                <span>{{ __('Log store unavailable — no histogram to show.') }}</span>
            </div>
        @else
            <div x-data="{ tip: null }" class="relative">
                {{-- Chart area --}}
                <div class="relative h-28 rounded-xl bg-brand-sand/20 px-2 pt-2">
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
                        <p class="mt-0.5 font-mono text-xs text-brand-moss" x-text="tip ? tip.time + ' (UTC)' : ''"></p>
                    </div>
                </div>

                {{-- X-axis labels (first / middle / last bucket) --}}
                @php
                    $n = count($buckets);
                    $ticks = $n > 0 ? array_values(array_unique([0, intdiv($n - 1, 2), $n - 1])) : [];
                @endphp
                <div class="relative mt-1 h-4 text-2xs tabular-nums text-brand-mist">
                    @foreach ($ticks as $i)
                        <span class="absolute -translate-x-1/2 whitespace-nowrap" style="left: {{ $buckets[$i]['x_pct'] }}%">{{ $buckets[$i]['label'] }}</span>
                    @endforeach
                </div>

                {{-- Legend + hint --}}
                {{-- Legend and the interaction hint share one row: the hint was a third
                     stacked block under an already-tall chart. --}}
                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-2xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-brand-sage/70"></span>{{ __('Logs') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-amber-400"></span>{{ __('Warnings') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-rose-500"></span>{{ __('Errors') }}</span>
                    <span class="ml-1 inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-brand-forest ring-2 ring-white"></span>{{ __('Deploy') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white"></span>{{ __('Error') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500 ring-2 ring-white"></span>{{ __('Incident') }}</span>
                    <span class="ml-auto text-brand-mist">
                        {{ $isLeaf
                            ? __('Click a minute to load its log lines below.')
                            : __('Click a bar to zoom in (:grain → finer). Hover a dot for the event.', ['grain' => $grains[$gran] ?? $gran]) }}
                    </span>
                </div>
            </div>
        @endunless
    </div>
</section>
