@if (! $showEngineWorkspace)
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-archive-box"
            :title="__('Recent backups')"
            :note="__('Completed exports for this engine — download or delete them here.')"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-5 sm:px-5">
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-archive-box"
            tone="sage"
            :title="__('Backups unavailable')"
            :description="__('Install :engine on Overview first — then backup exports and downloads appear here.', ['engine' => $dbEngineInfoForTab['label']])"
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
@elseif (! \App\Support\Servers\DatabaseWorkspaceEngines::supportsBackup($engine))
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-archive-box"
            :title="__('Recent backups')"
            :note="__('Completed exports for this engine — download or delete them here.')"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-5 sm:px-5">
            <x-empty-state
                borderless
                compact
                icon="heroicon-o-archive-box"
                tone="amber"
                :title="__('Backups not available yet')"
                :description="__('Automated backup export for :engine is not supported in this workspace yet.', ['engine' => $dbEngineInfoForTab['label']])"
            />
        </div>
    </div>
@else
    @include('livewire.servers.partials.recent-backups-list', [
        'backups' => $recentBackupsByEngine[$engine] ?? collect(),
        'card' => $card,
    ])
@endif
