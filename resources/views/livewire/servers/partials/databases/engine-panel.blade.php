@php
    $engineRow = $engineRows[$engine] ?? null;
    $engineRunning = $capabilities[$engine] ?? false;
    $isManageable = in_array($engine, \App\Support\Servers\DatabaseWorkspaceEngines::MANAGEABLE, true);
    $dbEngineInfoForTab = \App\Support\Servers\DatabaseEngineInfo::for($engine);
    $engineDatabases = $server->serverDatabases->where('engine', $engine);
    $engineSampleDatabase = $engineDatabases->sortBy('name')->first();
    $showEngineWorkspace = $engine === 'sqlite' || $engineRunning;
@endphp

@include('livewire.servers.partials.databases._header-tabs', compact('engine'))

<div class="space-y-6">
    @if ($engine_subtab === 'databases')
        @include('livewire.servers.partials.databases._subtab-databases', compact('engine', 'engineDatabases', 'showEngineWorkspace', 'card', 'dbEngineInfoForTab', 'capabilities'))
    @elseif ($engine_subtab === 'admin')
        @include('livewire.servers.partials.databases._subtab-admin', compact('engine', 'showEngineWorkspace', 'card', 'dbEngineInfoForTab'))
    @elseif ($engine_subtab === 'info')
        @include('livewire.servers.partials.databases._subtab-info', compact('engine', 'engineRow', 'dbEngineInfoForTab', 'card', 'isDeployer'))
    @elseif ($engine_subtab === 'connections')
        @include('livewire.servers.partials.databases._subtab-connections', compact('engine', 'engineDatabases', 'engineSampleDatabase', 'showEngineWorkspace', 'card', 'dbEngineInfoForTab'))
    @elseif ($engine_subtab === 'backups')
        @include('livewire.servers.partials.databases._subtab-backups', compact('engine', 'showEngineWorkspace', 'card', 'dbEngineInfoForTab'))
    @elseif ($engine_subtab === 'networking' && \App\Support\Servers\DatabaseEngineInstallScripts::supportsRemoteAccess($engine))
        @include('livewire.servers.partials.databases._subtab-networking', compact('engine', 'engineRow', 'engineDatabases', 'showEngineWorkspace', 'card', 'dbEngineInfoForTab'))
    @elseif ($engine_subtab === 'extensions' && $engine === 'postgres')
        @include('livewire.servers.partials.databases._subtab-extensions', compact('engine', 'showEngineWorkspace', 'card'))
    @elseif ($engine_subtab === 'danger')
        @include('livewire.servers.partials.databases._subtab-danger', compact('engine', 'engineDatabases', 'showEngineWorkspace', 'card', 'dbEngineInfoForTab'))
    @else
        @include('livewire.servers.partials.databases._subtab-overview', compact('engine', 'engineRow', 'isManageable', 'showEngineWorkspace', 'engineDatabases', 'card', 'dbEngineInfoForTab', 'capabilities'))
    @endif
</div>
