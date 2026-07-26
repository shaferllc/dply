{{--
    Lazy-load skeleton for Databases. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and basics row stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="databases"
    :title="__('Databases')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading databases…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-800 ring-1 ring-sky-200">
                        <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Databases') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Create databases on this server, then reveal credentials and copy connection details for your apps.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Basics'), __('MySQL'), __('PostgreSQL'), __('Advanced'), __('Notifications')] as $i => $label)
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

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 3) as $row)
                <li class="flex items-start gap-3 px-5 py-4 sm:px-6">
                    <span class="mt-0.5 h-5 w-14 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-3/4 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
</x-server-workspace-layout>
