@props([
    /** @var \App\Support\Sites\SiteIndexRow $site */
    'site',
])

<a
    href="{{ $site->manageHref }}"
    @if ($site->manageExternal) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
    class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-brand-cream transition hover:bg-brand-forest sm:px-3"
>
    <x-heroicon-m-cog-6-tooth class="h-4 w-4 shrink-0" aria-hidden="true" />
    {{ __('Manage') }}
</a>

@if ($site->visitUrl)
    <a
        href="{{ $site->visitUrl }}"
        target="_blank"
        rel="noreferrer"
        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
    >
        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
        {{ __('Visit') }}
    </a>
@endif
