    {{-- Seed / import .env — surfaced before the first deploy (no .env yet,
         never deployed) and available on demand thereafter. Workers in the same
         pool import VERBATIM (same app → shared APP_KEY + backend); other sites
         import SANITIZED (secrets blanked, APP_KEY regenerated). --}}
    @if ($this->needsFirstEnv())
        <div class="{{ $card ?? 'dply-card overflow-hidden' }}">
            <x-workspace-panel-head
                icon="heroicon-o-arrow-down-on-square"
                :title="__('Set up your .env before the first deploy')"
                :note="__('Import from a worker or another site, paste your own, or add keys one at a time.')"
            >
                <x-slot:actions>
                    <button type="button" wire:click="$set('env_import_key', null)" x-on:click="$dispatch('open-modal', 'env-import-modal')" class="dply-btn dply-btn-sm dply-btn-primary">{{ __('Import a .env') }}</button>
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'paste-env-modal')" class="dply-btn dply-btn-sm dply-btn-outline">{{ __('Paste / add') }}</button>
                </x-slot:actions>
            </x-workspace-panel-head>
        </div>
    @endif
