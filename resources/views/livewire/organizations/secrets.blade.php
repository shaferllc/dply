<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            dense
            :organization="$organization"
            section="secrets"
            :title="__('Secrets')"
            :description="$tab === 'residency'
                ? __('Who holds the key that encrypts secrets moved out of plaintext .env.')
                : __('Shared vault you link onto sites. Write-never values apply on the next deploy.')"
            icon="heroicon-o-lock-closed"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Secrets'), 'icon' => 'lock-closed'],
            ]"
        >
            <x-slot:tabs>
                <x-server-workspace-tablist :aria-label="__('Secrets sections')" bare class="!mb-0">
                    <x-server-workspace-tab :active="$tab === 'secrets'" icon="heroicon-o-lock-closed" wire:click="setTab('secrets')">{{ __('Secrets') }}</x-server-workspace-tab>
                    <x-server-workspace-tab :active="$tab === 'residency'" icon="heroicon-o-key" wire:click="setTab('residency')">{{ __('Residency') }}</x-server-workspace-tab>
                </x-server-workspace-tablist>
            </x-slot:tabs>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    <x-livewire-validation-errors />
                </div>
            @endif

            @if ($tab === 'residency')
                @include('livewire.organizations.secrets.residency')
            @else
                @include('livewire.organizations.secrets.vault')
            @endif
        </x-organization-shell>
    </div>

    @can('update', $organization)
        {{-- Writing a secret is a decision. These forms used to sit permanently
             expanded under their lists — visible whether or not you came to write
             anything — and rotate expanded another one inside the row it belonged
             to. --}}
        <x-modal
            name="new-secret-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="createVaultSecret">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New secret') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Add a shared secret') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('The value cannot be read back once saved — rotate to replace it. Keys may be reused; add a note when the key already exists so you can tell them apart.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="vault_key" :value="__('Key')" />
                        <x-text-input id="vault_key" wire:model="vault_key" class="mt-2 block w-full font-mono uppercase" placeholder="STRIPE_SECRET" autocomplete="off" />
                        <x-input-error :messages="$errors->get('vault_key')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="vault_value" :value="__('Value')" />
                        <x-text-input id="vault_value" type="password" wire:model="vault_value" class="mt-2 block w-full font-mono" autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('vault_value')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="vault_notes" :value="__('Notes')" />
                        <x-text-input id="vault_notes" wire:model="vault_notes" class="mt-2 block w-full" placeholder="{{ __('Required when this key already exists') }}" />
                        <x-input-error :messages="$errors->get('vault_notes')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeNewSecretModal">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createVaultSecret">
                        <span wire:loading.remove wire:target="createVaultSecret" class="inline-flex items-center gap-2">
                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Save secret') }}
                        </span>
                        <span wire:loading wire:target="createVaultSecret" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Saving…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>

        <x-modal
            name="rotate-secret-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="rotateVaultSecret">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Rotate secret') }}</p>
                        <h2 class="mt-1 break-all font-mono text-lg font-semibold text-brand-ink">{{ $rotating_secret_key ?: __('Secret') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('The old value is replaced everywhere this key is linked. Sites pick it up on the next deploy.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="rotate_value" :value="__('New value')" />
                        <x-text-input id="rotate_value" type="password" wire:model="rotate_value" class="mt-2 block w-full font-mono" autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('rotate_value')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelRotateVaultSecret">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="rotateVaultSecret">
                        <span wire:loading.remove wire:target="rotateVaultSecret" class="inline-flex items-center gap-2">
                            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Save new value') }}
                        </span>
                        <span wire:loading wire:target="rotateVaultSecret" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Rotating…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>

        <x-modal
            name="adopt-recipient-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="adoptRecipient">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Encryption key') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Adopt a recipient you already hold') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('dply encrypts new escrowed secrets to this public key and cannot decrypt them. The current key is discarded; escrowed secrets stay locked to it unless you re-move them. Vault secrets are unaffected.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="recipient_input" :value="__('age recipient (public key)')" />
                        <x-text-input id="recipient_input" wire:model="recipient_input" class="mt-2 block w-full font-mono text-sm" placeholder="age1…" autocomplete="off" />
                        <x-input-error :messages="$errors->get('recipient_input')" class="mt-2" />
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Paste only the public half. Keep the private identity yourself — dply never asks for it and could not use it if you sent it.') }}</p>
                    </div>

                    {{-- "Where do I get one?" was the whole question this dialog left
                         unanswered: it accepts a key it cannot generate for you,
                         because generating it here would mean dply seeing the private
                         half. Both real answers, stated. --}}
                    <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-3">
                        <p class="text-xs font-semibold text-brand-ink">{{ __("Don't have a recipient yet?") }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Generate a keypair on your own machine — the private half never leaves it:') }}</p>
                        <div class="mt-2 flex items-center gap-2" x-data="{ copied: false, async copyCmd() { try { await navigator.clipboard.writeText('age-keygen -o dply-org-key.txt'); this.copied = true; setTimeout(() => this.copied = false, 1400); } catch (e) {} } }">
                            <code class="min-w-0 flex-1 truncate rounded-md border border-brand-ink/10 bg-white px-2 py-1 font-mono text-xs text-brand-ink">age-keygen -o dply-org-key.txt</code>
                            <button type="button" x-on:click="copyCmd()" class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-2xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-clipboard-document class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('The file prints a "Public key: age1…" line — that is what goes above. Or close this and use Take custody of the key: dply generates the pair, shows you the private identity once, and keeps no copy.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeAdoptRecipientModal">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="adoptRecipient">
                        <span wire:loading.remove wire:target="adoptRecipient" class="inline-flex items-center gap-2">
                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Adopt recipient') }}
                        </span>
                        <span wire:loading wire:target="adoptRecipient" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Checking…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>

    @endcan

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
