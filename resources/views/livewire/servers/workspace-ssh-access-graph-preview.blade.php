<x-server-workspace-layout
    :server="$server"
    active="ssh-access"
    :title="__('SSH access')"
    :description="__('Who has keys on this server and time-boxed sessions — preview what is shipping next.')"
>
    <x-ssh-access-preview-panel :server="$server" />
</x-server-workspace-layout>
