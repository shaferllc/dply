@props(['size' => 'default'])

@php
    $sizeClasses = match ($size) {
        'sm' => 'rounded-lg px-3 py-1.5 text-xs',
        default => 'rounded-xl px-3 py-2 text-sm',
    };
@endphp

<a {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 border border-brand-ink/15 bg-white font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 '.$sizeClasses]) }}>
    {{ $slot }}
</a>
