@php
    use App\Livewire\Sites\DeploymentsList;
    $tabs = [
        ['id' => DeploymentsList::TAB_OVERVIEW, 'label' => __('Overview'), 'icon' => 'heroicon-o-chart-bar'],
        ['id' => DeploymentsList::TAB_DEPLOY,   'label' => __('Deploy'),   'icon' => 'heroicon-o-rocket-launch'],
        ['id' => DeploymentsList::TAB_COMMITS,  'label' => __('Commits'),  'icon' => 'heroicon-o-code-bracket-square'],
        ['id' => DeploymentsList::TAB_FILES,    'label' => __('Files'),    'icon' => 'heroicon-o-folder'],
        ['id' => DeploymentsList::TAB_BRANCHES, 'label' => __('Branches'), 'icon' => 'heroicon-o-rectangle-stack'],
        ['id' => DeploymentsList::TAB_PIPELINE, 'label' => __('Pipeline'), 'icon' => 'heroicon-o-adjustments-horizontal'],
        ['id' => DeploymentsList::TAB_ROLLOUT,  'label' => __('Rollout'),  'icon' => 'heroicon-o-arrow-path-rounded-square'],
        ['id' => DeploymentsList::TAB_RELEASES, 'label' => __('Releases'), 'icon' => 'heroicon-o-archive-box'],
        ['id' => DeploymentsList::TAB_HISTORY,  'label' => __('History'),  'icon' => 'heroicon-o-clock'],
        ['id' => DeploymentsList::TAB_SETTINGS, 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth'],
    ];
@endphp

<x-server-workspace-tablist ariaLabel="{{ __('Deployments sections') }}">
    @foreach ($tabs as $entry)
        @if (($tabsVisible[$entry['id']] ?? true))
            <x-server-workspace-tab
                wire:click="setTab('{{ $entry['id'] }}')"
                :active="$tab === $entry['id']"
                :icon="$entry['icon']"
            >{{ $entry['label'] }}</x-server-workspace-tab>
        @endif
    @endforeach
</x-server-workspace-tablist>
