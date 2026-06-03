@props([
    'suggestions' => [],
    // 'interactive' wires supervisor suggestions to applySupervisorPreset() —
    // only valid inside the WorkspaceDaemons component. 'links' renders plain
    // anchors to the Daemons / Schedule pages for use elsewhere.
    'mode' => 'links',
    'daemonsUrl' => null,
    'scheduleUrl' => null,
])

@php
    $items = collect($suggestions)->filter(fn ($s) => is_array($s) && ($s['label'] ?? '') !== '');
@endphp

@if ($items->isNotEmpty())
    <section class="rounded-2xl border border-indigo-200 bg-gradient-to-b from-indigo-50/90 to-white px-4 py-4 sm:px-5 sm:py-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-indigo-700">{{ __('Suggested processes') }}</p>
                <p class="mt-1 text-sm leading-relaxed text-indigo-950/90">
                    {{ __('Your stack looks like it needs these long-running processes, but nothing runs them yet.') }}
                </p>
            </div>
            <span class="inline-flex shrink-0 items-center rounded-full bg-indigo-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-indigo-900 ring-1 ring-indigo-200">
                {{ trans_choice('{1} :count suggestion|[2,*] :count suggestions', $items->count(), ['count' => $items->count()]) }}
            </span>
        </div>

        <ul class="mt-4 space-y-3">
            @foreach ($items as $item)
                @php
                    $isHigh = ($item['priority'] ?? 'medium') === 'high';
                    $isScheduler = ($item['kind'] ?? '') === 'scheduler';
                    $targetUrl = $isScheduler ? $scheduleUrl : $daemonsUrl;
                @endphp
                <li class="rounded-xl border border-indigo-200 bg-white/80 px-4 py-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                    'bg-rose-100 text-rose-800' => $isHigh,
                                    'bg-indigo-100 text-indigo-900' => ! $isHigh,
                                ])>{{ $isHigh ? __('Recommended') : __('Optional') }}</span>
                                <span class="text-sm font-semibold text-brand-ink">{{ $item['label'] }}</span>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-indigo-950/90">{{ $item['reason'] }}</p>
                            @if (($item['command'] ?? '') !== '')
                                <p class="mt-1 font-mono text-[11px] text-brand-mist">{{ $item['command'] }}</p>
                            @endif
                        </div>

                        @if ($mode === 'interactive' && ! $isScheduler && ($item['preset'] ?? null))
                            <button
                                type="button"
                                wire:click="suggestDaemonPreset(@js($item['preset']))"
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-indigo-300 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-indigo-50"
                            >
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Set up') }}
                            </button>
                        @elseif ($targetUrl)
                            <a
                                href="{{ $targetUrl }}"
                                wire:navigate
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-indigo-300 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-indigo-50"
                            >
                                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Set up') }}
                            </a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
