<x-server-workspace-layout
    :server="$server"
    active="blueprint"
    :title="__('Blueprint')"
    :description="__('Save this server\'s reconciled stack as a golden blueprint for the next VM you provision — preview what is shipping next.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-blueprint-preview-panel :server="$server" />
</x-server-workspace-layout>
