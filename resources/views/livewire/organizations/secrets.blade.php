<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="secrets">
            <x-livewire-validation-errors />

            {{-- Intro --}}
            <section class="dply-card overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="flex items-start gap-3">
                        <x-icon-badge size="md">
                            <x-heroicon-o-lock-closed class="h-6 w-6" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Secret residency') }}</p>
                            <h1 class="mt-1 text-xl font-semibold text-brand-ink">{{ __('Organization secrets') }}</h1>
                            <p class="mt-2 max-w-2xl text-sm text-brand-moss">
                                {{ __('Choose who holds the key that encrypts secrets you move off the plaintext .env, and register external stores your sites can reference. Move individual variables on each site\'s Environment tab.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- One-time identity reveal --}}
            @if ($revealed_identity)
                <section class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 p-6">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-exclamation-triangle class="h-6 w-6 shrink-0 text-amber-600" />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-amber-900">{{ __('Save this identity now — it is shown once') }}</h2>
                            <p class="mt-1 text-sm text-amber-800">{{ __('dply does NOT keep a copy. You must supply this to deploy or reveal customer-held secrets. Lose it and those secrets are unrecoverable.') }}</p>
                            <pre class="mt-3 overflow-x-auto rounded-lg border border-amber-300 bg-white p-3 font-mono text-[11px] text-brand-ink">{{ $revealed_identity }}</pre>
                            <div class="mt-3">
                                <x-secondary-button type="button" wire:click="dismissIdentity">{{ __('I have saved it') }}</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Organization key --}}
            <section class="mt-6 dply-card overflow-hidden">
                <div class="border-b border-brand-ink/8 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Encryption key') }}</h2>
                </div>
                <div class="p-6 sm:p-8 space-y-5">
                    @if ($orgKey)
                        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('Held by') }}</dt>
                                <dd class="mt-1 text-sm text-brand-ink">
                                    @if ($orgKey->identity_holder === \App\Models\OrgSecretKey::HOLDER_CUSTOMER)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-forest/10 px-2 py-0.5 text-[11px] font-semibold text-brand-forest ring-1 ring-inset ring-brand-forest/20">{{ __('You (customer-held)') }}</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/55 px-2 py-0.5 text-[11px] font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ __('dply-managed') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('Fingerprint') }}</dt>
                                <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $orgKey->fingerprint ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('dply can decrypt') }}</dt>
                                <dd class="mt-1 text-sm text-brand-ink">{{ $orgKey->dplyCanDecrypt() ? __('Yes') : __('No') }}</dd>
                            </div>
                        </dl>
                        <p class="break-all font-mono text-[11px] text-brand-moss">{{ $orgKey->public_recipient }}</p>
                    @else
                        <p class="text-sm text-brand-moss">{{ __('No key yet — dply mints a managed key automatically the first time you move a secret to the org key. Or establish a customer-held key below.') }}</p>
                    @endif

                    @can('update', $organization)
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Switch to a customer-held key') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('After this, dply stores only ciphertext it cannot open. You supply the identity at deploy time. Existing dply-managed secrets must be re-moved under the new key.') }}</p>
                            <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div>
                                    <x-primary-button type="button" wire:click="promoteToCustomerHeld" wire:loading.attr="disabled" wire:target="promoteToCustomerHeld">
                                        {{ __('Generate a customer-held key') }}
                                    </x-primary-button>
                                </div>
                                <div class="flex-1">
                                    <x-input-label for="recipient_input" :value="__('…or bring your own age recipient')" />
                                    <div class="mt-1 flex gap-2">
                                        <x-text-input id="recipient_input" wire:model="recipient_input" class="block w-full font-mono text-sm" placeholder="age1…" />
                                        <x-secondary-button type="button" wire:click="adoptRecipient" wire:loading.attr="disabled" wire:target="adoptRecipient">{{ __('Adopt') }}</x-secondary-button>
                                    </div>
                                    <x-input-error :messages="$errors->get('recipient_input')" class="mt-1" />
                                </div>
                            </div>
                        </div>
                    @endcan
                </div>
            </section>

            {{-- External stores --}}
            <section class="mt-6 dply-card overflow-hidden">
                <div class="border-b border-brand-ink/8 px-6 py-4 sm:px-8">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('External secret stores') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Reference secrets that live in your own store; the value never enters dply.') }}</p>
                </div>

                @if ($stores->isNotEmpty())
                    <ul class="divide-y divide-brand-ink/8">
                        @foreach ($stores as $store)
                            <li class="flex items-center justify-between gap-3 px-6 py-3 sm:px-8" wire:key="store-{{ $store->id }}">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $store->name }}</p>
                                    <p class="text-[11px] text-brand-moss">
                                        {{ strtoupper($store->driver) }} ·
                                        {{ $store->resolution === \App\Models\ExternalSecretStore::RESOLUTION_ONBOX ? __('resolved on the server (dply never sees values)') : __('resolved by dply at deploy') }}
                                    </p>
                                </div>
                                @can('update', $organization)
                                    <x-secondary-button type="button" wire:click="deleteStore('{{ $store->id }}')" wire:confirm="{{ __('Remove this store? Sites referencing it will fail to resolve.') }}">
                                        {{ __('Remove') }}
                                    </x-secondary-button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="px-6 py-5 text-sm text-brand-moss sm:px-8">{{ __('No external stores yet.') }}</p>
                @endif

                @can('update', $organization)
                    <div class="border-t border-brand-ink/8 bg-brand-sand/20 p-6 sm:p-8">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Add a store') }}</h3>
                        <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="store_driver" :value="__('Provider')" />
                                <select id="store_driver" wire:model.live="store_driver" class="dply-input mt-1 w-full">
                                    <option value="vault">{{ __('HashiCorp Vault') }}</option>
                                    <option value="aws_sm">{{ __('AWS Secrets Manager') }}</option>
                                    <option value="doppler">{{ __('Doppler') }}</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="store_name" :value="__('Name')" />
                                <x-text-input id="store_name" wire:model="store_name" class="mt-1 block w-full" placeholder="{{ __('e.g. corp-vault') }}" />
                                <x-input-error :messages="$errors->get('store_name')" class="mt-1" />
                            </div>

                            @if ($store_driver === 'vault')
                                <div>
                                    <x-input-label for="cfg_endpoint" :value="__('Endpoint')" />
                                    <x-text-input id="cfg_endpoint" wire:model="store_form.endpoint" class="mt-1 block w-full" placeholder="https://vault.example.com" />
                                </div>
                                <div>
                                    <x-input-label for="cfg_token" :value="__('Token')" />
                                    <x-text-input id="cfg_token" type="password" wire:model="store_form.token" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="cfg_namespace" :value="__('Namespace (optional)')" />
                                    <x-text-input id="cfg_namespace" wire:model="store_form.namespace" class="mt-1 block w-full" />
                                </div>
                            @elseif ($store_driver === 'aws_sm')
                                <div>
                                    <x-input-label for="cfg_region" :value="__('Region')" />
                                    <x-text-input id="cfg_region" wire:model="store_form.region" class="mt-1 block w-full" placeholder="us-east-1" />
                                </div>
                                <div>
                                    <x-input-label for="cfg_key" :value="__('Access key (omit to use the box IAM)')" />
                                    <x-text-input id="cfg_key" wire:model="store_form.key" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="cfg_secret" :value="__('Secret key (optional)')" />
                                    <x-text-input id="cfg_secret" type="password" wire:model="store_form.secret" class="mt-1 block w-full" />
                                </div>
                            @else
                                <div>
                                    <x-input-label for="cfg_dtoken" :value="__('Token')" />
                                    <x-text-input id="cfg_dtoken" type="password" wire:model="store_form.token" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="cfg_project" :value="__('Project (optional)')" />
                                    <x-text-input id="cfg_project" wire:model="store_form.project" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="cfg_config" :value="__('Config (optional)')" />
                                    <x-text-input id="cfg_config" wire:model="store_form.config" class="mt-1 block w-full" />
                                </div>
                            @endif

                            <div>
                                <x-input-label for="store_resolution" :value="__('Resolution')" />
                                <select id="store_resolution" wire:model="store_resolution" class="dply-input mt-1 w-full">
                                    <option value="dply">{{ __('dply fetches at deploy') }}</option>
                                    <option value="onbox">{{ __('Server fetches (dply never sees values)') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-4">
                            <x-primary-button type="button" wire:click="createStore" wire:loading.attr="disabled" wire:target="createStore">
                                {{ __('Add store') }}
                            </x-primary-button>
                        </div>
                    </div>
                @endcan
            </section>
        </x-organization-shell>
    </div>
</div>
