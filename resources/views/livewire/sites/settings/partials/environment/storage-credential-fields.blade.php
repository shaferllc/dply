{{-- Object-storage credential fields, shared by the storage attach + provision
     modal branches. Expects $osProvider in scope. Lets the operator reuse a
     saved ObjectStorageCredential for the team, or enter new S3 keys with an
     optional "save for reuse". When a saved credential is selected the manager
     loads its keys, so the key/secret inputs are hidden. --}}
@php
    $osCreds = $this->storageCredentialsFor($osProvider);
    $osUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($osCreds !== [])
    <div class="sm:col-span-2">
        <x-input-label for="binding_storage_credential" :value="__('Storage keys')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_storage_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter new keys…') }}</option>
                @foreach ($osCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($osUsingSaved)
                <button type="button" wire:click="deleteStorageCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved keys') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($osUsingSaved)
    <div>
        <x-input-label for="binding_storage_key" :value="__('Access key ID')" />
        <x-text-input id="binding_storage_key" wire:model="bindingForm.access_key_id" class="mt-1 block w-full font-mono text-sm" />
    </div>
    <div>
        <x-input-label for="binding_storage_secret" :value="__('Secret access key')" />
        <x-text-input id="binding_storage_secret" type="password" wire:model="bindingForm.secret_access_key" class="mt-1 block w-full font-mono text-sm" />
    </div>
    <div class="space-y-2 sm:col-span-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-brand-ink">
            <input type="checkbox" wire:model.live="bindingForm.save_credential" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Save these keys for reuse across the team') }}
        </label>
        @if ($bindingForm['save_credential'] ?? false)
            <x-text-input wire:model="bindingForm.credential_name" class="block w-full text-sm" :placeholder="__('Name (optional) — e.g. Production Spaces')" />
        @endif
    </div>
@endunless
