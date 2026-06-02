<livewire:sites.workspace-pipeline
    :server="$server"
    :site="$site"
    :embedded="true"
    lockedTab="rollout"
    wire:key="deployments-rollout-{{ $site->id }}"
/>
