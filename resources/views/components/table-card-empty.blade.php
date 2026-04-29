@props([
    /** Minimum height so empty regions match table-like vertical presence */
    'minHeight' => true,
])

<div
    {{ $attributes->class([
        'flex flex-col items-center justify-center rounded-xl border border-dashed border-brand-ink/20 bg-white px-6 text-center text-sm text-brand-moss',
        $minHeight ? 'min-h-[12rem] py-10' : 'py-8',
    ]) }}
    role="status"
>
    {{ $slot }}
</div>
