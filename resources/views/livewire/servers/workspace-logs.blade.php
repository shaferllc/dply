<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    :description="__('View Dply activity and system logs for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

    @include('livewire.servers.partials.remote-ssh-stream-panel', ['logViewportLines' => $logDisplayLines])

    @include('livewire.servers.partials.log-viewer-panel', ['logSources' => $logSources])

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
