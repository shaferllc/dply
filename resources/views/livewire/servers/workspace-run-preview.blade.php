<x-server-workspace-layout
    :server="$server"
    active="run"
    :title="__('Run')"
    :description="__('Saved commands and ad-hoc shell — preview what is shipping next.')"
>
    <x-run-preview-panel :server="$server" />
</x-server-workspace-layout>
