{{-- OAuth / Socialite credential fields. Expects $oauthProvider in scope. Reuse
     a saved OauthCredential or enter a new client id/secret with an optional
     "save for reuse". The redirect URL is auto-derived; leave the override
     blank to use it. --}}
@php
    $oauthCreds = $this->oauthCredentialsFor($oauthProvider);
    $oauthUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($oauthCreds !== [])
    <div>
        <x-input-label for="binding_oauth_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_oauth_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter client keys…') }}</option>
                @foreach ($oauthCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($oauthUsingSaved)
                <button type="button" wire:click="deleteOauthCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved keys') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($oauthUsingSaved)
    <div>
        <x-input-label for="binding_oauth_client_id" :value="__('Client ID')" />
        <x-text-input id="binding_oauth_client_id" wire:model="bindingForm.client_id" class="mt-1 block w-full font-mono text-sm" />
    </div>
    <div>
        <x-input-label for="binding_oauth_client_secret" :value="__('Client secret')" />
        <x-text-input id="binding_oauth_client_secret" type="password" wire:model="bindingForm.client_secret" class="mt-1 block w-full font-mono text-sm" />
    </div>
    <div class="space-y-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-brand-ink">
            <input type="checkbox" wire:model.live="bindingForm.save_credential" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Save these client keys for reuse across the team') }}
        </label>
        @if ($bindingForm['save_credential'] ?? false)
            <x-text-input wire:model="bindingForm.credential_name" class="block w-full text-sm" :placeholder="__('Name (optional)')" />
        @endif
    </div>
@endunless
<div>
    <x-input-label for="binding_oauth_redirect" :value="__('Redirect URL override (optional)')" />
    <x-text-input id="binding_oauth_redirect" wire:model="bindingForm.redirect" class="mt-1 block w-full font-mono text-sm" :placeholder="__('Leave blank to use the auto-filled URL above')" />
</div>
