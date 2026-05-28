@include('livewire.partials.confirm-action-modal')
@include('livewire.servers.partials.remove-server-modal', [
    'open' => $showRemoveServerModal,
    'serverName' => $server->name,
    'serverId' => $server->id,
    'deletionSummary' => $deletionSummary,
])
