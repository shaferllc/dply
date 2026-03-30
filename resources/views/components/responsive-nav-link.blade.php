@props(['active'])

@php
    $classes = ($active ?? false)
        ? 'flex shrink-0 items-center gap-2.5 w-full ps-3 pe-4 py-2 border-l-4 border-brand-gold text-start text-base font-medium text-brand-ink bg-brand-sand/40 focus:outline-none transition duration-150 ease-in-out'
        : 'flex shrink-0 items-center gap-2.5 w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-brand-moss hover:text-brand-ink hover:bg-brand-sand/30 hover:border-brand-sage/30 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    @isset($icon)
        <span class="flex shrink-0 items-center justify-center text-current [&>svg]:h-5 [&>svg]:w-5 [&>svg]:shrink-0 [&>svg]:stroke-2" aria-hidden="true">{!! $icon !!}</span>
    @endisset
    <span>{{ $slot }}</span>
</a>
