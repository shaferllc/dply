@props([
    'size' => 'md',
    'variant' => 'forest',
])

@php
    $sizeClass = match ($size) {
        'sm' => 'h-3.5 w-3.5',
        'lg' => 'h-5 w-5',
        default => 'h-4 w-4',
    };
    $colorClass = match ($variant) {
        'cream' => 'text-brand-cream',
        'white' => 'text-white',
        'muted' => 'text-brand-moss',
        'zinc' => 'text-zinc-600',
        'emerald' => 'text-emerald-700',
        'amber' => 'text-amber-900',
        'ink' => 'text-brand-ink',
        'slate' => 'text-slate-100',
        default => 'text-brand-forest',
    };
@endphp

<svg
    {{ $attributes->class(['inline-block shrink-0 animate-spin', $sizeClass, $colorClass]) }}
    xmlns="http://www.w3.org/2000/svg"
    fill="none"
    viewBox="0 0 24 24"
    aria-hidden="true"
>
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
    ></path>
</svg>
