<x-server-workspace-layout
    :server="$server"
    active="deploy-policy"
    :title="__('Deploy windows')"
    :description="__('Server-wide deploy deny windows, live status, and site coverage — preview what is shipping next.')"
>
    <x-deploy-policy-preview-panel :server="$server" />
</x-server-workspace-layout>
