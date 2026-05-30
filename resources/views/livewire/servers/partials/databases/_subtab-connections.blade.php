@if (! $showEngineWorkspace)
    <div class="{{ $card }} overflow-hidden px-6 py-6 sm:px-8">
        <x-empty-state
            borderless
            icon="heroicon-o-link"
            tone="sage"
            :title="__('Connections unavailable')"
            :description="__('Install :engine on Overview first — then connection snippets, extra users, and credential sharing appear here.', ['engine' => $dbEngineInfoForTab['label']])"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setEngineSubtab('overview')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    {{ __('Go to Overview') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    </div>
@else
    @include('livewire.servers.partials.connection-snippet', [
        'database' => $engineSampleDatabase,
        'card' => $card,
    ])

    @if ($engine !== 'sqlite')
        @include('livewire.servers.partials.extra-users-card', [
            'databases' => $engineDatabases,
            'engine' => $engine,
            'engineLabels' => $engineLabels,
            'card' => $card,
        ])

        @include('livewire.servers.partials.drift-card', [
            'engine' => $engine,
            'engineLabels' => $engineLabels,
            'drift_snapshot' => $drift_snapshot,
            'card' => $card,
        ])

        @include('livewire.servers.partials.share-credentials-form', [
            'databases' => $engineDatabases,
            'orgAllowsCredentialShares' => $orgAllowsCredentialShares,
            'card' => $card,
        ])
    @endif
@endif
