@php
    $card = 'dply-card overflow-hidden';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="settings"
    :title="__('Settings')"
    :description="__('Use the tabs to switch categories. Each area saves independently.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Server-level settings live here: SSH credentials + key rotation, deploy keys, monitoring toggles, project bindings, alert recipients, billing/cost overrides, and so on. Each tab on the left saves independently — there\'s no global "Save settings" button.') }}</p>
        <p>{{ __('Most settings are dply-side metadata (organization, alerts, project bindings); a few (SSH key rotation, deploy keys) push state to the server itself. Those tabs warn you when their action will run on the box.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        @include('livewire.servers.partials.settings.tabs', ['server' => $server, 'section' => $section])

        @include('livewire.servers.partials.settings-tab', [
            'workspaces' => $workspaces,
            'card' => $card,
            'section' => $section,
        ])
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])

        {{-- Inline channel-create modal. Triggered from the Add subscription
             form's "Create new channel" link; auto-selects the new channel
             on success via the notification-channel-created Livewire event. --}}
        @include('livewire.partials.create-notification-channel-modal')
    </x-slot>
</x-server-workspace-layout>
