@props(['description' => null])

<div class="group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-sm font-medium leading-5 cursor-default select-none" aria-disabled="true" title="{{ __('Coming soon') }}">
    @isset($icon)
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-ink/[0.045] text-brand-moss/55 ring-1 ring-brand-ink/[0.08] [&>svg]:h-[1.15rem] [&>svg]:w-[1.15rem]" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <span class="min-w-0 flex-1">
        <span class="block {{ $description ? 'font-semibold text-brand-ink/55' : 'text-brand-moss/55' }}">{{ $slot }}</span>
        @if ($description)
            <span class="mt-0.5 block text-xs font-normal leading-snug text-brand-mist/70">{{ $description }}</span>
        @endif
    </span>
    <span class="shrink-0 self-start rounded-full bg-brand-gold/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-gold ring-1 ring-inset ring-brand-gold/25">{{ __('Soon') }}</span>
</div>
