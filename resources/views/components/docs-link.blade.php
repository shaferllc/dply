@props([
    'slug' => null,
    'docRoute' => null,
    'docSlug' => null,
    'label' => null,
    'size' => 'sm',
])

@php
    $linkLabel = $label ?? __('Documentation');
    $sizeClasses = $size === 'md'
        ? 'rounded-xl px-3 py-2 text-sm'
        : 'rounded-lg px-3 py-1.5 text-xs';
@endphp

<button
    type="button"
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 border border-brand-ink/15 bg-white font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream dark:hover:bg-zinc-800 '.$sizeClasses]) }}
    x-on:click="window.dispatchEvent(new CustomEvent('dply-docs-open', { detail: @js(array_filter([
        'slug' => $slug,
        'docRoute' => $docRoute,
        'docSlug' => $docSlug,
    ], static fn ($value) => filled($value))) }))"
>
    {{ $slot->isEmpty() ? $linkLabel : $slot }}
</button>
