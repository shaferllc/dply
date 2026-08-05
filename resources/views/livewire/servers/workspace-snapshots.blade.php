<x-server-workspace-layout
    :server="$server"
    active="snapshots"
    :title="__('Snapshots')"
    :description="__('Point-in-time, full-state captures of this server — disk images, cache RDB, and database snapshots. Heavier than logical Backups, and restorable to a moment in time.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-camera"
            :title="__('Snapshots')"
            :note="__('Point-in-time, full-state captures of this server — disk images, cache RDB, and database snapshots. Heavier than logical Backups, and restorable to a moment in time.')"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Snapshot types')" scroll class="!mb-0 w-full border-0 bg-transparent p-0 shadow-none">
                <x-server-workspace-tab id="snapshots-tab-images" :active="$snapshots_tab === 'images'" wire:click="setSnapshotsTab('images')" icon="heroicon-o-camera">
                    {{ __('Server images') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="snapshots-tab-cache" :active="$snapshots_tab === 'cache'" wire:click="setSnapshotsTab('cache')" icon="heroicon-o-bolt">
                    {{ __('Cache') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="snapshots-tab-databases" :active="$snapshots_tab === 'databases'" wire:click="setSnapshotsTab('databases')" icon="heroicon-o-circle-stack">
                    {{ __('Databases') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="snapshots-tab-volumes" :active="$snapshots_tab === 'volumes'" wire:click="setSnapshotsTab('volumes')" icon="heroicon-o-square-3-stack-3d">
                    {{ __('Volumes') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="snapshots-tab-notifications" :active="$snapshots_tab === 'notifications'" wire:click="setSnapshotsTab('notifications')" icon="heroicon-o-bell">
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setSnapshotsTab">
            @if ($snapshots_tab === 'images')
                @include('livewire.servers.partials.snapshots._tab-images')
            @elseif ($snapshots_tab === 'cache')
                @include('livewire.servers.partials.snapshots._tab-cache')
            @elseif ($snapshots_tab === 'databases')
                @include('livewire.servers.partials.snapshots._tab-databases')
            @elseif ($snapshots_tab === 'notifications')
                @include('livewire.servers.partials.snapshots._tab-notifications')
            @else
                @include('livewire.servers.partials.snapshots._tab-volumes')
            @endif
        </div>
    </section>

    {{-- Shared confirm + add-destination modals. --}}
    @include('livewire.partials.confirm-action-modal')
    @include('livewire.servers.partials.backups._add-destination-modal')
    {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
         shared with the Notifications tab so an operator can add a channel without
         leaving the page; the new channel is auto-selected on success. --}}
    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
