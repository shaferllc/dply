{{-- SMS / push credential fields, shared by the sms configure modal. Expects
     $smsProvider in scope. Reuse a saved SmsCredential or enter new connection
     details with an optional "save for reuse". --}}
@php
    $smsCreds = $this->smsCredentialsFor($smsProvider);
    $smsUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($smsCreds !== [])
    <div>
        <x-input-label for="binding_sms_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_sms_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter credentials…') }}</option>
                @foreach ($smsCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($smsUsingSaved)
                <button type="button" wire:click="deleteSmsCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved credentials') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($smsUsingSaved)
    @if ($smsProvider === 'twilio')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_sms_sid" :value="__('Account SID')" />
                <x-text-input id="binding_sms_sid" wire:model="bindingForm.sid" class="mt-1 block w-full font-mono text-sm" />
            </div>
            <div>
                <x-input-label for="binding_sms_token" :value="__('Auth token')" />
                <x-text-input id="binding_sms_token" type="password" wire:model="bindingForm.auth_token" class="mt-1 block w-full font-mono text-sm" />
            </div>
        </div>
        <div>
            <x-input-label for="binding_sms_from" :value="__('From number')" />
            <x-text-input id="binding_sms_from" wire:model="bindingForm.from" class="mt-1 block w-full font-mono text-sm" placeholder="+15551234567" />
        </div>
    @elseif ($smsProvider === 'vonage')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_sms_key" :value="__('API key')" />
                <x-text-input id="binding_sms_key" wire:model="bindingForm.key" class="mt-1 block w-full font-mono text-sm" />
            </div>
            <div>
                <x-input-label for="binding_sms_secret" :value="__('API secret')" />
                <x-text-input id="binding_sms_secret" type="password" wire:model="bindingForm.secret" class="mt-1 block w-full font-mono text-sm" />
            </div>
        </div>
        <div>
            <x-input-label for="binding_sms_vonage_from" :value="__('From (number or sender id)')" />
            <x-text-input id="binding_sms_vonage_from" wire:model="bindingForm.from" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @elseif ($smsProvider === 'fcm')
        <div>
            <x-input-label for="binding_sms_server_key" :value="__('Server key')" />
            <x-text-input id="binding_sms_server_key" type="password" wire:model="bindingForm.server_key" class="mt-1 block w-full font-mono text-sm" />
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
