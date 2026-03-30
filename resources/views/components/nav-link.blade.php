@props(['active'])

@php
    $classes = ($active ?? false)
        ? 'group inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-1.5 py-2 border-b-2 border-brand-gold text-sm font-medium leading-5 text-brand-ink focus:outline-none transition duration-150 ease-in-out'
        : 'group inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-1.5 py-2 border-b-2 border-transparent text-sm font-medium leading-5 text-brand-moss hover:text-brand-ink hover:border-brand-sage/40 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    @isset($icon)
        <span class="flex shrink-0 items-center justify-center text-current [&>svg]:h-5 [&>svg]:w-5 [&>svg]:shrink-0" aria-hidden="true">{!! $icon !!}</span>
    @endisset
    <span>{{ $slot }}</span>
</a>
