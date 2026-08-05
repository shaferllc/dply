{{--
    Lazy-load skeleton for Maintenance. Mirrors the merged page (hide-hero +
    single card with identity, tabs, and window stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="maintenance"
    :title="__('Maintenance')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading maintenance…') }}</span>

        <x-workspace-panel-head
            dense
            icon="heroicon-o-wrench"
            :title="__('Maintenance')"
            :note="__('Visitor maintenance window, site impact, and related downtime controls for this server.')"
            class="border-b border-brand-ink/10"
            aria-hidden="true"
        />

        <div class="flex flex-wrap gap-1 border-b border-brand-ink/10 px-4 py-2" aria-hidden="true">
            @foreach ([__('Visitor window'), __('Operations'), __('Schedule'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-[11px] font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10" aria-hidden="true">
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span class="h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-3.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-2.5 w-64 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
                </div>
                <span class="h-6 w-24 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
            </div>
            <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-3 xl:grid-cols-6">
                @foreach (range(1, 6) as $stat)
                    <div class="space-y-1 bg-white px-3 py-2">
                        <div class="h-2 w-16 animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-4 w-10 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                @endforeach
            </div>
            <div class="space-y-2 px-5 py-3 sm:px-6">
                @foreach (range(1, 3) as $row)
                    <div class="h-9 animate-pulse rounded-xl bg-brand-ink/10"></div>
                @endforeach
            </div>
        </div>
    </section>
</x-server-workspace-layout>
