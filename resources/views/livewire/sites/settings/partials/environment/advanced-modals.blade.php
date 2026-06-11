    {{-- "Edit all" modal: the whole .env in one editable textarea. Saving is a
         full replace (saveAllEnv) — distinct from the additive Bulk import.
         Trait-only (edit_all_env / saveAllEnv), so gated like the trigger. --}}
    @if ($envAdvanced)
    <x-modal name="edit-all-env-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Site variables') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Edit all variables') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Edit the entire .env at once. Saving REPLACES every variable — keys you delete here are removed. Changes auto-push to the server.') }}
            </p>
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>
        <div class="px-6 py-5">
            <form wire:submit="saveAllEnv" id="edit-all-env-form">
                <textarea
                    id="edit-all-env-ta"
                    wire:model="edit_all_env"
                    rows="20"
                    spellcheck="false"
                    class="w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                    placeholder="APP_NAME=&quot;My App&quot;&#10;APP_ENV=production&#10;DB_PASSWORD=…"
                ></textarea>
                <x-input-error :messages="$errors->get('edit_all_env')" class="mt-1" />
            </form>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4" x-data="{ copied: false }">
            <p class="mr-auto text-xs text-rose-700">{{ __('Saving replaces ALL variables.') }}</p>
            <button type="button" class="text-xs font-semibold text-brand-sage hover:underline" @click="navigator.clipboard.writeText(document.getElementById('edit-all-env-ta')?.value || ''); copied = true; setTimeout(() => copied = false, 1500)">
                <span x-show="!copied">{{ __('Copy all') }}</span>
                <span x-show="copied" x-cloak class="text-emerald-700">{{ __('Copied') }}</span>
            </button>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="edit-all-env-form" wire:loading.attr="disabled" wire:target="saveAllEnv">
                <span wire:loading.remove wire:target="saveAllEnv">{{ __('Save all') }}</span>
                <span wire:loading wire:target="saveAllEnv" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- Single-variable "Fix" modal. Opened from a Configuration check warning
         (or any keyed finding) — pre-fills the current value with the same
         hint-aware control as the inline editor (toggle for booleans, dropdown
         for enums, masked text otherwise) and offers a one-click suggested fix
         where we know the safe-in-production value. Saving writes just this key
         and auto-pushes. --}}
    <x-modal name="fix-env-var-modal" maxWidth="lg" overlayClass="bg-brand-ink/40">
        @php
            $fixKey = (string) ($fixing_env_key ?? '');
            $fixVal = (string) ($fixing_env_value ?? '');
            $fixHint = \App\Support\Sites\SiteEnvFieldHints::hint($fixKey, $fixVal);
            $fixWarnings = $fixKey !== '' ? ($envWarningsByKey[$fixKey] ?? []) : [];
            $fixSuggestion = $fixKey !== '' ? $this->envFixSuggestionLabel($fixKey, $fixVal) : null;
        @endphp
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Fix variable') }}</p>
            <h2 class="mt-2 font-mono text-xl font-semibold text-brand-ink">{{ $fixKey !== '' ? $fixKey : __('Variable') }}</h2>
            @foreach ($fixWarnings as $fw)
                <p class="mt-2 flex items-start gap-2 pr-10 text-sm leading-6 {{ $fw['level'] === 'danger' ? 'text-rose-800' : 'text-amber-900' }}">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{{ $fw['message'] }}</span>
                </p>
            @endforeach
            <button
                type="button"
                x-on:click="$dispatch('close')"
                wire:click="cancelFixEnvVar"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>
        <div class="px-6 py-5">
            <form wire:submit="saveFixedEnvVar" id="fix-env-var-form" class="space-y-3" wire:key="fix-field-{{ md5($fixKey) }}">
                <div x-data="{ showValue: true }">
                    <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="fixing_env_value">
                        <span>{{ __('Value') }}@if ($fixHint['type'] === 'bool')<span class="ml-1 font-normal text-[11px] text-brand-mist">{{ __('(true / false)') }}</span>@elseif ($fixHint['type'] === 'enum')<span class="ml-1 font-normal text-[11px] text-brand-mist">{{ __('(pick or type)') }}</span>@endif</span>
                        @if ($fixHint['type'] === 'text')
                            <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                <span x-show="!showValue">{{ __('Show') }}</span>
                                <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                            </button>
                        @endif
                    </label>
                    @include('livewire.sites.settings.partials.environment._value-input', ['hint' => $fixHint, 'model' => 'fixing_env_value', 'id' => 'fixing_env_value'])
                    <x-input-error :messages="$errors->get('fixing_env_value')" class="mt-1" />
                </div>
                @if ($fixSuggestion !== null)
                    <button type="button" wire:click="applySuggestedEnvFix" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:underline">
                        <x-heroicon-o-sparkles class="h-4 w-4" />
                        {{ __('Use suggested: :value', ['value' => $fixSuggestion]) }}
                    </button>
                @endif
                {{-- Universal: import THIS key's value from any other site/worker that
                     has it set. Works for every variable (REVERB_*, APP_KEY, DB_*, …)
                     — no per-key special-casing. --}}
                @php $fixSources = $fixKey ? $this->envKeySources($fixKey) : []; @endphp
                @if (! empty($fixSources))
                    <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3">
                        <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-brand-sage">{{ __('Or import :key from another site', ['key' => $fixKey]) }}</p>
                        <div class="space-y-1">
                            @foreach ($fixSources as $s)
                                <div class="flex items-center justify-between gap-2">
                                    <span class="min-w-0 truncate text-xs text-brand-ink">{{ $s['label'] }}<span class="text-brand-mist">{{ $s['server'] ? ' · '.$s['server'] : '' }}</span> <span class="font-mono text-[10px] text-brand-mist">{{ $s['masked'] }}</span></span>
                                    <button type="button" wire:click="importEnvKeyFromSite(@js($fixKey), '{{ $s['id'] }}')" x-on:click="$dispatch('close')" class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Use') }}</button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </form>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Saves this one variable and pushes to the server.') }}</p>
            <div class="flex shrink-0 items-center gap-2">
                <x-secondary-button type="button" x-on:click="$dispatch('close')" wire:click="cancelFixEnvVar">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" form="fix-env-var-form" wire:loading.attr="disabled" wire:target="saveFixedEnvVar">
                    <span wire:loading.remove wire:target="saveFixedEnvVar">{{ __('Save & push') }}</span>
                    <span wire:loading wire:target="saveFixedEnvVar" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </x-modal>
    @endif
