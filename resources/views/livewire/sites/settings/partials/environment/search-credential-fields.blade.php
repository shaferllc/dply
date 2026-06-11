{{-- Search (Scout) credential fields. Expects $searchProvider in scope. Reuse
     a saved SearchCredential or enter new connection details with an optional
     "save for reuse". --}}
@php
    $searchCreds = $this->searchCredentialsFor($searchProvider);
    $searchUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($searchCreds !== [])
    <div>
        <x-input-label for="binding_search_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_search_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter credentials…') }}</option>
                @foreach ($searchCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($searchUsingSaved)
                <button type="button" wire:click="deleteSearchCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved credentials') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($searchUsingSaved)
    @if ($searchProvider === 'algolia')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_search_app" :value="__('Application ID')" />
                <x-text-input id="binding_search_app" wire:model="bindingForm.app_id" class="mt-1 block w-full font-mono text-sm" />
            </div>
            <div>
                <x-input-label for="binding_search_secret" :value="__('Admin API key')" />
                <x-text-input id="binding_search_secret" type="password" wire:model="bindingForm.secret" class="mt-1 block w-full font-mono text-sm" />
            </div>
        </div>
    @elseif ($searchProvider === 'meilisearch')
        <div>
            <x-input-label for="binding_search_host" :value="__('Host')" />
            <x-text-input id="binding_search_host" wire:model="bindingForm.host" class="mt-1 block w-full font-mono text-sm" placeholder="http://127.0.0.1:7700" />
        </div>
        <div>
            <x-input-label for="binding_search_key" :value="__('Master / API key (optional)')" />
            <x-text-input id="binding_search_key" type="password" wire:model="bindingForm.key" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @elseif ($searchProvider === 'typesense')
        <div>
            <x-input-label for="binding_search_key" :value="__('API key')" />
            <x-text-input id="binding_search_key" type="password" wire:model="bindingForm.api_key" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-input-label for="binding_search_host" :value="__('Host')" />
                <x-text-input id="binding_search_host" wire:model="bindingForm.host" class="mt-1 block w-full font-mono text-sm" placeholder="127.0.0.1" />
            </div>
            <div>
                <x-input-label for="binding_search_port" :value="__('Port')" />
                <x-text-input id="binding_search_port" wire:model="bindingForm.port" class="mt-1 block w-full font-mono text-sm" placeholder="8108" />
            </div>
        </div>
        <div>
            <x-input-label for="binding_search_protocol" :value="__('Protocol')" />
            <select id="binding_search_protocol" wire:model="bindingForm.protocol" class="dply-input">
                <option value="http">http</option>
                <option value="https">https</option>
            </select>
        </div>
    @endif
    <div class="space-y-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-brand-ink">
            <input type="checkbox" wire:model.live="bindingForm.save_credential" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Save these credentials for reuse across the team') }}
        </label>
        @if ($bindingForm['save_credential'] ?? false)
            <x-text-input wire:model="bindingForm.credential_name" class="block w-full text-sm" :placeholder="__('Name (optional)')" />
        @endif
    </div>
@endunless
