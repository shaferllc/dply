<x-server-workspace-layout
    :server="$server"
    active="maintenance"
    :title="__('Maintenance')"
    :description="__('Visitor maintenance window, site impact, and downtime controls — preview what is shipping next.')"
>
    <x-maintenance-preview-panel :server="$server" />
</x-server-workspace-layout>
