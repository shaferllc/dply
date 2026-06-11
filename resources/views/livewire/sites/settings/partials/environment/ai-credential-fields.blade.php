{{-- AI/LLM credential fields, shared by the ai configure modal. Expects
     $aiProvider in scope. Reuse a saved AiCredential or enter a new key with an
     optional "save for reuse". --}}
@php
    $aiCreds = $this->aiCredentialsFor($aiProvider);
    $aiUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($aiCreds !== [])
    <div>
        <x-input-label for="binding_ai_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_ai_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter a key…') }}</option>
                @foreach ($aiCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($aiUsingSaved)
                <button type="button" wire:click="deleteAiCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove this saved key') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($aiUsingSaved)
    <div>
        <x-input-label for="binding_ai_key" :value="__('API key')" />
        <x-text-input id="binding_ai_key" type="password" wire:model="bindingForm.api_key" class="mt-1 block w-full font-mono text-sm" />
    </div>
    @if ($aiProvider === 'openai')
        <div>
            <x-input-label for="binding_ai_org" :value="__('Organization (optional)')" />
            <x-text-input id="binding_ai_org" wire:model="bindingForm.organization" class="mt-1 block w-full font-mono text-sm" placeholder="org-…" />
        </div>
    @endif
    <div class="space-y-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-brand-ink">
            <input type="checkbox" wire:model.live="bindingForm.save_credential" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Save this key for reuse across the team') }}
        </label>
        @if ($bindingForm['save_credential'] ?? false)
            <x-text-input wire:model="bindingForm.credential_name" class="block w-full text-sm" :placeholder="__('Name (optional)')" />
        @endif
    </div>
@endunless
