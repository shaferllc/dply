<x-server-workspace-layout
    :server="$server"
    active="logs"
    :title="__('Logs')"
    :description="__('View Dply activity and system logs for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4">
        <p>{{ __('Logs from systemd, nginx/caddy, PHP-FPM, and dply\'s own activity stream — read live from the server over SSH. Pick a source from the left, and the viewer tails the most recent lines and streams new ones via Reverb (with a poll fallback).') }}</p>
        <p>{{ __('Time ranges are server-side filters: "Last 5 minutes" reads only the recent slice of each file; broader ranges page through more of the file. Sources that rotate (e.g. nginx access.log) honor the rotation — older entries roll off naturally.') }}</p>
    </x-explainer>

    <div
        id="dply-server-log-broadcast-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $logBroadcastEchoSubscribable ? '1' : '0' }}"
    ></div>

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
