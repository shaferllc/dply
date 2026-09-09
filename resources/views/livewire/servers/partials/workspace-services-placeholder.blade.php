{{--
    Lazy-load skeleton for Services. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and the Inventory body), and shares that body with
    the tab-switch skeleton so the two can't drift apart.
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

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Inventory'), __('Activity')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-xs font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        @include('livewire.servers.partials.services._tab-skeleton', ['tab' => 'inventory'])
    </section>
</x-server-workspace-layout>
