{{-- Reusable "Add backup destination" modal. Backed by the
     ManagesBackupDestinationModal trait; include from any server workspace that
     needs to create a BackupConfiguration inline. --}}
@if ($showDestinationModal)
    <div
        class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-destination-title"
        x-data
        x-on:keydown.escape.window="$wire.closeDestinationModal()"
    >
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeDestinationModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
            <div class="my-auto flex w-full max-w-2xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-cloud-arrow-up class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Storage') }}</p>
                        <h2 id="add-destination-title" class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Add backup destination') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Shared with every server in your organization. Credentials are encrypted at rest.') }}</p>
                    </div>
                    <button type="button" wire:click="closeDestinationModal" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                    {{-- Mode toggle: connect existing storage vs create a new bucket. --}}
                    <div class="inline-flex rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-1 text-sm">
                        <button type="button" wire:click="$set('destination_create_mode', 'connect')"
                            @class([
                                'rounded-lg px-3 py-1.5 font-medium transition',
                                'bg-white text-brand-ink shadow-sm' => $destination_create_mode === 'connect',
                                'text-brand-moss hover:text-brand-ink' => $destination_create_mode !== 'connect',
                            ])>{{ __('Connect existing') }}</button>
                        <button type="button" wire:click="$set('destination_create_mode', 'provision')"
                            @class([
                                'rounded-lg px-3 py-1.5 font-medium transition',
                                'bg-white text-brand-ink shadow-sm' => $destination_create_mode === 'provision',
                                'text-brand-moss hover:text-brand-ink' => $destination_create_mode !== 'provision',
                            ])>{{ __('Create new bucket') }}</button>
                    </div>

                    @if ($destination_create_mode === 'connect')
                        <p class="text-sm text-brand-moss">{{ __('Point dply at a bucket you already have. Works with any S3-compatible provider.') }}</p>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="dest_name" :value="__('Name')" />
                                <x-text-input id="dest_name" wire:model="destinationForm.name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production S3') }}" autocomplete="off" />
                                <x-input-error :messages="$errors->get('destinationForm.name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="dest_provider" :value="__('Storage provider')" />
                                <select id="dest_provider" wire:model.live="destinationForm.provider" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                    @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                        @php $providerAvailable = \App\Models\BackupConfiguration::isProviderAvailable($p); @endphp
                                        <option value="{{ $p }}" @disabled(! $providerAvailable)>{{ \App\Models\BackupConfiguration::labelForProvider($p) }}@unless ($providerAvailable) — {{ __('coming soon') }}@endunless</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('destinationForm.provider')" class="mt-2" />
                            </div>
                        </div>

                        @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'destinationForm', 'form' => $destinationForm])
                    @else
                        @php
                            $provisionProviders = $this->provisionableObjectStorageProviders();
                            $canAutoMint = $this->provisionCanAutoMint();
                            $savedCreds = $this->savedObjectStorageCredentials();
                        @endphp
                        <p class="text-sm text-brand-moss">{{ __('Create a brand-new bucket on the provider and wire it up here.') }}</p>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="prov_name" :value="__('Name')" />
                                <x-text-input id="prov_name" wire:model="provisionForm.name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Backups (Spaces)') }}" autocomplete="off" />
                                <x-input-error :messages="$errors->get('provisionForm.name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="prov_provider" :value="__('Provider')" />
                                <select id="prov_provider" wire:model.live="provisionForm.provider" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                    @foreach ($provisionProviders as $key => $meta)
                                        <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provisionForm.provider')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="prov_region" :value="__('Region')" />
                                <select id="prov_region" wire:model="provisionForm.region" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                    <option value="">{{ __('Pick a region…') }}</option>
                                    @foreach (($provisionProviders[$provisionForm['provider']]['regions'] ?? []) as $regionKey => $regionLabel)
                                        <option value="{{ $regionKey }}">{{ is_string($regionLabel) ? $regionLabel : $regionKey }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('provisionForm.region')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="prov_bucket" :value="__('New bucket name')" />
                                <x-text-input id="prov_bucket" wire:model="provisionForm.bucket" type="text" class="mt-1 block w-full font-mono" placeholder="my-app-backups" autocomplete="off" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Lowercase letters, digits, dots, and dashes. Must be globally unique on the provider.') }}</p>
                                <x-input-error :messages="$errors->get('provisionForm.bucket')" class="mt-2" />
                            </div>
                        </div>

                        @if ($canAutoMint)
                            {{-- api_managed provider (DO Spaces) with a connected cloud token:
                                 dply mints the Spaces keys itself — no keys to paste. --}}
                            <div class="flex items-start gap-3 rounded-xl border border-brand-sage/30 bg-brand-sand/20 px-4 py-3 text-sm text-brand-moss">
                                <x-heroicon-o-sparkles class="mt-0.5 h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                                <span>{{ __('dply will create the bucket and its access keys automatically using your connected :provider token. No keys to paste.', ['provider' => $provisionProviders[$provisionForm['provider']]['label'] ?? $provisionForm['provider']]) }}</span>
                            </div>
                        @else
                            @if ($savedCreds->isNotEmpty())
                                <div>
                                    <x-input-label for="prov_saved" :value="__('Keys')" />
                                    <select id="prov_saved" wire:model.live="provision_credential_id" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                        <option value="">{{ __('Enter new keys…') }}</option>
                                        @foreach ($savedCreds as $cred)
                                            <option value="{{ $cred->id }}">{{ $cred->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('provision_credential_id')" class="mt-2" />
                                </div>
                            @endif

                            @if ($provision_credential_id === '')
                                @php $provMeta = $provisionProviders[$provisionForm['provider']] ?? []; @endphp
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs leading-relaxed text-brand-moss">
                                    {{ $provMeta['key_help'] ?? __('Enter this provider\'s S3 object-storage keys (not your compute API token).') }}
                                    @if (! empty($provMeta['key_console_url']))
                                        <a href="{{ $provMeta['key_console_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1 font-semibold text-brand-forest hover:underline">
                                            {{ __('Open :provider console', ['provider' => $provMeta['label'] ?? __('provider')]) }}
                                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                        </a>
                                    @endif
                                </div>
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="prov_key" :value="__('Access key')" />
                                        <x-text-input id="prov_key" wire:model="provisionForm.access_key" type="text" class="mt-1 block w-full" autocomplete="off" />
                                        <x-input-error :messages="$errors->get('provisionForm.access_key')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-input-label for="prov_secret" :value="__('Access secret')" />
                                        <x-text-input id="prov_secret" wire:model="provisionForm.secret" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                        <x-input-error :messages="$errors->get('provisionForm.secret')" class="mt-2" />
                                    </div>
                                </div>
                                <label class="flex items-center gap-2 text-sm text-brand-moss">
                                    <input type="checkbox" wire:model="provision_save_credential" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                                    {{ __('Save these keys to my organization for reuse') }}
                                </label>
                            @endif
                        @endif
                    @endif

                    {{-- Pricing transparency + no-cut disclaimer for the active provider. --}}
                    @php
                        $activeProvider = $destination_create_mode === 'provision'
                            ? ($provisionForm['provider'] ?? '')
                            : ($destinationForm['provider'] ?? '');
                        $pricing = $this->objectStoragePricing($activeProvider);
                        $noCut = $this->objectStorageNoCutDisclaimer();
                    @endphp
                    @if ($pricing['note'] !== '' || $noCut !== '')
                        <div class="space-y-1.5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-xs leading-relaxed text-brand-moss">
                            <p class="flex items-center gap-1.5 font-semibold uppercase tracking-wide text-brand-sage">
                                <x-heroicon-o-banknotes class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Pricing') }}
                            </p>
                            @if ($pricing['note'] !== '')
                                <p>{{ $pricing['note'] }}</p>
                            @endif
                            @if ($noCut !== '')
                                <p class="font-medium text-brand-ink">{{ $noCut }}</p>
                            @endif
                            @if ($pricing['url'] !== '')
                                <a href="{{ $pricing['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-semibold text-brand-forest hover:underline">
                                    {{ __('See provider pricing') }}
                                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                </a>
                            @endif
                            @if ($destination_create_mode === 'provision' && ($pricing['cold_note'] ?? '') !== '')
                                <p class="mt-1 rounded-lg bg-brand-sand/50 px-3 py-2 text-brand-ink/80">
                                    ❄️ {{ $pricing['cold_note'] }}
                                    @if (($pricing['cold_console_url'] ?? '') !== '')
                                        <a href="{{ $pricing['cold_console_url'] }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand-forest hover:underline">{{ __('Open console') }}</a>
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeDestinationModal">{{ __('Cancel') }}</x-secondary-button>
                    @if ($destination_create_mode === 'connect')
                        <button
                            type="button"
                            wire:click="saveDestination"
                            wire:loading.attr="disabled"
                            wire:target="saveDestination"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="saveDestination" class="inline-flex items-center gap-2">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Save destination') }}
                            </span>
                            <span wire:loading wire:target="saveDestination" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="provisionDestinationBucket"
                            wire:loading.attr="disabled"
                            wire:target="provisionDestinationBucket"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="provisionDestinationBucket" class="inline-flex items-center gap-2">
                                <x-heroicon-o-cloud-arrow-up class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create bucket & add') }}
                            </span>
                            <span wire:loading wire:target="provisionDestinationBucket" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Creating…') }}
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
