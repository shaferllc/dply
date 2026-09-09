@props(['server'])

{{--
    Live progress for an in-flight resize, shown on every server workspace tab.

    A resize powers the machine down for minutes, so "nothing appears to be
    happening" is the default experience without this — the toast fires once on
    dispatch and then the page is silent until someone reloads.

    State comes from meta['resize'], written by ResizeServerJob as it advances.
    The banner polls only while a resize is actually running, so an idle
    workspace pays nothing for it.
--}}

@php
    $resize = is_array($server->meta['resize'] ?? null) ? $server->meta['resize'] : null;
    $state = is_string($resize['state'] ?? null) ? $resize['state'] : null;

    $running = in_array($state, ['powering_off', 'resizing', 'powering_on'], true);

    // A finished resize lingers briefly so the operator sees how it ended,
    // then disappears on its own rather than needing a dismiss control.
    $settledAt = filled($resize['at'] ?? null) ? \Illuminate\Support\Carbon::parse($resize['at']) : null;
    $recentlySettled = in_array($state, ['completed', 'failed'], true)
        && $settledAt !== null
        && $settledAt->gt(now()->subMinutes(10));

    $show = $resize !== null && ($running || $recentlySettled);

    $target = (string) ($resize['target_size'] ?? '?');
    $growsDisk = (bool) ($resize['grow_disk'] ?? false);

    // Ordered journey. Vultr reboots in place and never reports powering_off,
    // so a step the run skipped simply never lights up.
    $steps = [
        'powering_off' => __('Powering off'),
        'resizing' => __('Resizing at provider'),
        'powering_on' => __('Starting back up'),
    ];
    $order = array_keys($steps);
    $currentIndex = $state !== null ? array_search($state, $order, true) : false;
@endphp

@if ($show)
    <div
        @if ($running) wire:poll.5s @endif
        class="mb-4 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm"
        role="status"
        aria-live="polite"
    >
        <div @class([
            'flex flex-wrap items-center gap-2 px-4 py-2.5',
            'bg-amber-50' => $running,
            'bg-emerald-50' => $state === 'completed',
            'bg-rose-50' => $state === 'failed',
        ])>
            @if ($running)
                <x-heroicon-o-arrows-pointing-out class="h-4 w-4 shrink-0 animate-pulse text-amber-700" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-amber-900">{{ __('Resizing to :size', ['size' => $target]) }}</h2>
                <span class="text-xs text-amber-800">{{ __('The server is offline until this finishes.') }}</span>
            @elseif ($state === 'completed')
                <x-heroicon-o-check-circle class="h-4 w-4 shrink-0 text-emerald-700" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-emerald-900">{{ __('Resize to :size finished', ['size' => $target]) }}</h2>
            @else
                <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-rose-700" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-rose-900">{{ __('Resize to :size failed', ['size' => $target]) }}</h2>
            @endif

            @if ($growsDisk)
                <span class="rounded-md border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-rose-700">
                    {{ __('disk grew — permanent') }}
                </span>
            @endif
        </div>

        @if ($running || $state === 'completed')
            <ol class="grid gap-px bg-brand-ink/10 sm:grid-cols-3">
                @foreach ($steps as $key => $label)
                    @php
                        $index = (int) array_search($key, $order, true);
                        $done = $state === 'completed' || ($currentIndex !== false && $index < $currentIndex);
                        $active = $state !== 'completed' && $currentIndex !== false && $index === $currentIndex;
                    @endphp
                    <li class="flex items-center gap-2 bg-white px-4 py-2.5">
                        @if ($done)
                            <x-heroicon-m-check-circle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        @elseif ($active)
                            <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-amber-500" aria-hidden="true"></span>
                        @else
                            <span class="h-2 w-2 shrink-0 rounded-full bg-brand-ink/15" aria-hidden="true"></span>
                        @endif
                        <span @class([
                            'text-xs',
                            'font-semibold text-brand-ink' => $active,
                            'text-brand-moss' => $done,
                            'text-brand-mist' => ! $done && ! $active,
                        ])>{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($state === 'failed' && filled($resize['error'] ?? null))
            <p class="border-t border-brand-ink/10 px-4 py-2.5 text-xs text-rose-800">
                {{ $resize['error'] }}
            </p>
            <p class="border-t border-brand-ink/10 px-4 py-2 text-xs text-brand-moss">
                {{ __('The machine may still be powered off — check it at the provider before assuming traffic recovered.') }}
            </p>
        @endif
    </div>
@endif
