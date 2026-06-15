<x-server-workspace-layout
    :server="$server"
    active="errors"
    :title="__('Errors')"
    :description="__('Every failure on this server and the sites it hosts — newest first. Dismiss what you’ve handled; retry where supported.')"
>
    <x-slot:explainer>
        <p>{{ __('A dedicated stream of failed operations — deploys, SSL, database/cache engine work, connectivity fixes, uptime checks — captured for this server and everything hosted on it. Like the logs, but only errors.') }}</p>
        <p>{{ __('Dismiss is shared with your team. Retry re-runs the original operation for the categories that support it; otherwise open the error to act at its source.') }}</p>
    </x-slot:explainer>

    <div class="space-y-6">
        <x-server-workspace-tablist :aria-label="__('Errors workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
            <x-server-workspace-tab
                id="errors-tab-stream"
                icon="heroicon-o-exclamation-triangle"
                :active="$errorsTab === 'stream'"
                wire:click="setErrorsWorkspaceTab('stream')"
            >
                {{ __('Stream') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="errors-tab-notifications"
                icon="heroicon-o-bell"
                :active="$errorsTab === 'notifications'"
                wire:click="setErrorsWorkspaceTab('notifications')"
            >
                {{ __('Notifications') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        <div class="space-y-6">
            @if ($errorsTab === 'stream')
                @include('livewire.partials.error-stream')
            @endif

            @if ($errorsTab === 'notifications')
                @include('livewire.servers.partials.errors.notifications-tab')
            @endif
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')

    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
