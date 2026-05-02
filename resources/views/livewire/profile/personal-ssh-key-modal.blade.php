<div>
<x-modal
    :name="$modalName"
    :show="false"
    maxWidth="2xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel"
    focusable
>
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Personal profile access') }}</p>
        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a personal SSH key') }}</h2>
        <p class="mt-2 text-sm leading-6 text-brand-moss">
            @if ($source === 'servers.create')
                {{ __('Save one of your own public keys here so Dply can place your access during setup. Your answers on this page stay as they are.') }}
            @else
                {{ __('Save a public key on your profile so you can provision it onto new servers and deploy it to existing ones later.') }}
            @endif
        </p>
    </div>

    <div class="space-y-5 px-6 py-6">
        <div>
            <x-input-label for="personal_ssh_key_name" :value="__('Name')" />
            <x-text-input
                id="personal_ssh_key_name"
                wire:model="name"
                type="text"
                class="mt-1 block w-full"
                placeholder="{{ __('e.g. Work laptop') }}"
                autocomplete="off"
            />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <x-input-label for="personal_ssh_key_public_key" :value="__('Public key')" class="!mb-0" />
                <button
                    type="button"
                    wire:click="generateKeyPair"
                    wire:loading.attr="disabled"
                    wire:target="generateKeyPair"
                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="generateKeyPair">{{ __('Generate key pair') }}</span>
                    <span wire:loading wire:target="generateKeyPair" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Generating…') }}
                    </span>
                </button>
            </div>
            <textarea
                id="personal_ssh_key_public_key"
                wire:model="public_key"
                rows="6"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                placeholder="ssh-ed25519 AAAA..."
            ></textarea>
            <x-input-error :messages="$errors->get('public_key')" class="mt-2" />
        </div>

        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" wire:model.boolean="provision_on_new_servers" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" />
            <span class="text-sm leading-relaxed text-brand-moss">
                {{ __('Always provision to new servers') }}
                <span class="text-brand-mist">{{ __('so this key is added during setup by default') }}</span>
            </span>
        </label>

        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-3 text-sm leading-6 text-brand-moss">
            {{ __('Paste an OpenSSH public key only, or use “Generate key pair.” Private keys are never stored in Dply—you must copy them from the dialog into your SSH agent or a local file.') }}
            <span class="block mt-2">{{ __('Alternatively, run `ssh-keygen -t ed25519 -C "you@example.com"` locally and paste the `.pub` file contents.') }}</span>
        </div>
    </div>

    <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
        <x-secondary-button type="button" wire:click="closeModal">
            {{ __('Cancel') }}
        </x-secondary-button>
        <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('Save SSH key') }}</span>
            <span wire:loading wire:target="save" class="inline-flex items-center justify-center gap-2">
                <x-spinner variant="cream" />
                {{ __('Saving…') }}
            </span>
        </x-primary-button>
    </div>
</x-modal>

@include('livewire.partials.ssh-keypair-reveal-modal', [
    'listenEvent' => 'dply-ssh-profile-keypair-generated',
    'revealContext' => 'profile',
])
</div>
