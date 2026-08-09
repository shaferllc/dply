@props([
    /** Uppercase label shown above the value. */
    'label',
])

{{-- Flat tile for fleet / org pages inside merged shell (avoids nested dply-card).
     White fill stays readable on sand identity headers and calm on body strips. --}}
<div {{ $attributes->class(['rounded-xl border border-brand-ink/10 bg-white/80 p-4']) }}>
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</p>
    {{ $slot }}
</div>
