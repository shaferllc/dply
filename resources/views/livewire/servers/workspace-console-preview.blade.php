<x-server-workspace-layout
    :server="$server"
    active="console"
    :title="__('Console')"
    :description="__('SSH from the browser — preview what is shipping next.')"
>
    <x-console-preview-panel :server="$server" />
</x-server-workspace-layout>
