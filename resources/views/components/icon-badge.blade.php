@props([
    'tone' => 'brand',
    'size' => 'default',
])

@php
    $toneClasses = match ($tone) {
        'amber' => 'bg-amber-100 text-amber-700 ring-amber-200',
        'rose'  => 'bg-rose-100 text-rose-700 ring-rose-200',
        'red'   => 'bg-red-100 text-red-700 ring-red-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'indigo'  => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'violet'  => 'bg-violet-100 text-violet-700 ring-violet-200',
        'gold'    => 'bg-brand-gold/20 text-brand-forest ring-brand-gold/30',
        default   => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    };

    $sizeClasses = match ($size) {
        'lg' => 'h-12 w-12',
        'md' => 'h-11 w-11',
        default => 'h-10 w-10',
    };
@endphp

<span {{ $attributes->class(["flex shrink-0 items-center justify-center rounded-2xl ring-1 $toneClasses $sizeClasses"]) }}>
    {{ $slot }}
</span>
