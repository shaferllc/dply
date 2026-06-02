<livewire:sites.workspace-pipeline
    :server="$server"
    :site="$site"
    :embedded="true"
    lockedTab="steps"
    wire:key="deployments-pipeline-{{ $site->id }}"
/>
