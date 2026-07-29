{{--
    Lazy-load skeleton for Run. Mirrors the merged page (hide-hero + single
    card with identity header, library list, one-off command).
--}}
<x-server-workspace-layout
    :server="$server"
    active="run"
    :title="__('Run')"
    hide-hero
>
    <div class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading run…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-play class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Run') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Saved recipes and one-off shell on this server. Site deploys live on each site’s page.') }}
                        </p>
                        <div class="mt-2 h-3 w-40 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-8 w-28 animate-pulse rounded-lg bg-brand-ink/15"></span>
                    <span class="inline-flex h-8 w-24 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="mb-3 flex items-center gap-2">
                <x-heroicon-o-rectangle-stack class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Library on this server') }}</p>
            </div>
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach (range(1, 3) as $row)
                    <li class="flex items-center justify-between gap-3 px-4 py-3.5">
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                        <div class="flex gap-2">
                            <span class="h-7 w-14 animate-pulse rounded-lg bg-brand-ink/10"></span>
                            <span class="h-7 w-14 animate-pulse rounded-lg bg-brand-ink/10"></span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="mb-3 flex items-center gap-2">
                <x-heroicon-o-bolt class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('One-off command') }}</p>
            </div>
            <div class="mb-3 h-3 w-2/3 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
            <div class="h-24 w-full animate-pulse rounded-xl bg-brand-ink/10"></div>
            <div class="mt-3 h-9 w-28 animate-pulse rounded-lg bg-brand-ink/15"></div>
        </div>
    </div>
</x-server-workspace-layout>
