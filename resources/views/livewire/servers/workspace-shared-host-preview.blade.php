<x-server-workspace-layout
    :server="$server"
    active="shared-host"
    :title="__('Shared Host Radar')"
    :description="__('Per-site load attribution, shared stack dependencies, and contention timeline — preview what is shipping next.')"
>
    <x-shared-host-preview-panel :server="$server" />
</x-server-workspace-layout>
