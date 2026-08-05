{{--
    Lazy-load skeleton for Services. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and inventory row stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="services"
    :title="__('Services')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading services…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-cog-6-tooth"
            :title="__('Services')"
            :note="__('Systemd units from inventory — start, stop, restart, and sync with the same SSH safeguards as Manage.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Inventory'), __('Activity')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="h-3.5 w-36 animate-pulse rounded bg-brand-ink/10"></div>
                <div class="flex gap-2">
                    <span class="h-8 w-24 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    <span class="h-8 w-20 animate-pulse rounded-lg bg-brand-ink/10"></span>
                </div>
            </div>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 6) as $row)
                <li class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                    <span class="h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-44 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-28 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <span class="h-6 w-16 animate-pulse rounded-full bg-brand-ink/10"></span>
                </li>
            @endforeach
        </ul>
    </section>
</x-server-workspace-layout>
