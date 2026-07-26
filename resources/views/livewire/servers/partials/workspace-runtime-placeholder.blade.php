{{--
    Lazy-load skeleton for Runtime (PHP). Mirrors the merged page (hide-hero +
    single card with identity, summary tiles, and version row stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="php"
    :title="__('Runtime')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading runtime…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Runtime') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('PHP inventory, CLI default, and new-site default for this server.') }}
                        </p>
                    </div>
                </div>
                <span class="h-8 w-32 animate-pulse rounded-lg bg-brand-ink/10"></span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-2 border-b border-brand-ink/10 px-5 py-5 sm:grid-cols-3 sm:px-6" aria-hidden="true">
            @foreach (range(1, 3) as $tile)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                    <div class="h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="mt-2 h-5 w-16 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="mt-2 h-2 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6" aria-hidden="true">
            <div class="flex items-start gap-3">
                <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 4) as $row)
                <li class="flex items-center gap-3 px-5 py-4 sm:px-6">
                    <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-32 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-44 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <span class="h-8 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </li>
            @endforeach
        </ul>
    </section>
</x-server-workspace-layout>
