@props([
    /**
     * list<array{label: string, value: string|int, tone?: null|'ok'|'warn'|'bad', hint?: ?string}>
     */
    'stats' => [],
    /** Cells per row at sm and up. 4 is the default; 3 suits three-value strips. */
    'columns' => 4,
])

{{--
    Compact stat strip: one hairline-separated row of figures, wrapping on narrow
    viewports. Pairs with x-workspace-panel-head dense — the head names the
    group, this carries the numbers.

    Replaces the bordered-tile grids (four to six `text-2xl` cards, each with its
    own caption) that made workspace header regions taller than the content they
    described. Same numbers, roughly a third of the height.
--}}
@php
    $stats = array_values(array_filter($stats));
    $columns = in_array($columns, [2, 3, 4], true) ? $columns : 4;
    $gridClass = match ($columns) {
        2 => 'sm:grid-cols-2',
        3 => 'sm:grid-cols-3',
        default => 'sm:grid-cols-4',
    };
@endphp

@if ($stats !== [])
    <dl {{ $attributes->class(['grid grid-cols-2', $gridClass]) }}>
        @foreach ($stats as $i => $stat)
            @php
                $valueTone = match ($stat['tone'] ?? null) {
                    'ok' => 'text-emerald-700',
                    'warn' => 'text-amber-800',
                    'bad' => 'text-rose-700',
                    default => 'text-brand-ink',
                };
            @endphp
            {{-- Hairlines are borders on the cell, not divide-*, so wrapped rows
                 keep their separators without a trailing edge. --}}
            <div @class([
                'min-w-0 border-brand-ink/8 px-4 py-2 sm:px-5',
                'border-l' => $i % 2 !== 0,
                'sm:border-l' => $i % $columns !== 0,
                'sm:border-l-0' => $i % $columns === 0,
                'border-t' => $i >= 2,
                'sm:border-t-0' => $i < $columns,
                'sm:border-t' => $i >= $columns,
            ])>
                <dt class="truncate text-2xs font-semibold uppercase tracking-wide text-brand-mist" @if (! empty($stat['hint'])) title="{{ $stat['hint'] }}" @endif>{{ $stat['label'] }}</dt>
                <dd class="mt-0.5 truncate font-mono text-sm font-semibold tabular-nums {{ $valueTone }}">{{ $stat['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
