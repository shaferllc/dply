@props(['size' => 'default'])

@php
    $classes = match($size) {
        'sm' => 'inline-flex items-center justify-center whitespace-nowrap gap-2 rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white shadow-sm hover:bg-red-700 transition-colors disabled:cursor-not-allowed disabled:opacity-50 dply-focus dply-pressable',
        // Matches workspace panel-head action height (compact, sentence case).
        'xs' => 'inline-flex items-center justify-center whitespace-nowrap gap-1.5 rounded-lg bg-red-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50 dply-focus dply-pressable',
        default => 'inline-flex items-center justify-center whitespace-nowrap gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 focus:ring-offset-white disabled:cursor-not-allowed disabled:opacity-50 dply-pressable',
    };
    $tag = $attributes->has('href') ? 'a' : 'button';
    $defaults = $tag === 'button' ? ['type' => 'submit', 'class' => $classes] : ['class' => $classes];
@endphp

<{{ $tag }} {{ $attributes->merge($defaults) }}>
    {{ $slot }}
</{{ $tag }}>
