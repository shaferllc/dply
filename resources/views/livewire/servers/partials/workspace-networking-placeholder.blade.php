{{--
    Lazy-load skeleton for Networking. Mirrors the merged page (hide-hero +
    single card with identity, tabs, and map stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="networking"
    :title="__('Networking')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading networking…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Networking') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('All servers in your workspace — their private IPs, running services, and which databases are open to the network.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Servers'), __('Access'), __('Attached'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-72 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3 px-5 py-8 sm:px-6">
                @foreach (range(1, 3) as $col)
                    <div class="space-y-3">
                        @foreach (range(1, 3) as $row)
                            <div class="h-14 animate-pulse rounded-xl bg-brand-ink/10"></div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</x-server-workspace-layout>
