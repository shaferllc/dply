{{--
    Lazy-load skeleton for Overview. Mirrors the identity-hero card
    (hide-hero + single merged header with facts / summary tiles / metrics)
    instead of the generic split hero + three pulsing cards.
--}}
@php
    $ssh = method_exists($server, 'getSshConnectionString')
        ? $server->getSshConnectionString()
        : trim((string) (($server->ssh_user ?: 'root').'@'.($server->ip_address ?: '')));
@endphp

<x-server-workspace-layout
    :server="$server"
    active="overview"
    :title="__('Overview')"
    hide-hero
>
    <div class="space-y-4" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading overview…') }}</span>

        <section class="dply-card overflow-hidden" aria-hidden="true">
            <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                <div class="lg:col-span-7">
                    <div class="flex items-start gap-3">
                        <x-icon-badge size="md" tone="brand">
                            <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Server') }}</p>
                            <h1 class="mt-1 truncate text-xl font-semibold tracking-tight text-brand-ink">{{ $server->name }}</h1>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                                <span class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/10 bg-white px-2 py-0.5">
                                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand-gold"></span>
                                    {{ __('Loading…') }}
                                </span>
                                <span class="inline-flex items-center gap-1 font-mono">
                                    <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">SSH</span>
                                    <span class="break-all text-brand-ink">{{ $ssh }}</span>
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                @foreach (range(1, 4) as $chip)
                                    <span class="inline-flex h-6 w-16 animate-pulse rounded-md bg-brand-ink/10"></span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-5">
                    <dl class="divide-y divide-brand-ink/10 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                        <div class="grid grid-cols-3 divide-x divide-brand-ink/10">
                            @foreach ([__('Provider'), __('Region'), __('Size')] as $label)
                                <div class="min-w-0 px-3 py-2.5 sm:px-4">
                                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                                    <dd class="mt-1.5 h-4 w-16 animate-pulse rounded bg-brand-ink/10"></dd>
                                </div>
                            @endforeach
                        </div>
                        @foreach ([__('Status'), __('IP')] as $label)
                            <div class="flex items-baseline justify-between gap-4 px-3 py-2.5 sm:px-4">
                                <dt class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                                <dd class="h-4 w-24 animate-pulse rounded bg-brand-ink/10"></dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>

            <div class="border-t border-brand-ink/10 bg-brand-sand/25 p-6 sm:p-8">
                <div class="mb-3 flex items-center gap-2">
                    <x-heroicon-o-squares-2x2 class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Workspace summary') }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    @foreach (range(1, 5) as $tile)
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <div class="h-2.5 w-14 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="mt-2 h-5 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="mt-1.5 h-3 w-28 animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="border-t border-brand-ink/10 p-6 sm:p-8">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <div class="h-2.5 w-24 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach (range(1, 3) as $metric)
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                            <div class="h-2.5 w-10 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="mt-2 h-6 w-14 animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="mt-2 h-1.5 w-full animate-pulse rounded-full bg-brand-ink/10"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="dply-card overflow-hidden p-6 sm:p-8" aria-hidden="true">
            <div class="h-3 w-28 animate-pulse rounded bg-brand-ink/10"></div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (range(1, 6) as $cell)
                    <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-3">
                        <div class="h-2.5 w-16 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="mt-2 h-4 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-server-workspace-layout>
