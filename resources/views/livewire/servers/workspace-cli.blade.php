<x-server-workspace-layout
    :server="$server"
    active="cli"
    :title="__('CLI')"
    :description="__('Install the dply CLI and run server and site operations from your terminal.')"
>
    @include('livewire.servers.partials.workspace-flashes')

    <x-cli-preview-panel :server="$server" />
</x-server-workspace-layout>
