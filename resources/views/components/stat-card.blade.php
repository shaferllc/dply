@props([
    'label',
    'value',
    'meta' => null,
    'tone' => 'default',
])

@php
    $classes = match ($tone) {
        'subtle' => 'rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-5',
        default => 'rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm',
    };
@endphp

<div {{ $attributes->class([$classes]) }}>
    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ $label }}</p>
    <p class="mt-3 text-xl font-semibold text-brand-ink">{{ $value }}</p>

    @if ($meta)
        <p class="mt-1 text-sm text-brand-moss">{{ $meta }}</p>
    @endif

    @if (isset($slot) && trim((string) $slot) !== '')
        <div class="mt-3 text-sm text-brand-moss">
            {{ $slot }}
        </div>
    @endif
</div>
