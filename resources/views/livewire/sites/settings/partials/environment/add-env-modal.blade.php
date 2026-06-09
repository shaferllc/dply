    {{-- Add modal: single-row form on top, bulk-paste disclosure underneath.
         Mirrors basic-auth's add modal pattern. --}}
    <x-modal name="add-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Environment variable') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a variable') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Add a single KEY=value pair. To import many at once, use the Paste .env button instead.') }}
            </p>
            {{-- Top-right close. Mirrors the Cancel button at the bottom but
                 is always visible, so the operator can dismiss without
                 scrolling through a long bulk-paste block. --}}
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
            <form wire:submit="addEnvVar" id="add-env-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-1">
                        <x-input-label for="new_env_key" :value="__('Key')" />
                        <x-text-input
                            id="new_env_key"
                            wire:model="new_env_key"
                            class="mt-1 block w-full font-mono text-sm"
                            autocomplete="off"
                            placeholder="APP_DEBUG"
                        />
                        <x-input-error :messages="$errors->get('new_env_key')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2"
                        x-data="{
                            showValue: false,
                            async copyValue() {
                                const v = document.getElementById('new_env_value')?.value || '';
                                if (!v) return;
                                try { await navigator.clipboard.writeText(v); } catch (e) {}
                            },
                        }"
                    >
                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="new_env_value">
                            <span>{{ __('Value') }}</span>
                            <span class="flex items-center gap-3 text-xs">
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="copyValue()">
                                    {{ __('Copy') }}
                                </button>
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                    <span x-show="!showValue">{{ __('Show') }}</span>
                                    <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                </button>
                            </span>
                        </label>
                        <input
                            id="new_env_value"
                            wire:model="new_env_value"
                            x-bind:type="showValue ? 'text' : 'password'"
                            autocomplete="off"
                            spellcheck="false"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                        />
                        <x-input-error :messages="$errors->get('new_env_value')" class="mt-1" />
                    </div>
                </div>
                {{-- Optional comment that renders as a `# ...` line above the
                     KEY=value in the .env file. Useful for "what is this for?"
                     reminders that survive into deploys. Multi-line comments
                     emit one `#` line each. --}}
                <div>
                    <x-input-label for="new_env_comment" :value="__('Comment (optional)')" />
                    <textarea
                        id="new_env_comment"
                        wire:model="new_env_comment"
                        rows="2"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        placeholder="{{ __('e.g. Stripe webhook signing secret — rotate quarterly') }}"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Rendered as a # comment line above this variable in the .env file.') }}
                    </p>
                    <x-input-error :messages="$errors->get('new_env_comment')" class="mt-1" />
                </div>
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">
                @if ($supportsEnvPush)
                    {{ __('Saved and auto-pushed to the server.') }}
                @else
                    {{ __('Saved. Values are injected on the next deploy.') }}
                @endif
            </p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-env-form" wire:loading.attr="disabled" wire:target="addEnvVar">
                <span wire:loading.remove wire:target="addEnvVar">{{ __('Add variable') }}</span>
                <span wire:loading wire:target="addEnvVar" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
