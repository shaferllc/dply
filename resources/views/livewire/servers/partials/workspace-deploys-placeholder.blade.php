{{--
    Lazy-load skeleton for Deploys. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and history rows).
--}}
<x-server-workspace-layout
    :server="$server"
    active="deploys"
    :title="__('Deploys')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading deploys…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Deploys') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('History and deploy-window policy for every site on this server.') }}
                        </p>
                    </div>
                </div>
                <span class="inline-flex h-7 w-24 animate-pulse rounded-full bg-brand-ink/10"></span>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('History'), __('Deploy windows'), __('Coverage'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6" aria-hidden="true">
            <div class="h-3 w-40 animate-pulse rounded bg-brand-ink/10"></div>
        </div>

        <div class="px-5 py-4 sm:px-6" aria-hidden="true">
            <div class="mb-4 flex gap-3">
                <div class="h-9 w-28 animate-pulse rounded-lg bg-brand-ink/10"></div>
            </div>
            <ul class="divide-y divide-brand-ink/10">
                @foreach (range(1, 5) as $row)
                    <li class="flex items-start gap-3 py-3.5">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-brand-ink/15"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-44 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="flex gap-1.5">
                                <span class="h-5 w-14 animate-pulse rounded-full bg-brand-ink/10"></span>
                                <span class="h-5 w-16 animate-pulse rounded-full bg-brand-ink/10"></span>
                                <span class="h-5 w-12 animate-pulse rounded-full bg-brand-ink/10"></span>
                            </div>
                        </div>
                        <span class="h-3 w-10 animate-pulse rounded bg-brand-ink/10"></span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
</x-server-workspace-layout>
