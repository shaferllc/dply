@php
    $panelBody = 'px-5 py-3 sm:px-6';
    $fieldHelp = 'mt-1 text-[11px] text-brand-moss';
@endphp

<form wire:submit="saveRuntimePreferences" class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-folder"
        :title="__('Static runtime')"
        :note="__('Directory the web server publishes after a build.')"
    >
        <x-slot:actions>
            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveRuntimePreferences">
                <span wire:loading.remove wire:target="saveRuntimePreferences">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveRuntimePreferences">{{ __('Saving…') }}</span>
            </x-primary-button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $panelBody }}">
        <x-input-label for="runtime_settings_document_root" :value="__('Web directory / published path')" class="!text-xs" />
        <x-text-input id="runtime_settings_document_root" wire:model="settings_document_root" class="mt-1 block w-full font-mono text-sm" placeholder="/var/www/app/public" />
        <p class="{{ $fieldHelp }}">{{ __('Document root for static HTML and assets served by the web server.') }}</p>
        <x-input-error :messages="$errors->get('settings_document_root')" class="mt-1" />
    </div>
</form>

<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
    <x-cli-snippet :commands="[
        ['label' => __('Set published path'), 'command' => 'dply sites:runtime:set '.$site->slug.' --runtime=static --document-root=/var/www/app/public'],
    ]" />
</div>
