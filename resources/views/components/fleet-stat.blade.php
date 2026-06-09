@props([
    /** Uppercase label shown above the value. */
    'label',
])

{{-- Shared stat tile for fleet pages. Provide the value (and any hint) as the slot. --}}
<div {{ $attributes->class(['dply-card p-5']) }}>
    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</p>
    {{ $slot }}
</div>
