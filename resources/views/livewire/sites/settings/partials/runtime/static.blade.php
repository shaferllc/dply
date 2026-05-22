<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Static runtime') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Static-site runtime settings. Set the directory the web server publishes after a build.') }}</p>
    </div>

    <form wire:submit="saveRuntimePreferences" class="space-y-6">
        <div>
            <x-input-label for="runtime_settings_document_root" :value="__('Web directory / published path')" />
            <x-text-input id="runtime_settings_document_root" wire:model="settings_document_root" class="mt-1 block w-full font-mono text-sm" placeholder="/var/www/app/public" />
            <p class="mt-1 text-xs text-brand-moss">{{ __('Document root for static HTML and assets served by the web server.') }}</p>
            <x-input-error :messages="$errors->get('settings_document_root')" class="mt-1" />
        </div>

        <div class="border-t border-brand-ink/10 pt-6">
            <x-primary-button type="submit">{{ __('Save static runtime settings') }}</x-primary-button>
        </div>
    </form>
</section>

<x-cli-snippet :commands="[
    ['label' => __('Set published path'), 'command' => 'dply:site:set-runtime '.$site->slug.' --runtime=static --document-root=/var/www/app/public'],
]" />
