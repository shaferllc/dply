            @if ($revealed_identity)
                <section class="border-b border-brand-ink/10 bg-amber-50 px-3 py-3 sm:px-4">
                    <div class="flex items-start gap-2.5">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0 text-amber-600" />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-amber-900">{{ __('Save this identity now — it is shown once') }}</h2>
                            <p class="mt-0.5 text-xs text-amber-800">{{ __('dply does NOT keep a copy. You must supply this to deploy or reveal customer-held secrets. Lose it and those secrets are unrecoverable.') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg border border-amber-300 bg-white p-2.5 font-mono text-xs text-brand-ink">{{ $revealed_identity }}</pre>
                            <div class="mt-2">
                                <x-secondary-button size="xs" type="button" wire:click="dismissIdentity">{{ __('I have saved it') }}</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            @php
                $customerHeld = $orgKey && $orgKey->identity_holder === \App\Models\OrgSecretKey::HOLDER_CUSTOMER;
            @endphp

            <section class="border-b border-brand-ink/10 last:border-b-0">
                {{-- State, then actions. The three-column <dl> that used to sit here
                     spent a column on "dply can decrypt", which is the same fact the
                     holder chip already states. --}}
                <x-workspace-panel-head dense icon="heroicon-o-key" :title="__('Encryption key')">
                    @can('update', $organization)
                        <x-slot:actions>
                            @if ($orgKey)
                                <x-secondary-button
                                    size="xs"
                                    type="button"
                                    wire:click="confirmRotateEncryptionKey"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmRotateEncryptionKey,rotateToNewCustomerHeldKey,rotateToNewDplyHeldKey"
                                >
                                    {{ __('Rotate key') }}
                                </x-secondary-button>
                            @endif

                            @unless ($customerHeld)
                                {{-- Taking custody is the recommended path, so it stays
                                     inline; handing custody back never should be one
                                     mis-click away, so it lives in the menu. --}}
                                <x-primary-button
                                    size="xs"
                                    type="button"
                                    wire:click="confirmPromoteToCustomerHeld"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmPromoteToCustomerHeld,applyPromoteToCustomerHeld"
                                >
                                    {{ __('Take custody of the key') }}
                                </x-primary-button>
                            @endunless

                            <x-overflow-menu :label="__('More key actions')">
                                <button type="button" wire:click="openAdoptRecipientModal" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    <x-heroicon-o-key class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
                                    {{ $customerHeld ? __('Replace recipient') : __('Adopt your own recipient') }}
                                </button>
                                @if ($customerHeld)
                                    <button
                                        type="button"
                                        wire:click="confirmRevertToDplyHeld"
                                        wire:loading.attr="disabled"
                                        wire:target="confirmRevertToDplyHeld,revertToDplyHeldKey"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-moss hover:bg-rose-50 hover:text-rose-700"
                                    >
                                        <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Revert to dply-managed') }}
                                    </button>
                                @endif
                            </x-overflow-menu>
                        </x-slot:actions>
                    @endcan
                </x-workspace-panel-head>

                <div class="px-3 py-3 sm:px-4">
                    @if ($orgKey)
                        {{-- Custody, recipient, fingerprint as labelled rows. The
                             recipient used to be a truncated grey tail of a sentence;
                             it is the one string here you actually need to copy. --}}
                        <dl class="space-y-2">
                            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <dt class="w-24 shrink-0 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Custody') }}</dt>
                            <dd class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                                @if ($customerHeld)
                                    <span class="inline-flex items-center rounded-full bg-brand-forest/10 px-2 py-0.5 text-xs font-semibold text-brand-forest ring-1 ring-inset ring-brand-forest/20">{{ __('You hold the key') }}</span>
                                    <span class="text-xs text-brand-moss">{{ __('dply stores only ciphertext it cannot open.') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/55 px-2 py-0.5 text-xs font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ __('dply-managed') }}</span>
                                    <span class="text-xs text-brand-moss">{{ __('dply decrypts at deploy — nothing for you to store.') }}</span>
                                @endif
                            </dd>
                            </div>

                            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <dt class="w-24 shrink-0 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Recipient') }}</dt>
                            <dd class="flex min-w-0 flex-1 items-center gap-2" x-data="{ copied: false, async copyRecipient() { try { await navigator.clipboard.writeText(@js($orgKey->public_recipient)); this.copied = true; setTimeout(() => this.copied = false, 1400); } catch (e) {} } }">
                                <code class="min-w-0 flex-1 truncate font-mono text-xs text-brand-moss" title="{{ $orgKey->public_recipient }}">{{ $orgKey->public_recipient }}</code>
                                <button
                                    type="button"
                                    x-on:click="copyRecipient()"
                                    class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-2xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-clipboard-document class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                </button>
                            </dd>
                            </div>

                            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <dt class="w-24 shrink-0 text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Fingerprint') }}</dt>
                            <dd class="min-w-0 flex-1 truncate font-mono text-xs text-brand-mist">{{ $orgKey->fingerprint ?: '—' }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-sm text-brand-moss">{{ __('No key yet — dply mints a managed key automatically the first time you move a secret to the org key.') }}</p>
                    @endif
                </div>
            </section>
