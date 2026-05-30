<x-server-workspace-layout
    :server="$server"
    active="ssh-access"
    :title="__('Access graph')"
    :description="__('Who had SSH access on this server over time — preview what is shipping next.')"
>
    <x-ssh-access-preview-panel :server="$server" />
</x-server-workspace-layout>
