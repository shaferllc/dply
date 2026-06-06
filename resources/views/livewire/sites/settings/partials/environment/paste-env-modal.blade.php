    {{-- Paste .env: first-class bulk import. Paste a whole .env block and it
         merges into the existing cache — keys not in the paste are preserved,
         pasted keys overwrite. Closes on success (bulkImportEnvVars dispatches
         close-modal) so the operator drops back to the updated list. --}}
    <x-modal name="paste-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Environment') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Paste a .env') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Paste a multi-line .env block — one KEY=value per line. Existing keys you don\'t paste are preserved; pasted keys overwrite matching values.') }}
            </p>
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
                title="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <form wire:submit="bulkImportEnvVars" id="paste-env-form" class="space-y-3">
                <div>
                    <x-input-label for="paste_env_input" :value="__('.env contents')" />
                    <textarea
                        id="paste_env_input"
                        wire:model="bulk_env_input"
                        rows="14"
                        autocomplete="off"
                        spellcheck="false"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        placeholder="# Database settings&#10;DB_PASSWORD=hunter2&#10;&#10;APP_NAME=&quot;My App&quot;&#10;export AWS_REGION=us-east-1"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('# comment lines directly above a KEY=value are kept as that variable\'s comment; free-floating comments and blank lines are dropped.') }}
                    </p>
                    <x-input-error :messages="$errors->get('bulk_env_input')" class="mt-1" />
                </div>
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">
                @if ($supportsEnvPush)
                    {{ __('Imported keys auto-push to the server.') }}
                @else
                    {{ __('Imported keys are injected on the next deploy.') }}
                @endif
            </p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="paste-env-form" wire:loading.attr="disabled" wire:target="bulkImportEnvVars">
                <span wire:loading.remove wire:target="bulkImportEnvVars">{{ __('Import variables') }}</span>
                <span wire:loading wire:target="bulkImportEnvVars" class="inline-flex items-center gap-1.5"><span class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Importing…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
