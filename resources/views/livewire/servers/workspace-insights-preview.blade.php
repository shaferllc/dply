<x-server-workspace-layout
    :server="$server"
    active="insights"
    :title="__('Insights')"
    :description="__('Automated health checks and prioritized findings — preview what is shipping next.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-insights-preview-panel :server="$server" />
</x-server-workspace-layout>
