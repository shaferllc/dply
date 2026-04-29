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
                {{ __('Save one of your own public keys here so Dply can keep your access ready while you connect a BYO server.') }}
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
            <x-input-label for="personal_ssh_key_public_key" :value="__('Public key')" />
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
            {{ __('Paste an OpenSSH public key only. Private keys are never stored here.') }}
            <span class="block mt-2">{{ __('If you need to generate one, run `ssh-keygen -t ed25519 -C "you@example.com"` and paste the contents of the `.pub` file.') }}</span>
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
