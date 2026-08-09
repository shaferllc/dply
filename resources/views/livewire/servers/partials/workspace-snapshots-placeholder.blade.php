{{--
    Lazy-load skeleton for Snapshots. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and capture-form stubs), and shares the panel body
    with the tab-switch skeleton so the two can't drift apart.
--}}
<x-server-workspace-layout
    :server="$server"
    active="snapshots"
    :title="__('Snapshots')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading snapshots…') }}</span>

        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-camera"
            :title="__('Snapshots')"
            :note="__('Point-in-time, full-state captures of this server — disk images, cache RDB, and database snapshots. Heavier than logical Backups, and restorable to a moment in time.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Server images'), __('Cache'), __('Databases'), __('Volumes'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-xs font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        @include('livewire.servers.partials.snapshots._tab-skeleton', ['tab' => 'images', 'rows' => 3])
    </section>
</x-server-workspace-layout>
