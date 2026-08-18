            @if ($revealed_identity)
                <section class="border-b border-brand-ink/10 bg-amber-50 px-3 py-3 sm:px-4">
                    <div class="flex items-start gap-2.5">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0 text-amber-600" />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-amber-900">{{ __('Save this identity now — it is shown once') }}</h2>
                            <p class="mt-0.5 text-xs text-amber-800">{{ __('dply does NOT keep a copy. You must supply this to deploy or reveal customer-held secrets. Lose it and those secrets are unrecoverable.') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg border border-amber-300 bg-white p-2.5 font-mono text-xs text-brand-ink">{{ $revealed_identity }}</pre>
                            <div class="mt-2">
                                <x-secondary-button type="button" wire:click="dismissIdentity">{{ __('I have saved it') }}</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-key"
                    :title="__('Encryption key')"
                    :note="__('Who holds the key that encrypts secrets moved out of plaintext .env.')"
                />
                <div class="space-y-3 px-3 py-3 sm:px-4">
                    @if ($orgKey)
                        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Held by') }}</dt>
                                <dd class="mt-0.5 text-sm text-brand-ink">
                                    @if ($orgKey->identity_holder === \App\Models\OrgSecretKey::HOLDER_CUSTOMER)
                                        <span class="inline-flex items-center rounded-full bg-brand-forest/10 px-2 py-0.5 text-xs font-semibold text-brand-forest ring-1 ring-inset ring-brand-forest/20">{{ __('You (customer-held)') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-brand-sand/55 px-2 py-0.5 text-xs font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ __('dply-managed') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Fingerprint') }}</dt>
                                <dd class="mt-0.5 font-mono text-sm text-brand-ink">{{ $orgKey->fingerprint ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('dply can decrypt') }}</dt>
                                <dd class="mt-0.5 text-sm text-brand-ink">{{ $orgKey->dplyCanDecrypt() ? __('Yes') : __('No') }}</dd>
                            </div>
                        </dl>
                        <p class="break-all font-mono text-xs text-brand-moss">{{ $orgKey->public_recipient }}</p>
                    @else
                        <p class="text-sm text-brand-moss">{{ __('No key yet — dply mints a managed key automatically the first time you move a secret to the org key. Or establish a customer-held key below.') }}</p>
                    @endif

                    @can('update', $organization)
                        @if ($orgKey)
                            <div class="flex flex-wrap gap-2">
                                <x-secondary-button
                                    type="button"
                                    wire:click="confirmRotateEncryptionKey"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmRotateEncryptionKey,rotateToNewCustomerHeldKey,rotateToNewDplyHeldKey"
                                >
                                    {{ __('Rotate key') }}
                                </x-secondary-button>
                                @if ($orgKey->identity_holder === \App\Models\OrgSecretKey::HOLDER_CUSTOMER)
                                    <x-secondary-button
                                        type="button"
                                        wire:click="confirmRevertToDplyHeld"
                                        wire:loading.attr="disabled"
                                        wire:target="confirmRevertToDplyHeld,revertToDplyHeldKey"
                                    >
                                        {{ __('Revert to dply-managed') }}
                                    </x-secondary-button>
                                @endif
                            </div>
                        @endif

                        @if (! $orgKey || $orgKey->identity_holder === \App\Models\OrgSecretKey::HOLDER_DPLY)
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-2.5">
                                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Switch to a customer-held key') }}</h3>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('After this, dply stores only ciphertext it cannot open. You supply the identity at deploy time. Existing dply-managed secrets must be re-moved under the new key.') }}</p>
                                <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end">
                                    <div>
                                        <x-primary-button type="button" wire:click="confirmPromoteToCustomerHeld" wire:loading.attr="disabled" wire:target="confirmPromoteToCustomerHeld,applyPromoteToCustomerHeld">
                                            {{ __('Generate a customer-held key') }}
                                        </x-primary-button>
                                    </div>
                                    <div class="flex-1">
                                        <x-input-label for="recipient_input" :value="__('…or bring your own age recipient')" />
                                        <div class="mt-1 flex gap-2">
                                            <x-text-input id="recipient_input" wire:model="recipient_input" class="block w-full font-mono text-sm" placeholder="age1…" />
                                            <x-secondary-button type="button" wire:click="adoptRecipient" wire:loading.attr="disabled" wire:target="adoptRecipient,applyAdoptRecipient">{{ __('Adopt') }}</x-secondary-button>
                                        </div>
                                        <x-input-error :messages="$errors->get('recipient_input')" class="mt-1" />
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-2.5">
                                <h3 class="text-xs font-semibold text-brand-ink">{{ __('Replace with a different recipient') }}</h3>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Adopt another age1… public key you already hold. The current key is discarded; escrowed secrets stay locked to it unless you re-move them.') }}</p>
                                <div class="mt-2">
                                    <x-input-label for="recipient_input" :value="__('age recipient')" />
                                    <div class="mt-1 flex gap-2">
                                        <x-text-input id="recipient_input" wire:model="recipient_input" class="block w-full font-mono text-sm" placeholder="age1…" />
                                        <x-secondary-button type="button" wire:click="adoptRecipient" wire:loading.attr="disabled" wire:target="adoptRecipient,applyAdoptRecipient">{{ __('Adopt') }}</x-secondary-button>
                                    </div>
                                    <x-input-error :messages="$errors->get('recipient_input')" class="mt-1" />
                                </div>
                            </div>
                        @endif
                    @endcan
                </div>
            </section>

            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-server-stack"
                    :title="__('External secret stores')"
                    :note="__('Reference secrets that live in your own store; the value never enters dply.')"
                />

                @if ($stores->isNotEmpty())
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($stores as $store)
                            <li class="flex items-center justify-between gap-3 px-3 py-2 sm:px-4" wire:key="store-{{ $store->id }}">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $store->name }}</p>
                                    <p class="text-xs text-brand-moss">
                                        {{ strtoupper($store->driver) }} ·
                                        {{ $store->resolution === \App\Models\ExternalSecretStore::RESOLUTION_ONBOX ? __('resolved on the server (dply never sees values)') : __('resolved by dply at deploy') }}
                                    </p>
                                </div>
                                @can('update', $organization)
                                    <button
                                        type="button"
                                        class="inline-flex h-6 items-center px-1.5 text-xs font-semibold text-rose-700 hover:text-rose-900"
                                        wire:click="openConfirmActionModal('deleteStore', ['{{ $store->id }}'], @js(__('Remove secret store')), @js(__('Remove this store? Sites referencing it will fail to resolve.')), @js(__('Remove')), true)"
                                    >
                                        {{ __('Remove') }}
                                    </button>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="px-3 py-4 text-sm text-brand-moss sm:px-4">{{ __('No external stores yet.') }}</p>
                @endif

                @can('update', $organization)
                    <div class="border-t border-brand-ink/10 px-3 py-3 sm:px-4">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ __('Add a store') }}</h3>
                        <div class="mt-2 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
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
                        <div class="mt-2.5">
                            <x-primary-button type="button" wire:click="createStore" wire:loading.attr="disabled" wire:target="createStore">
                                {{ __('Add store') }}
                            </x-primary-button>
                        </div>
                    </div>
                @endcan
            </section>
