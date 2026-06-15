<x-server-workspace-layout
    :server="$server"
    active="snapshots"
    :title="__('Snapshots')"
    :description="__('Point-in-time, full-state captures of this server — disk images, cache RDB, and database snapshots. Heavier than logical Backups, and restorable to a moment in time.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-slot:explainer>
        <p>
            <span class="font-semibold text-brand-ink">{{ __('Snapshots vs Backups:') }}</span>
            {{ __('Snapshots are full-state, point-in-time captures — a whole-disk server image, a volume, or a cache/database snapshot — that you roll the server back to. They live on your cloud account and are billed by the provider. For granular, portable exports of a single database or a site\'s files to storage you own (and restored by importing), use') }}
            <a href="{{ route('servers.backups', $server) }}" wire:navigate class="font-semibold text-brand-ink underline hover:no-underline">{{ __('Backups') }}</a>{{ __(' instead.') }}
        </p>
    </x-slot:explainer>

    <x-server-workspace-tablist :aria-label="__('Snapshot types')">
        <x-server-workspace-tab id="snapshots-tab-images" :active="$snapshots_tab === 'images'" wire:click="setSnapshotsTab('images')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-camera class="h-4 w-4" aria-hidden="true" />
                {{ __('Server images') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab id="snapshots-tab-cache" :active="$snapshots_tab === 'cache'" wire:click="setSnapshotsTab('cache')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                {{ __('Cache') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab id="snapshots-tab-databases" :active="$snapshots_tab === 'databases'" wire:click="setSnapshotsTab('databases')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-circle-stack class="h-4 w-4" aria-hidden="true" />
                {{ __('Databases') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab id="snapshots-tab-volumes" :active="$snapshots_tab === 'volumes'" wire:click="setSnapshotsTab('volumes')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-square-3-stack-3d class="h-4 w-4" aria-hidden="true" />
                {{ __('Volumes') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab id="snapshots-tab-notifications" :active="$snapshots_tab === 'notifications'" wire:click="setSnapshotsTab('notifications')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-bell class="h-4 w-4" aria-hidden="true" />
                {{ __('Notifications') }}
            </span>
        </x-server-workspace-tab>
    </x-server-workspace-tablist>

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

    {{-- Shared confirm + add-destination modals. --}}
    @include('livewire.partials.confirm-action-modal')
    @include('livewire.servers.partials.backups._add-destination-modal')
    {{-- Reusable inline channel-create modal (CreatesNotificationChannelInline trait),
         shared with the Notifications tab so an operator can add a channel without
         leaving the page; the new channel is auto-selected on success. --}}
    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
