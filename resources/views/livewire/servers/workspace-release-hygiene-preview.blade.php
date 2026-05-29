<x-server-workspace-layout
    :server="$server"
    active="hygiene"
    :title="__('Release hygiene')"
    :description="__('Atomic release pressure, log sizes, failed jobs, and disk headroom — preview what is shipping next.')"
>
    <x-release-hygiene-preview-panel :server="$server" />
</x-server-workspace-layout>
