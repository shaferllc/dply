@if (! $showEngineWorkspace)
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            tone="danger"
            icon="heroicon-o-trash"
            :title="__('Destructive actions')"
            :note="__('Drop or detach tracked databases for this engine.')"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-5 sm:px-5">
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-exclamation-triangle"
            tone="rose"
            :title="__('Danger zone unavailable')"
            :description="__('Install :engine on Overview first — destructive drop and detach actions appear here.', ['engine' => $dbEngineInfoForTab['label']])"
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
    </div>
@elseif ($engineDatabases->isEmpty())
    <div class="{{ $card }} border-rose-200/80">
        <x-workspace-panel-head
            dense
            tone="danger"
            icon="heroicon-o-trash"
            :title="__('Destructive actions')"
            :note="__('Dropping a database removes it from the server and from Dply. There is no undo.')"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-3.5 sm:px-5">
            <x-empty-state
                borderless
                compact
                icon="heroicon-o-shield-check"
                tone="rose"
                :title="__('Nothing to remove yet')"
                :description="__('No :engine databases are tracked. Create one on Basics or Overview first — drop and detach actions appear here.', ['engine' => $dbEngineInfoForTab['label']])"
            >
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="setEngineSubtab('overview')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        {{ __('Go to Overview') }}
                    </button>
                </x-slot:actions>
            </x-empty-state>
        </div>
    </div>
@else
    @include('livewire.servers.partials.destructive-actions', [
        'databases' => $engineDatabases,
        'engineLabels' => $engineLabels,
        'card' => $card,
    ])
@endif
