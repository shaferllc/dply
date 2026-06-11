{{-- Error-tracking credential fields, shared by the error_tracking configure
     modal. Expects $etProvider in scope. Lets the operator reuse a saved
     ErrorTrackingCredential for the team, or enter a new DSN/key with an
     optional "save for reuse". When a saved credential is selected the manager
     loads its secret, so the provider-specific inputs are hidden. --}}
@php
    $etCreds = $this->errorTrackingCredentialsFor($etProvider);
    $etUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($etCreds !== [])
    <div>
        <x-input-label for="binding_et_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_et_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter credentials…') }}</option>
                @foreach ($etCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($etUsingSaved)
                <button type="button" wire:click="deleteErrorTrackingCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved credentials') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($etUsingSaved)
    @if ($etProvider === 'sentry')
        <div>
            <x-input-label for="binding_et_dsn" :value="__('DSN')" />
            <x-text-input id="binding_et_dsn" wire:model="bindingForm.dsn" class="mt-1 block w-full font-mono text-sm" placeholder="https://examplePublicKey@o0.ingest.sentry.io/0" />
        </div>
        <div>
            <x-input-label for="binding_et_traces" :value="__('Traces sample rate (optional)')" />
            <x-text-input id="binding_et_traces" wire:model="bindingForm.traces_sample_rate" class="mt-1 block w-full font-mono text-sm" placeholder="0.1" />
        </div>
    @elseif ($etProvider === 'bugsnag')
        <div>
            <x-input-label for="binding_et_api_key" :value="__('API key')" />
            <x-text-input id="binding_et_api_key" type="password" wire:model="bindingForm.api_key" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @elseif ($etProvider === 'flare')
        <div>
            <x-input-label for="binding_et_key" :value="__('Project key')" />
            <x-text-input id="binding_et_key" type="password" wire:model="bindingForm.key" class="mt-1 block w-full font-mono text-sm" />
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
