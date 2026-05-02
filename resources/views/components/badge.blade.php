@props([
    'tone' => 'neutral',
    'size' => 'md',
    'caps' => true,
])

@php
    $toneClasses = match ($tone) {
        'success' => 'bg-green-50 text-green-900 border-green-200',
        'warning' => 'bg-amber-50 text-amber-900 border-amber-200',
        'danger' => 'bg-red-50 text-red-900 border-red-200',
        'info' => 'bg-brand-ink text-brand-cream border-brand-ink/10',
        'accent' => 'bg-brand-sand/30 text-brand-ink border-brand-ink/10',
        default => 'bg-white text-brand-moss border-brand-ink/10',
    };

    $sizeClasses = match ($size) {
        'sm' => 'px-2 py-0.5 text-[11px]',
        default => 'px-2.5 py-1 text-xs',
    };
@endphp

<span {{ $attributes->class([
    "inline-flex items-center rounded-full border font-semibold $toneClasses $sizeClasses",
    'uppercase tracking-wide' => $caps,
]) }}>
    {{ $slot }}
</span>
