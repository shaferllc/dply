{{--
    Skeletons for the engine sub-tabs (Overview / Logs / Config / Info and the
    per-engine live-state tabs).

    Switching a sub-tab round-trips: @entangle('engine_subtab').live writes the
    property, Livewire re-renders, and the panel body for the NEW sub-tab only
    exists after the response. Until this partial, the OLD sub-tab's tables just
    sat there for the duration — the same "this is your data" problem the
    top-level tabs had.

    Server-side we can't know which sub-tab is incoming ($engine_subtab still
    holds the outgoing one during the request), but Alpine can: `subtab` is the
    entangled value and updates immediately on click. So every shape is rendered
    once and gated on BOTH the in-flight state (wire:loading) and the incoming
    sub-tab (x-show) — exactly one paints.

    Receives: $key (engine), $subtabShapes (map of sub-tab key => shape).
    Shapes: logs | config | form | info | overview | live-state (default).
    Every key in the header strip needs an entry — a missing one paints nothing.
--}}
@php
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

{{-- wire:loading.block, not bare wire:loading: Livewire's default shows a
     hidden element as inline-block, which shrink-wraps the whole skeleton to
     its content width — the head strip and the config grid then rendered in a
     ~1/3-width column instead of spanning the panel. Same fix as the SSH keys
     and Firewall tab skeletons. --}}
<div
    wire:loading.block
    wire:target="engine_subtab,setEngineSubtab"
    aria-busy="true"
    aria-live="polite"
    wire:key="subtab-skeleton-{{ $key }}"
>
    <span class="sr-only">{{ __('Loading section…') }}</span>

    @foreach ($subtabShapes as $subtabKey => $shape)
        <div x-show="subtab === @js($subtabKey)" x-cloak aria-hidden="true">
            @if ($shape === 'logs')
                {{-- Logs: dense head, the toolbar row (source segment + actions +
                     line budget), then the full-bleed tail pane. It keeps its dark
                     surface while loading — a light block here would flash the
                     panel white. --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                </div>
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-4 py-2 sm:px-5">
                    <span class="h-7 w-32 rounded-lg {{ $bar }}"></span>
                    <span class="h-6 w-20 rounded-md {{ $bar }}"></span>
                    <span class="h-6 w-14 rounded-md {{ $bar }}"></span>
                    <span class="ml-auto h-6 w-24 rounded-md {{ $bar }}"></span>
                </div>
                <div class="space-y-2 border-t border-brand-ink/10 bg-brand-ink/95 px-4 py-3 sm:px-5">
                    @foreach (range(1, 12) as $line)
                        <div class="h-2.5 animate-pulse rounded bg-emerald-100/10" style="width: {{ 32 + (($line * 17) % 62) }}%;"></div>
                    @endforeach
                </div>
            @elseif ($shape === 'config')
                {{-- Config: the allowlisted-file list on the left, editor right. --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <span class="h-6 w-24 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
                {{-- Same grid metric as the real panel (260px file rail + editor),
                     so the columns don't jump width when the response lands. --}}
                <div class="grid gap-5 px-4 py-3.5 sm:px-5 lg:grid-cols-[260px_minmax(0,1fr)]">
                    <div class="rounded-xl border border-brand-ink/10 bg-white">
                        <div class="border-b border-brand-ink/10 px-3 py-2">
                            <span class="block h-2 w-10 {{ $bar }}"></span>
                        </div>
                        <div class="divide-y divide-brand-ink/5">
                            @foreach (range(1, 6) as $file)
                                <div class="flex items-center gap-2 px-3 py-2">
                                    <span class="h-3.5 w-3.5 shrink-0 {{ $bar }}"></span>
                                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="min-w-0 space-y-2 rounded-xl bg-zinc-950 px-4 py-3">
                        @foreach (range(1, 14) as $line)
                            <div class="h-2.5 animate-pulse rounded bg-zinc-100/10" style="width: {{ 28 + (($line * 23) % 66) }}%;"></div>
                        @endforeach
                    </div>
                </div>
            @elseif ($shape === 'form')
                {{-- Config form (engine cache zones): dense head with its two
                     actions, the zone summary strip, then the field grid and a
                     right-aligned save row. --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-44 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <span class="h-6 w-16 shrink-0 rounded-md {{ $bar }}"></span>
                    <span class="h-6 w-24 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
                <div class="px-4 py-3.5 sm:px-5">
                    <div class="grid gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 sm:grid-cols-2">
                        @foreach (range(1, 2) as $zone)
                            <div class="space-y-1.5">
                                <span class="block h-2 w-20 {{ $bar }}"></span>
                                <span class="block h-2.5 w-56 max-w-full {{ $bar }}"></span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach (range(1, 4) as $field)
                            <div class="space-y-1.5">
                                <span class="block h-2.5 w-24 {{ $bar }}"></span>
                                <span class="block h-8 w-full rounded-md {{ $bar }}"></span>
                                <span class="block h-2 w-40 max-w-full {{ $bar }}"></span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex justify-end border-t border-brand-ink/10 pt-3">
                        <span class="h-7 w-40 rounded-lg {{ $bar }}"></span>
                    </div>
                </div>
            @elseif ($shape === 'info')
                {{-- Info: description prose, a fact list, and doc links. --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                </div>
                <div class="space-y-2.5 px-4 py-3.5 sm:px-5">
                    <div class="h-2.5 w-full max-w-2xl {{ $bar }}"></div>
                    <div class="h-2.5 w-2/3 max-w-xl {{ $bar }}"></div>
                    <div class="grid gap-2 pt-1.5 sm:grid-cols-2">
                        @foreach (range(1, 4) as $fact)
                            <div class="flex items-center gap-2 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                                <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($shape === 'overview')
                {{-- Overview: engine head + status pill, the metric strip, the
                     health chart, then the lifecycle action rows. --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-24 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <span class="h-5 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                </div>
                <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4">
                    @foreach (range(0, 3) as $cell)
                        <div @class([
                            'space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                            'border-l' => $cell % 2 !== 0,
                            'sm:border-l' => $cell % 4 !== 0,
                            'border-t' => $cell >= 2,
                            'sm:border-t-0' => true,
                        ])>
                            <div class="h-2 w-16 {{ $bar }}"></div>
                            <div class="h-3 w-10 {{ $bar }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
                    <div class="h-28 w-full rounded-xl {{ $bar }}"></div>
                </div>
                <div class="divide-y divide-brand-ink/5">
                    @foreach (range(1, 2) as $group)
                        <div class="flex flex-wrap items-center justify-between gap-3 bg-white px-4 py-3 sm:px-5">
                            <div class="min-w-0 space-y-1.5">
                                <div class="h-2 w-20 {{ $bar }}"></div>
                                <div class="h-2.5 w-56 max-w-full {{ $bar }}"></div>
                            </div>
                            <div class="flex gap-2">
                                @foreach (range(1, 3) as $action)
                                    <span class="h-7 w-20 rounded-lg {{ $bar }}"></span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                {{-- Live state: a probe head with its refresh action, then the
                     result table. Shared by every per-engine live-state sub-tab
                     (hosts / upstreams / certs / routes / vhosts / …). --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-10 shrink-0 rounded-full {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <span class="h-6 w-24 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
                <div class="px-4 py-3.5 sm:px-5">
                    <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                        <div class="flex items-center gap-3 bg-brand-sand/30 px-3 py-2">
                            @foreach ([18, 24, 14, 16, 12] as $width)
                                <span class="h-2 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                            @endforeach
                        </div>
                        <div class="divide-y divide-brand-ink/5 bg-white">
                            @foreach (range(1, 5) as $row)
                                <div class="flex items-center gap-3 px-3 py-2">
                                    @foreach ([18, 24, 14, 16, 12] as $width)
                                        <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
