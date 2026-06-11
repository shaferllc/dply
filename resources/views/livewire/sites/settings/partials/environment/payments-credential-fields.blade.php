{{-- Payments (Cashier) credential fields. Expects $paymentsProvider in scope.
     Reuse a saved PaymentCredential or enter new keys with an optional "save for
     reuse". --}}
@php
    $payCreds = $this->paymentCredentialsFor($paymentsProvider);
    $payUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($payCreds !== [])
    <div>
        <x-input-label for="binding_pay_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_pay_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter keys…') }}</option>
                @foreach ($payCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($payUsingSaved)
                <button type="button" wire:click="deletePaymentCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove these saved keys') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($payUsingSaved)
    @if ($paymentsProvider === 'stripe')
        <div>
            <x-input-label for="binding_pay_key" :value="__('Publishable key')" />
            <x-text-input id="binding_pay_key" wire:model="bindingForm.key" class="mt-1 block w-full font-mono text-sm" placeholder="pk_live_…" />
        </div>
        <div>
            <x-input-label for="binding_pay_secret" :value="__('Secret key')" />
            <x-text-input id="binding_pay_secret" type="password" wire:model="bindingForm.secret" class="mt-1 block w-full font-mono text-sm" placeholder="sk_live_…" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_pay_whsec" :value="__('Webhook secret (optional)')" />
                <x-text-input id="binding_pay_whsec" type="password" wire:model="bindingForm.webhook_secret" class="mt-1 block w-full font-mono text-sm" placeholder="whsec_…" />
            </div>
            <div>
                <x-input-label for="binding_pay_currency" :value="__('Currency (optional)')" />
                <x-text-input id="binding_pay_currency" wire:model="bindingForm.currency" class="mt-1 block w-full font-mono text-sm" placeholder="usd" />
            </div>
        </div>
    @elseif ($paymentsProvider === 'paddle')
        <div>
            <x-input-label for="binding_pay_apikey" :value="__('API key')" />
            <x-text-input id="binding_pay_apikey" type="password" wire:model="bindingForm.api_key" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div>
            <x-input-label for="binding_pay_cst" :value="__('Client-side token')" />
            <x-text-input id="binding_pay_cst" wire:model="bindingForm.client_side_token" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="binding_pay_whsec" :value="__('Webhook secret (optional)')" />
                <x-text-input id="binding_pay_whsec" type="password" wire:model="bindingForm.webhook_secret" class="mt-1 block w-full font-mono text-sm" />
            </div>
            <div>
                <x-input-label for="binding_pay_sandbox" :value="__('Sandbox')" />
                <select id="binding_pay_sandbox" wire:model="bindingForm.sandbox" class="dply-input">
                    <option value="">{{ __('Production') }}</option>
                    <option value="true">{{ __('Sandbox') }}</option>
                </select>
            </div>
        </div>
    @endif
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
