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

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
            <h2 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                <x-heroicon-o-play class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                {{ __('Run') }}
            </h2>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <div class="h-2.5 w-40 flex-1 animate-pulse rounded bg-brand-ink/10"></div>
            <div class="flex shrink-0 items-center gap-1.5">
                <span class="inline-flex h-6 w-28 animate-pulse rounded-lg bg-brand-ink/15"></span>
                <span class="inline-flex h-6 w-24 animate-pulse rounded-lg bg-brand-ink/10"></span>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-3 sm:px-4" aria-hidden="true">
            <div class="mb-2 flex items-center gap-1.5">
                <x-heroicon-o-rectangle-stack class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Library on this server') }}</p>
            </div>
            <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach (range(1, 3) as $row)
                    <li class="flex items-center justify-between gap-3 px-3 py-2">
                        <div class="flex min-w-0 flex-1 items-baseline gap-2">
                            <div class="h-3 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                        <div class="flex gap-1.5">
                            <span class="h-5 w-14 animate-pulse rounded-lg bg-brand-ink/10"></span>
                            <span class="h-5 w-14 animate-pulse rounded-lg bg-brand-ink/10"></span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="px-3 py-3 sm:px-4" aria-hidden="true">
            <div class="mb-2 flex items-center gap-2">
                <x-heroicon-o-bolt class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" />
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('One-off command') }}</p>
                <div class="h-2.5 w-2/5 max-w-xs animate-pulse rounded bg-brand-ink/10"></div>
            </div>
            <div class="h-[4.5rem] w-full animate-pulse rounded-xl bg-brand-ink/10"></div>
            <div class="mt-2 h-7 w-28 animate-pulse rounded-lg bg-brand-ink/15"></div>
        </div>
    </div>
</x-server-workspace-layout>
