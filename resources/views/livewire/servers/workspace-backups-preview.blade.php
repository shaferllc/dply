<x-server-workspace-layout
    :server="$server"
    active="backups"
    :title="__('Backups')"
    :description="__('Database and site-files backup runs and schedules — preview what is shipping next.')"
>
    <x-backups-preview-panel :server="$server" />
</x-server-workspace-layout>
