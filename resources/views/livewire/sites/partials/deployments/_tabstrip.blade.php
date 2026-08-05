{{-- Tab list + visibility both come from DeploymentsList::tabDefinitions() /
     tabVisibility(), the same pair placeholder() uses. This partial used to keep
     its own hardcoded copy, which is how the skeleton and the loaded page drifted
     apart (Pipeline flashed in, Releases popped in late). --}}
<x-server-workspace-tablist ariaLabel="{{ __('Deployments sections') }}" scroll class="!mb-0 w-full border-0 bg-transparent p-0 shadow-none">
    @foreach ($tabDefinitions as $entry)
        @if (($tabsVisible[$entry['id']] ?? true))
            <x-server-workspace-tab
                wire:click="setTab('{{ $entry['id'] }}')"
                :active="$tab === $entry['id']"
                :icon="$entry['icon']"
            >{{ $entry['label'] }}</x-server-workspace-tab>
        @endif
    @endforeach
</x-server-workspace-tablist>
