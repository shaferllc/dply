@props(['size' => 'default'])

@php
    $classes = match($size) {
        'sm' => 'inline-flex items-center justify-center whitespace-nowrap gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50',
        'xs' => 'inline-flex items-center justify-center whitespace-nowrap gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50',
        default => 'inline-flex items-center justify-center whitespace-nowrap gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 focus:ring-offset-white disabled:cursor-not-allowed disabled:opacity-50',
    };
    $tag = $attributes->has('href') ? 'a' : 'button';
    $defaults = $tag === 'button' ? ['type' => 'button', 'class' => $classes] : ['class' => $classes];
@endphp

<{{ $tag }} {{ $attributes->merge($defaults) }}>
    {{ $slot }}
</{{ $tag }}>
