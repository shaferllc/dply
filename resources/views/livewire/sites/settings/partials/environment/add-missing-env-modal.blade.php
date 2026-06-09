    {{-- "Add missing variables" modal: one input per still-missing required
         key, pre-seeded by openMissingEnvModal() with the .env.example sample
         value. Blank inputs are skipped on submit (addMissingEnvVars). --}}
    <x-modal name="add-missing-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-700">{{ __('Missing variables') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add the required variables') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Detected from the deployed code but not set on this site. Fill in the ones you have — blanks are skipped. Saved to the Environment section and auto-pushed to the server.') }}
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

        <div class="max-h-[60vh] overflow-y-auto px-6 py-6">
            <form wire:submit="addMissingEnvVars" id="add-missing-env-form" class="space-y-3">
                @forelse ($missingEnv as $entry)
                    <div wire:key="missing-env-{{ md5($entry['key']) }}">
                        <label class="block font-mono text-xs font-semibold text-brand-ink" for="missing_env_{{ md5($entry['key']) }}">{{ $entry['key'] }}</label>
                        <input
                            id="missing_env_{{ md5($entry['key']) }}"
                            wire:model="missing_env_values.{{ $entry['key'] }}"
                            autocomplete="off"
                            spellcheck="false"
                            class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                            placeholder="{{ $entry['example'] !== null && $entry['example'] !== '' ? $entry['example'] : __('value') }}"
                        />
                        <div class="mt-0.5 flex items-center justify-between gap-2">
                            <p class="text-[11px] text-brand-mist">{{ __('source: :s', ['s' => implode(', ', $entry['sources'])]) }}</p>
                            <div class="flex items-center gap-3">
                                @if ($entry['key'] === 'APP_KEY')
                                    <button type="button" wire:click="generateMissingAppKey" class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-forest hover:underline">
                                        <x-heroicon-o-sparkles class="h-3 w-3" />
                                        {{ __('Generate a key') }}
                                    </button>
                                @endif
                                @if ($canIgnoreEnv)
                                    <button type="button" wire:click="confirmIgnoreEnvKey('{{ $entry['key'] }}')" class="text-[11px] font-semibold text-brand-mist hover:text-rose-700 hover:underline" title="{{ __('Mark this variable as intentionally unset.') }}">{{ __('Ignore this') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-brand-moss">{{ __('Nothing missing — all required variables are set.') }}</p>
                @endforelse
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Saved and auto-pushed to the server.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-missing-env-form" wire:loading.attr="disabled" wire:target="addMissingEnvVars">
                <span wire:loading.remove wire:target="addMissingEnvVars">{{ __('Add variables') }}</span>
                <span wire:loading wire:target="addMissingEnvVars" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
