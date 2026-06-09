@props([
    /** Whether this pill is the active selection. */
    'active' => false,
])

{{-- Shared segmented filter pill for fleet pages. Forward wire:click etc. via attributes. --}}
<button
    type="button"
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition',
        'border-brand-ink bg-brand-ink text-brand-cream' => $active,
        'border-brand-ink/15 bg-white text-brand-moss hover:text-brand-ink' => ! $active,
    ]) }}
>
    {{ $slot }}
</button>
