<a {{ $attributes->merge(['class' => 'group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-sm font-medium leading-5 text-brand-ink transition duration-150 ease-out hover:bg-brand-sand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/35']) }}>
    @isset($icon)
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand text-brand-forest shadow-inner shadow-brand-ink/[0.06] ring-1 ring-brand-sage/25 transition group-hover:bg-brand-sage group-hover:text-brand-cream group-hover:ring-brand-sage/40 [&>svg]:h-[1.15rem] [&>svg]:w-[1.15rem]" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <span class="min-w-0 flex-1 text-brand-moss transition group-hover:text-brand-ink">{{ $slot }}</span>
</a>
