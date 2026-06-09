<livewire:sites.repository
    :server="$server"
    :site="$site"
    :embedded="true"
    lockedTab="commits"
    wire:key="deployments-commits-{{ $site->id }}"
/>
