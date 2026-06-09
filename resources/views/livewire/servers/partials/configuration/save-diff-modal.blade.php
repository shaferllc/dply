@if ($configSaveConfirmOpen && $configSaveDiffText !== null)
    <x-modal name="config-save-confirm" :show="true" wire:model="configSaveConfirmOpen" max-width="3xl">
        <div class="space-y-4 p-6">
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Review changes before save') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Confirm you want to write these changes to :path on the server.', ['path' => $config_selected_path]) }}</p>
            </div>

            <pre class="max-h-[45vh] overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-xs leading-5 text-emerald-200">{{ $configSaveDiffText !== '' ? $configSaveDiffText : __('(no differences)') }}</pre>

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="closeConfigSaveDiff" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    wire:click="confirmConfigSave"
                    wire:loading.attr="disabled"
                    wire:target="confirmConfigSave"
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-forest bg-brand-forest px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-50"
                >
                    <x-heroicon-o-cloud-arrow-up class="h-4 w-4" />
                    {{ __('Confirm save') }}
                </button>
            </div>
        </div>
    </x-modal>
@endif
