{{--
    Lazy-load skeleton for Edge proxy. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and overview stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="edge-proxy"
    :title="__('Edge proxy')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading edge proxy…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrow-path-rounded-square class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Edge proxy') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Optional L7 reverse proxy in front of your webserver. Caddy serves each site on a high port; the edge proxy routes hosts on :80.', ['port' => 80]) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Overview'), __('Add / remove'), __('Traefik'), __('HAProxy')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="space-y-2">
                <div class="h-3.5 w-40 animate-pulse rounded bg-brand-ink/10"></div>
                <div class="h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
            </div>
            <div class="mt-4 h-7 w-48 animate-pulse rounded-full bg-brand-ink/10"></div>
        </div>

        <div class="grid gap-2 px-5 py-5 sm:grid-cols-2 sm:px-6" aria-hidden="true">
            @foreach (range(1, 2) as $tile)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <div class="flex items-start gap-3">
                        <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-36 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
