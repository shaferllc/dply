{{--
  Drop $lockedTab so the embedded Repository component renders its full
  internal tablist (Overview / Commits / Files / Branches / Connection)
  here — the Overview sub-tab is where the README lives, and Connection
  surfaces the linked source-control account + repo URL + deploy-ref
  form. The other DeploymentsList top-level tabs (Commits/Files/Branches)
  still embed this same component with their own lockedTab values, so
  those panels keep working as direct entry points.
--}}
<livewire:sites.repository
    :server="$server"
    :site="$site"
    :embedded="true"
    wire:key="deployments-repository-{{ $site->id }}"
/>
