{{--
    Lazy-load skeleton for Webserver. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and overview stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="webserver"
    :title="__('Webserver')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading webserver…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-globe-alt"
            :title="__('Webserver')"
            :note="__('Pick which webserver runs on this box. Switching reprovisions all sites under the new daemon, then service-swaps to :80.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Overview'), __('Change'), __('Health'), __('nginx'), __('Advanced')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6" aria-hidden="true">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="space-y-2">
                        <div class="h-3.5 w-28 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                <span class="h-6 w-16 animate-pulse rounded-full bg-brand-ink/10"></span>
            </div>
        </div>

        <div class="grid gap-2 px-5 py-5 sm:grid-cols-2 sm:px-6" aria-hidden="true">
            @foreach (range(1, 3) as $tile)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <div class="flex items-start gap-3">
                        <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-32 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
