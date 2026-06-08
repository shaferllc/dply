@php
    $engineRow = $engineRows[$engine] ?? null;
    $engineRunning = $capabilities[$engine] ?? false;
    $isManageable = in_array($engine, \App\Support\Servers\DatabaseWorkspaceEngines::MANAGEABLE, true);
    $dbEngineInfoForTab = \App\Support\Servers\DatabaseEngineInfo::for($engine);
    $engineDatabases = $server->serverDatabases->where('engine', $engine);
    $engineSampleDatabase = $engineDatabases->sortBy('name')->first();
    $showEngineWorkspace = $engine === 'sqlite' || $engineRunning;

    // Gated engine (MariaDB / MongoDB / ClickHouse behind database.{engine})
    // with nothing installed yet. Every tab but Info shows the shared
    // coming-soon teaser instead of the misleading "Install on Overview first"
    // empty states — Info still describes the engine for evaluation.
    $engineComingSoon = ($comingSoonEngines[$engine] ?? false) && ! $engineRow;
    $comingSoonPreview = $engineComingSoon ? \App\Support\Servers\DatabaseEngineInfo::comingSoonPreview($engine) : null;
@endphp

@include('livewire.servers.partials.databases._header-tabs', compact('engine'))

<div class="space-y-6">
    @if ($engineComingSoon && $engine_subtab === 'info')
        @include('livewire.servers.partials.databases._subtab-info', compact('engine', 'engineRow', 'dbEngineInfoForTab', 'card', 'isDeployer'))
    @elseif ($engineComingSoon)
        <x-workspace-coming-soon
            :server="$server"
            :icon="$comingSoonPreview['icon']"
            :title="$dbEngineInfoForTab['label']"
            :description="$dbEngineInfoForTab['description']"
            :eyebrow="$comingSoonPreview['eyebrow']"
            :heroNote="__(':engine will install on :server when it ships.', ['engine' => $dbEngineInfoForTab['label'], 'server' => $server->name])"
            :lines="$comingSoonPreview['lines']"
            :features="$comingSoonPreview['features']"
            :footnote="__('We’ll enable :engine for your org when it ships — until then, MySQL and PostgreSQL are the supported relational engines.', ['engine' => $dbEngineInfoForTab['label']])"
        />
    @elseif (! $capabilitiesLoaded && $engine !== 'sqlite')
        {{-- Engine reachability is probed off the render path (wire:init=loadCapabilities).
             Show a neutral checking state instead of flashing "Databases unavailable". --}}
        <div class="{{ $card }} px-6 py-10 sm:px-7">
            <div class="flex items-center justify-center gap-3 text-sm text-brand-moss">
                <x-spinner variant="forest" size="sm" />
                {{ __('Checking :engine on this server…', ['engine' => $engineLabels[$engine] ?? ucfirst($engine)]) }}
            </div>
        </div>
    @elseif ($engine_subtab === 'databases')
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
