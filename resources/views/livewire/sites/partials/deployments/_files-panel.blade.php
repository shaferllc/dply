<livewire:sites.repository
    :server="$server"
    :site="$site"
    :embedded="true"
    lockedTab="files"
    wire:key="deployments-files-{{ $site->id }}"
/>
