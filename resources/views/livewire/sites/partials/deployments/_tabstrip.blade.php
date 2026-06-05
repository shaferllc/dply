@php
    use App\Livewire\Sites\DeploymentsList;
    $tabs = [
        ['id' => DeploymentsList::TAB_OVERVIEW,   'label' => __('Overview'),   'icon' => 'heroicon-o-chart-bar'],
        ['id' => DeploymentsList::TAB_DEPLOY,     'label' => __('Deploy'),     'icon' => 'heroicon-o-rocket-launch'],
        ['id' => DeploymentsList::TAB_ENVIRONMENT, 'label' => __('Environment'), 'icon' => 'heroicon-o-key'],
        ['id' => DeploymentsList::TAB_WEBHOOK,  'label' => __('Webhook'),  'icon' => 'heroicon-o-bolt'],
        ['id' => DeploymentsList::TAB_HOOKS,    'label' => __('Hooks'),    'icon' => 'heroicon-o-link'],
        ['id' => DeploymentsList::TAB_PIPELINE, 'label' => __('Pipeline'), 'icon' => 'heroicon-o-adjustments-horizontal'],
        ['id' => DeploymentsList::TAB_RELEASES, 'label' => __('Releases'), 'icon' => 'heroicon-o-archive-box'],
        ['id' => DeploymentsList::TAB_HISTORY,  'label' => __('History'),  'icon' => 'heroicon-o-clock'],
    ];
@endphp

<x-server-workspace-tablist ariaLabel="{{ __('Deployments sections') }}" scroll>
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
