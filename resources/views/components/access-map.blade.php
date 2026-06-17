@props([
    'map',
    'server' => null,
])

{{--
    "Who → as whom → owns what" access map. Three columns:
      Key sources  →  Linux accounts  →  Workloads (sites / workers / cron)

    Node positions are pre-computed in App\Services\Servers\ServerAccessMap (top in
    px, edge anchors in 0..100 viewBox units), so the SVG edge layer lines up with the
    HTML node cards at any width and survives Livewire poll morphs without JS measuring
    the DOM. The edge SVG uses preserveAspectRatio="none" (x scales to width, y stays
    px-exact) with non-scaling-stroke so lines stay crisp.
--}}
<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Access map') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Who can act on this box, and as whom') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('SSH key sources authenticate as a Linux account; each account owns the sites, worker processes, and cron jobs that run as it. Hover a node to trace its connections.') }}
                </p>
            </div>
        </div>
    </div>

    @if (! ($map['has_data'] ?? false))
        <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                <x-heroicon-o-share class="h-6 w-6" />
            </span>
            <p class="text-sm font-medium text-brand-ink">{{ __('No access paths to map yet.') }}</p>
            <p class="text-xs text-brand-moss">{{ __('Once authorized keys are synced, their accounts and workloads appear here.') }}</p>
        </div>
    @else
        <div class="px-4 py-6 sm:px-6" x-data="{ hot: null }">
            {{-- Column headers --}}
            <div class="mb-3 grid grid-cols-3 gap-2 text-center">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $map['columns']['sources'] }}</p>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $map['columns']['accounts'] }}</p>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $map['columns']['workloads'] }}</p>
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

                {{-- Sources column --}}
                <div class="absolute inset-y-0" style="left: 0; width: 30%;">
                    @foreach ($map['sources'] as $node)
                        <div
                            wire:key="am-{{ $node['id'] }}"
                            class="absolute left-0 right-1 flex flex-col justify-center rounded-xl border bg-white px-3 py-2 shadow-sm transition"
                            style="top: {{ $node['top'] }}px; height: 58px;"
                            x-on:mouseenter="hot = '{{ $node['id'] }}'"
                            x-on:mouseleave="hot = null"
                            :class="hot === '{{ $node['id'] }}' ? 'border-brand-forest ring-2 ring-brand-forest/30' : 'border-brand-ink/10'"
                        >
                            <p class="truncate text-xs font-semibold text-brand-ink">{{ $node['label'] }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-mist">
                                {{ trans_choice('{1} :count key|[2,*] :count keys', $node['count'], ['count' => $node['count']]) }}
                            </p>
                        </div>
                    @endforeach
                </div>

                {{-- Accounts column (the spine) --}}
                <div class="absolute inset-y-0" style="left: 37%; width: 26%;">
                    @foreach ($map['accounts'] as $node)
                        <div
                            wire:key="am-{{ $node['id'] }}"
                            class="absolute inset-x-1 flex flex-col justify-center rounded-xl border px-3 py-2 shadow-sm transition"
                            style="top: {{ $node['top'] }}px; height: 58px;"
                            x-on:mouseenter="hot = '{{ $node['id'] }}'"
                            x-on:mouseleave="hot = null"
                            :class="hot === '{{ $node['id'] }}' ? 'border-brand-forest ring-2 ring-brand-forest/30 bg-white' : '{{ $node['exists'] ? 'border-brand-ink/10 bg-brand-sand/30' : 'border-amber-200 bg-amber-50/60' }}'"
                        >
                            <div class="flex items-center justify-between gap-1">
                                <p class="truncate font-mono text-xs font-semibold text-brand-ink">{{ $node['user'] }}</p>
                                <div class="flex shrink-0 items-center gap-1">
                                    @if ($node['is_login'])
                                        <span title="{{ __('SSH login user') }}" class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-brand-sand/60 text-brand-moss">
                                            <x-heroicon-m-user class="h-3 w-3" />
                                        </span>
                                    @endif
                                    @if ($node['is_protected'])
                                        <span title="{{ __('Protected account') }}" class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-sky-50 text-sky-700 ring-1 ring-sky-200">
                                            <x-heroicon-m-shield-check class="h-3 w-3" />
                                        </span>
                                    @endif
                                    @unless ($node['exists'])
                                        <span title="{{ __('No matching /etc/passwd account — sync system users') }}" class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-amber-100 text-amber-800 ring-1 ring-amber-200">
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                        </span>
                                    @endunless
                                </div>
                            </div>
                            <p class="mt-0.5 truncate text-[11px] text-brand-mist">
                                {{ trans_choice('{0} no keys|{1} :count key|[2,*] :count keys', $node['key_count'], ['count' => $node['key_count']]) }}
                                @if ($node['uid'] !== null)
                                    · <span class="font-mono">UID {{ $node['uid'] }}</span>
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>

                {{-- Workloads column --}}
                <div class="absolute inset-y-0" style="left: 70%; width: 30%;">
                    @foreach ($map['workloads'] as $node)
                        <div
                            wire:key="am-{{ $node['id'] }}"
                            class="absolute left-1 right-0 flex flex-col justify-center rounded-xl border bg-white px-3 py-2 shadow-sm transition"
                            style="top: {{ $node['top'] }}px; height: 58px;"
                            x-on:mouseenter="hot = '{{ $node['id'] }}'"
                            x-on:mouseleave="hot = null"
                            :class="hot === '{{ $node['id'] }}' ? 'border-brand-forest ring-2 ring-brand-forest/30' : 'border-brand-ink/10'"
                        >
                            <p class="truncate text-xs font-semibold text-brand-ink">{{ $node['label'] }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-mist">
                                {{ trans_choice('{1} :count total|[2,*] :count total', $node['total'], ['count' => $node['total']]) }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Screen-reader summary (the SVG/overlay is decorative). --}}
            <ul class="sr-only">
                @foreach ($map['accounts'] as $node)
                    <li>
                        {{ __('Account :user', ['user' => $node['user']]) }}:
                        {{ trans_choice('{0} no keys|{1} :count key|[2,*] :count keys', $node['key_count'], ['count' => $node['key_count']]) }},
                        {{ trans_choice('{0} no sites|{1} :count site|[2,*] :count sites', $node['sites'], ['count' => $node['sites']]) }},
                        {{ trans_choice('{0} no workers|{1} :count worker|[2,*] :count workers', $node['workers'], ['count' => $node['workers']]) }},
                        {{ trans_choice('{0} no cron jobs|{1} :count cron job|[2,*] :count cron jobs', $node['crons'], ['count' => $node['crons']]) }}.
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
