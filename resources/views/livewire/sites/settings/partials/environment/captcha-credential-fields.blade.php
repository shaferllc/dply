{{-- CAPTCHA credential fields, shared by the captcha configure modal. Expects
     $captchaProvider in scope. Reuse a saved CaptchaCredential or enter a new
     site key + secret with an optional "save for reuse". --}}
@php
    $captchaCreds = $this->captchaCredentialsFor($captchaProvider);
    $captchaUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($captchaCreds !== [])
    <div>
        <x-input-label for="binding_captcha_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_captcha_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter keys…') }}</option>
                @foreach ($captchaCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($captchaUsingSaved)
                <button type="button" wire:click="deleteCaptchaCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved keys') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($captchaUsingSaved)
    <div>
        <x-input-label for="binding_captcha_site" :value="__('Site key (public)')" />
        <x-text-input id="binding_captcha_site" wire:model="bindingForm.site_key" class="mt-1 block w-full font-mono text-sm" />
    </div>
    <div>
        <x-input-label for="binding_captcha_secret" :value="__('Secret key')" />
        <x-text-input id="binding_captcha_secret" type="password" wire:model="bindingForm.secret_key" class="mt-1 block w-full font-mono text-sm" />
    </div>
    <div class="space-y-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-brand-ink">
            <input type="checkbox" wire:model.live="bindingForm.save_credential" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Save these keys for reuse across the team') }}
        </label>
        @if ($bindingForm['save_credential'] ?? false)
            <x-text-input wire:model="bindingForm.credential_name" class="block w-full text-sm" :placeholder="__('Name (optional)')" />
        @endif
    </div>
@endunless
