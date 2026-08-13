{{--
    Lazy-load skeleton for Metrics. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and status rows).
--}}
<x-server-workspace-layout
    :server="$server"
    active="monitor"
    :title="__('Metrics')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading metrics…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-sky-200">
                        <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Metrics') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Live usage, history, alert routing, and agent diagnostics.') }}
                        </p>
                    </div>
                </div>
                <span class="inline-flex h-7 w-24 animate-pulse rounded-full bg-brand-ink/10"></span>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Status'), __('History'), __('Notifications'), __('Diagnostics')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex items-start gap-3">
                <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
        </div>

        <dl class="grid grid-cols-1 gap-px bg-brand-ink/5 sm:grid-cols-2 lg:grid-cols-4" aria-hidden="true">
            @foreach (range(1, 4) as $row)
                <div class="bg-white px-5 py-4">
                    <div class="h-2.5 w-16 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="mt-2 h-3.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            @endforeach
        </dl>
    </section>
</x-server-workspace-layout>
