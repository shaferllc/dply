<x-server-workspace-layout
    :server="$server"
    active="docker"
    :title="__('Docker')"
    :description="__('Containers, images, volumes, networks, and compose — preview what is shipping next.')"
>
    <x-docker-preview-panel :server="$server" />
</x-server-workspace-layout>
