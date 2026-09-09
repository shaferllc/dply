@include('livewire.servers.partials.databases._generated-credentials-banner')
@include('livewire.servers.partials.databases._cache-crosslink', ['server' => $server, 'opsReady' => $opsReady])

@php
    $anyEngine = in_array(true, $capabilities, true);
    $installedDatabases = $server->serverDatabases->sortBy('name');
@endphp

{{-- One section, not three. This tab used to stack a "CREATE / New database"
     header, a strip holding nothing but the Create button, and a second
     "DATABASES / Installed databases" header above the same list — roughly
     300px of chrome before the first row. Create now rides the page head, so
     what is left is the list and the states that replace it. --}}
@if (! $capabilitiesLoaded)
    <div class="flex items-center gap-2 px-4 py-3.5 text-xs text-brand-moss sm:px-5">
        <x-spinner variant="forest" size="sm" />
        {{ __('Checking which database engines are installed on this server…') }}
    </div>
@elseif (! $anyEngine)
    <div class="px-4 py-5 sm:px-5">
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-server-stack"
            tone="amber"
            :title="__('No database engine installed')"
            :description="__('Install MySQL, MariaDB, PostgreSQL, or SQLite on an engine tab, or open Advanced → Server sync and click Recheck engines.')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setWorkspaceTab('advanced')"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    {{ __('Open Advanced') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    </div>
@elseif ($installedDatabases->isEmpty())
    <div class="px-4 py-5 sm:px-5">
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-circle-stack"
            tone="sage"
            :title="__('No databases yet')"
            :description="__('Create your first database — Dply provisions the schema and credentials for you.')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="openDatabaseCreate"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('Create database') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    </div>
@else
    @include('livewire.servers.partials.databases-list', [
        'databases' => $installedDatabases,
    ])
@endif
