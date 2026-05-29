<div {{ $attributes->merge(['class' => 'flex shrink-0 items-center gap-2.5 w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-brand-moss/55 cursor-default select-none']) }} aria-disabled="true" title="{{ __('Coming soon') }}">
    @isset($icon)
        <span class="flex shrink-0 items-center justify-center text-current [&>svg]:h-5 [&>svg]:w-5 [&>svg]:shrink-0 [&>svg]:stroke-2" aria-hidden="true">{!! $icon !!}</span>
    @endisset
    <span>{{ $slot }}</span>
    <span class="ml-auto shrink-0 rounded-full bg-brand-gold/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-gold ring-1 ring-inset ring-brand-gold/25">{{ __('Soon') }}</span>
</div>
