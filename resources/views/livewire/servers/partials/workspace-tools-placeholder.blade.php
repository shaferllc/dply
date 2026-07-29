{{--
    Lazy-load skeleton for Tools. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and tool row stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="tools"
    :title="__('Tools')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading tools…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Tools') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Installed CLIs and version managers from the inventory probe — install, upgrade, or repair from here.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3.5 sm:px-6" aria-hidden="true">
            <div class="h-3.5 w-40 animate-pulse rounded bg-brand-ink/10"></div>
            <span class="h-8 w-28 animate-pulse rounded-lg bg-brand-ink/10"></span>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Tools'), __('Runtimes')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 5) as $row)
                <li class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                    <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-36 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <span class="h-8 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </li>
            @endforeach
        </ul>
    </section>
</x-server-workspace-layout>
