<a {{ $attributes->merge(['class' => 'flex items-center gap-2 w-full px-4 py-2 text-start text-sm leading-5 text-brand-moss hover:bg-brand-sand/50 focus:outline-none focus:bg-brand-sand/50 transition duration-150 ease-in-out']) }}>
    @isset($icon)
        <span class="shrink-0 opacity-90 [&>svg]:h-4 [&>svg]:w-4" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <span>{{ $slot }}</span>
</a>
