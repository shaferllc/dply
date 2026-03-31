@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="settings"
    :title="__('Settings')"
    :description="__('Use the tabs to switch categories. Each area saves independently.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div
        class="space-y-6"
        x-data="{ settingsHelpOpen: false }"
        @keydown.window="if ($event.key === '?' && ! ['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable) { $event.preventDefault(); settingsHelpOpen = ! settingsHelpOpen }"
    >
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
    </x-slot>
</x-server-workspace-layout>
