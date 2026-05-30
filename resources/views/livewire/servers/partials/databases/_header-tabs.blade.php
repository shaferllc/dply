@php
    $activeSubtab = in_array($engine_subtab, \App\Livewire\Servers\WorkspaceDatabases::ENGINE_SUBTABS, true)
        ? $engine_subtab
        : 'overview';
    $showAdminTab = \App\Support\Servers\DatabaseWorkspaceEngines::isMysqlFamily($engine)
        || in_array($engine, ['postgres', 'mongodb', 'clickhouse'], true);
    $showExtensionsTab = $engine === 'postgres';
@endphp
{{-- Per-engine sub-tabs — always visible (like webserver), with empty states when not installed. --}}
<x-server-workspace-tablist
    :aria-label="__(':engine workspace sections', ['engine' => $engineLabels[$engine] ?? $engine])"
    scroll
    class="mb-4 w-full"
>
    <x-server-workspace-tab
        :id="'db-subtab-'.$engine.'-overview'"
        icon="heroicon-o-presentation-chart-line"
        :active="$activeSubtab === 'overview'"
        wire:click="setEngineSubtab('overview')"
    >
        {{ __('Overview') }}
    </x-server-workspace-tab>
    <x-server-workspace-tab
        :id="'db-subtab-'.$engine.'-databases'"
        icon="heroicon-o-circle-stack"
        :active="$activeSubtab === 'databases'"
        wire:click="setEngineSubtab('databases')"
    >
        {{ __('Databases') }}
    </x-server-workspace-tab>
    @if ($showAdminTab)
        <x-server-workspace-tab
            :id="'db-subtab-'.$engine.'-admin'"
            icon="heroicon-o-key"
            :active="$activeSubtab === 'admin'"
            wire:click="setEngineSubtab('admin')"
        >
            {{ __('Admin') }}
        </x-server-workspace-tab>
    @endif
    @if ($showExtensionsTab)
        <x-server-workspace-tab
            :id="'db-subtab-'.$engine.'-extensions'"
            icon="heroicon-o-puzzle-piece"
            :active="$activeSubtab === 'extensions'"
            wire:click="setEngineSubtab('extensions')"
        >
            {{ __('Extensions') }}
        </x-server-workspace-tab>
    @endif
    <x-server-workspace-tab
        :id="'db-subtab-'.$engine.'-connections'"
        icon="heroicon-o-link"
        :active="$activeSubtab === 'connections'"
        wire:click="setEngineSubtab('connections')"
    >
        {{ __('Connections') }}
    </x-server-workspace-tab>
    <x-server-workspace-tab
        :id="'db-subtab-'.$engine.'-backups'"
        icon="heroicon-o-archive-box"
        :active="$activeSubtab === 'backups'"
        wire:click="setEngineSubtab('backups')"
    >
        {{ __('Backups') }}
    </x-server-workspace-tab>
    <x-server-workspace-tab
        :id="'db-subtab-'.$engine.'-info'"
        icon="heroicon-o-information-circle"
        :active="$activeSubtab === 'info'"
        wire:click="setEngineSubtab('info')"
    >
        {{ __('Info') }}
    </x-server-workspace-tab>
    <x-server-workspace-tab
        :id="'db-subtab-'.$engine.'-danger'"
        icon="heroicon-o-exclamation-triangle"
        :active="$activeSubtab === 'danger'"
        wire:click="setEngineSubtab('danger')"
    >
        {{ __('Danger') }}
    </x-server-workspace-tab>
</x-server-workspace-tablist>
