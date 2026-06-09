{{-- Log drain credential fields, shared by the logging attach modal.
     Expects $logProvider in scope. Lets the operator reuse a saved
     LogDrainCredential for the team, or enter new credentials with an optional
     "save for reuse". When a saved credential is selected the manager loads its
     credentials, so the provider-specific inputs are hidden. --}}
@php
    $logCreds = $this->logDrainCredentialsFor($logProvider);
    $logUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($logCreds !== [])
    <div>
        <x-input-label for="binding_log_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_log_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter credentials…') }}</option>
                @foreach ($logCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($logUsingSaved)
                <button type="button" wire:click="deleteLogDrainCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved credentials') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($logUsingSaved)
    @if ($logProvider === 'papertrail')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_log_host" :value="__('Host')" />
                <x-text-input id="binding_log_host" wire:model="bindingForm.host" class="mt-1 block w-full font-mono text-sm" placeholder="logs.papertrailapp.com" />
            </div>
            <div>
                <x-input-label for="binding_log_port" :value="__('Port')" />
                <x-text-input id="binding_log_port" wire:model="bindingForm.port" class="mt-1 block w-full font-mono text-sm" placeholder="12345" />
            </div>
        </div>
    @elseif ($logProvider === 'logtail')
        <div>
            <x-input-label for="binding_log_token" :value="__('Source token')" />
            <x-text-input id="binding_log_token" type="password" wire:model="bindingForm.source_token" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
            {{ __('Logtail requires the') }} <code class="font-mono font-semibold">better-stack/laravel-logs</code> {{ __('package. Add it to your') }} <code class="font-mono font-semibold">composer.json</code> {{ __('before deploying.') }}
        </div>
    @elseif ($logProvider === 'syslog')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_log_host" :value="__('Host')" />
                <x-text-input id="binding_log_host" wire:model="bindingForm.host" class="mt-1 block w-full font-mono text-sm" placeholder="syslog.example.com" />
            </div>
            <div>
                <x-input-label for="binding_log_port" :value="__('Port (optional)')" />
                <x-text-input id="binding_log_port" wire:model="bindingForm.port" class="mt-1 block w-full font-mono text-sm" placeholder="514" />
            </div>
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
