<x-server-workspace-layout
    :server="$server"
    active="files"
    :title="__('Files')"
    :description="__('Read-only filesystem browser over SSH — preview what is shipping next.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-files-preview-panel :server="$server" />
</x-server-workspace-layout>
