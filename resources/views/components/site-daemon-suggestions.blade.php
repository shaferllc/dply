@props([
    'suggestions' => [],
    // 'interactive' wires supervisor suggestions to applySupervisorPreset() —
    // only valid inside the WorkspaceDaemons component. 'links' renders plain
    // anchors to the Daemons / Schedule pages for use elsewhere.
    'mode' => 'links',
    'daemonsUrl' => null,
    'scheduleUrl' => null,
    // How many the operator has dismissed, so the panel can offer them back.
    'dismissedCount' => 0,
])

@php
    $items = collect($suggestions)->filter(fn ($s) => is_array($s) && ($s['label'] ?? '') !== '');
@endphp

@if ($items->isNotEmpty() || $dismissedCount > 0)
    {{-- Collapsible: this panel is advisory, so it should tuck away once read.
         Open while anything is outstanding. Each row can also be dismissed for
         good (persisted on the site by SiteDaemonAdvisor) — never a one-way
         door, the footer restores them. --}}
    <details class="group rounded-xl border border-indigo-200 bg-gradient-to-b from-indigo-50/90 to-white" @if ($items->isNotEmpty()) open @endif>
        <summary class="flex cursor-pointer list-none flex-wrap items-center gap-2 px-3 py-2">
            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 shrink-0 text-indigo-700 transition-transform group-open:rotate-90" aria-hidden="true" />
            <span class="shrink-0 text-[11px] font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ __('Suggested processes') }}</span>
            @if ($items->isNotEmpty())
                <span class="inline-flex shrink-0 items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-900 ring-1 ring-indigo-200">
                    {{ $items->count() }}
                </span>
            @endif
            <span class="min-w-0 truncate text-xs text-indigo-950/80">{{ __('Long-running processes your stack needs, but nothing runs them yet.') }}</span>
        </summary>

        @if ($items->isNotEmpty())
            <ul class="divide-y divide-indigo-100 border-t border-indigo-100">
                @foreach ($items as $item)
                    @php
                        $isHigh = ($item['priority'] ?? 'medium') === 'high';
                        $isScheduler = ($item['kind'] ?? '') === 'scheduler';
                        $targetUrl = $isScheduler ? $scheduleUrl : $daemonsUrl;
                    @endphp
                    {{-- One row: badge + label + command + reason inline, actions right.
                         This was a ~180px card per suggestion. --}}
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1.5">
                        <span @class([
                            'inline-flex shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide',
                            'bg-rose-100 text-rose-800' => $isHigh,
                            'bg-indigo-100 text-indigo-900' => ! $isHigh,
                        ])>{{ $isHigh ? __('Recommended') : __('Optional') }}</span>
                        <span class="shrink-0 text-xs font-semibold text-brand-ink">{{ $item['label'] }}</span>
                        @if (($item['command'] ?? '') !== '')
                            <code class="shrink-0 rounded bg-white/70 px-1 font-mono text-[10px] text-brand-moss ring-1 ring-inset ring-indigo-100">{{ $item['command'] }}</code>
                        @endif
                        <span class="min-w-0 flex-1 truncate text-[11px] text-indigo-950/80" title="{{ $item['reason'] }}">{{ $item['reason'] }}</span>

                        <span class="flex shrink-0 items-center gap-1">
                            @if ($mode === 'interactive' && ! $isScheduler && ($item['preset'] ?? null))
                                <button
                                    type="button"
                                    wire:click="suggestDaemonPreset(@js($item['preset']))"
                                    class="inline-flex items-center gap-1 rounded-md border border-indigo-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-indigo-50"
                                >
                                    <x-heroicon-o-plus class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Set up') }}
                                </button>
                            @elseif ($targetUrl)
                                <a
                                    href="{{ $targetUrl }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1 rounded-md border border-indigo-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-indigo-700 shadow-sm transition-colors hover:bg-indigo-50"
                                >
                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Set up') }}
                                </a>
                            @endif

                            @if (($item['key'] ?? '') !== '')
                                <button
                                    type="button"
                                    wire:click="dismissDaemonSuggestion(@js($item['key']))"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded-md text-indigo-400 transition-colors hover:bg-white hover:text-indigo-800"
                                    title="{{ __('Dismiss this suggestion') }}"
                                    aria-label="{{ __('Dismiss :label', ['label' => $item['label']]) }}"
                                >
                                    <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                                </button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($dismissedCount > 0)
            <div class="flex items-center justify-between gap-2 border-t border-indigo-100 px-3 py-1.5">
                <span class="text-[11px] text-indigo-950/70">
                    {{ trans_choice('{1} :count suggestion dismissed|[2,*] :count suggestions dismissed', $dismissedCount, ['count' => $dismissedCount]) }}
                </span>
                <button
                    type="button"
                    wire:click="restoreDaemonSuggestions"
                    class="text-[11px] font-semibold text-indigo-700 hover:underline"
                >
                    {{ __('Show again') }}
                </button>
            </div>
        @endif
    </details>
@endif
