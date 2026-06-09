<livewire:sites.repository
    :server="$server"
    :site="$site"
    :embedded="true"
    lockedTab="branches"
    wire:key="deployments-branches-{{ $site->id }}"
/>
