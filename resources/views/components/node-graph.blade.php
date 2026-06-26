@props([
    'map',
    'eyebrow' => null,
    'title' => null,
    'description' => null,
    'emptyTitle' => null,
    'emptyText' => null,
])

{{--
    Generic three-column node-link graph (servers → services → exposure, key
    sources → accounts → workloads, …). Node positions are pre-computed by a
    builder service (top in px, edge anchors in 0..100 viewBox units) so the SVG
    edge layer lines up with the HTML node cards at any width and survives Livewire
    poll morphs without JS measuring the DOM. The edge SVG uses
    preserveAspectRatio="none" (x scales to width, y stays px-exact) with
    non-scaling-stroke so lines stay crisp. Hover a node to trace its connections.

    Expected $map shape:
      ['has_data' => bool, 'height' => int,
       'columns' => ['left' => .., 'mid' => .., 'right' => ..],
       'left'|'mid'|'right' => [ ['id','label','sub','top','tone','mono'], … ],
       'edges' => [ ['from','to','x1','y1','x2','y2'], … ]]
    tone ∈ default | highlight | warn | good.
--}}
@php
    $toneBase = [
        'default' => 'border-brand-ink/10 bg-white',
        'highlight' => 'border-brand-sage/40 bg-brand-sage/[0.06]',
        'warn' => 'border-amber-200 bg-amber-50/60',
        'good' => 'border-emerald-200 bg-emerald-50/50',
    ];
    $rowH = $map['row_h'] ?? 58;
@endphp
<section class="dply-card overflow-hidden">
    @if ($title || $eyebrow || $description)
        <div class="flex flex-col gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    @if ($eyebrow)
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $eyebrow }}</p>
                    @endif
                    @if ($title)
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $title }}</h2>
                    @endif
                    @if ($description)
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if (! ($map['has_data'] ?? false))
        <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                <x-heroicon-o-share class="h-6 w-6" />
            </span>
            <p class="text-sm font-medium text-brand-ink">{{ $emptyTitle ?? __('Nothing to map yet.') }}</p>
            @if ($emptyText)
                <p class="text-xs text-brand-moss">{{ $emptyText }}</p>
            @endif
        </div>
    @else
        <div class="px-4 py-6 sm:px-6" x-data="{ hot: null }">
            {{-- Column headers --}}
            <div class="mb-3 grid grid-cols-3 gap-2 text-center">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $map['columns']['left'] }}</p>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $map['columns']['mid'] }}</p>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $map['columns']['right'] }}</p>
            </div>

            <div class="relative w-full" style="height: {{ $map['height'] }}px;">
                {{-- Edge layer --}}
                <svg
                    class="pointer-events-none absolute inset-0 h-full w-full"
                    viewBox="0 0 100 {{ $map['height'] }}"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    @foreach ($map['edges'] as $edge)
                        @php
                            $x1 = $edge['x1']; $y1 = $edge['y1'];
                            $x2 = $edge['x2']; $y2 = $edge['y2'];
                            $dx = ($x2 - $x1) / 2;
                            $d = sprintf('M %s %s C %s %s, %s %s, %s %s', $x1, $y1, $x1 + $dx, $y1, $x2 - $dx, $y2, $x2, $y2);
                        @endphp
                        <path
                            d="{{ $d }}"
                            fill="none"
                            stroke-linecap="round"
                            vector-effect="non-scaling-stroke"
                            :stroke="(hot === '{{ $edge['from'] }}' || hot === '{{ $edge['to'] }}') ? '#3f6b5c' : '#c7d2cd'"
                            :stroke-width="(hot === '{{ $edge['from'] }}' || hot === '{{ $edge['to'] }}') ? 2.25 : 1.25"
                            :stroke-opacity="hot && !(hot === '{{ $edge['from'] }}' || hot === '{{ $edge['to'] }}') ? 0.2 : 1"
                        />
                    @endforeach
                </svg>

                {{-- Column geometry MUST match the SVG x anchors in the builder
                     feeding this component (e.g. ServerNetworkMap): left 0–28,
                     mid 35–65, right 72–100. Cards are flush to the boundary on the
                     edge that carries a connector so the lines touch the cards. --}}
                @foreach ([['key' => 'left', 'style' => 'left: 0; width: 28%;', 'card' => 'left-0 right-0'], ['key' => 'mid', 'style' => 'left: 35%; width: 30%;', 'card' => 'inset-x-0'], ['key' => 'right', 'style' => 'left: 72%; width: 28%;', 'card' => 'left-0 right-0']] as $col)
                    <div class="absolute inset-y-0" style="{{ $col['style'] }}">
                        @foreach ($map[$col['key']] as $node)
                            <div
                                wire:key="ng-{{ $node['id'] }}"
                                @if (($node['title'] ?? '') !== '') title="{{ $node['title'] }}" @endif
                                class="absolute {{ $col['card'] }} flex flex-col justify-center rounded-xl border px-3 py-2 shadow-sm transition"
                                style="top: {{ $node['top'] }}px; height: {{ $rowH }}px;"
                                x-on:mouseenter="hot = '{{ $node['id'] }}'"
                                x-on:mouseleave="hot = null"
                                :class="hot === '{{ $node['id'] }}' ? 'border-brand-forest ring-2 ring-brand-forest/30 bg-white' : '{{ $toneBase[$node['tone'] ?? 'default'] ?? $toneBase['default'] }}'"
                            >
                                <p class="truncate {{ ($node['mono'] ?? false) ? 'font-mono' : '' }} text-xs font-semibold text-brand-ink">{{ $node['label'] }}</p>
                                @if (($node['sub'] ?? '') !== '')
                                    <p class="mt-0.5 truncate {{ ($node['mono'] ?? false) ? 'font-mono' : '' }} text-[11px] text-brand-mist">{{ $node['sub'] }}</p>
                                @endif
                                @if (($node['detail'] ?? null) !== null && $node['detail'] !== '')
                                    <p class="mt-0.5 flex items-center gap-1 truncate text-[11px] font-medium text-amber-700">
                                        <x-heroicon-m-globe-alt class="h-3 w-3 shrink-0" aria-hidden="true" />
                                        <span class="truncate font-mono">{{ $node['detail'] }}</span>
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- Screen-reader summary (the SVG/overlay is decorative). --}}
            <ul class="sr-only">
                @foreach ($map['mid'] as $node)
                    <li>{{ $node['label'] }} — {{ $node['sub'] ?? '' }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
