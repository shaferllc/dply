<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Static') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Static runtime') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Static-site runtime settings. Set the directory the web server publishes after a build.') }}</p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7 space-y-6">
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
    </div>
</section>

<x-cli-snippet :commands="[
    ['label' => __('Set published path'), 'command' => 'dply:site:set-runtime '.$site->slug.' --runtime=static --document-root=/var/www/app/public'],
]" />
