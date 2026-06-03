{{-- Unlocked: workspace-pipeline shows its own subtab bar (Overview / Pipeline /
     Rollout / Reference), so the deployments page no longer needs separate
     top-level Pipeline + Rollout tabs. --}}
<livewire:sites.workspace-pipeline
    :server="$server"
    :site="$site"
    :embedded="true"
    wire:key="deployments-pipeline-{{ $site->id }}"
/>
