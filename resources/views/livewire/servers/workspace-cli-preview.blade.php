<x-server-workspace-layout
    :server="$server"
    active="cli"
    :title="__('CLI')"
    :description="__('Install the dply CLI and run server operations from your terminal — preview what is shipping next.')"
>
    <x-cli-preview-panel :server="$server" />
</x-server-workspace-layout>
