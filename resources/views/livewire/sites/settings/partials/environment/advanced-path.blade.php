    {{-- Advanced: relocate the .env file. Hidden behind a disclosure since
         most operators want the default (the docroot's .env, protected by
         the webserver deny rule we inject by default). Power users can move
         it outside the docroot — e.g. /etc/dply/<slug>.env — for an extra
         layer of defense in case the deny rule ever fails or is removed. --}}
    @if ($supportsEnvPush)
        <details class="{{ $card }}">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cog-6-tooth class="h-4 w-4 text-brand-moss" />
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Advanced — .env file location') }}</span>
                </div>
                <span class="font-mono text-[11px] text-brand-mist">{{ $site->effectiveEnvFilePath() }}</span>
            </summary>
            <div class="px-6 py-5 sm:px-8 space-y-3">
                <p class="text-sm text-brand-moss">
                    {{ __('By default the .env file lives at :default.', ['default' => rtrim($site->effectiveEnvDirectory(), '/').'/.env']) }}
                    {{ __('Override the path to relocate it outside the docroot — useful as defense in depth even with the webserver-level deny rule.') }}
                </p>
                <form wire:submit="saveEnvFilePath" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[18rem]">
                        <x-input-label for="env_file_path_override" :value="__('Absolute path on host (leave blank for default)')" />
                        <x-text-input
                            id="env_file_path_override"
                            wire:model="env_file_path_override"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="/etc/dply/{{ $site->slug }}.env"
                            autocomplete="off"
                            spellcheck="false"
                        />
                        <x-input-error :messages="$errors->get('env_file_path_override')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEnvFilePath">
                        <span wire:loading.remove wire:target="saveEnvFilePath">{{ __('Save path') }}</span>
                        <span wire:loading wire:target="saveEnvFilePath" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
                    </x-primary-button>
                </form>
                <p class="text-[11px] text-brand-moss">
                    {{ __('Push will mkdir -p the parent directory and write the file there. Sync and Load fetch from this path. The webserver deny rule for /.env still applies for the default location.') }}
                </p>
            </div>
        </details>
    @endif
