{{-- One leg of a failover / round-robin mail chain. Expects $legIndex (int).
     Binds to bindingForm.legs.{i}.*. Saved-credential reuse isn't offered per
     leg (v1) — each leg's secret is entered inline. --}}
@php
    $legProvider = (string) ($bindingForm['legs'][$legIndex]['provider'] ?? 'smtp');
    $legPackage = \App\Services\Deploy\SiteBindingManager::MAIL_TRANSPORT_PACKAGES[$legProvider] ?? null;
    $legCanRemove = count($bindingForm['legs'] ?? []) > 2;
@endphp
<div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand-forest/10 text-[11px] font-bold text-brand-forest">{{ $legIndex + 1 }}</span>
            <select wire:model.live="bindingForm.legs.{{ $legIndex }}.provider" class="dply-input !w-auto py-1.5 text-sm">
                <option value="smtp">{{ __('SMTP') }}</option>
                <option value="mailgun">{{ __('Mailgun') }}</option>
                <option value="postmark">{{ __('Postmark') }}</option>
                <option value="ses">{{ __('Amazon SES') }}</option>
                <option value="resend">{{ __('Resend') }}</option>
                <option value="log">{{ __('Log (no delivery)') }}</option>
            </select>
        </div>
        @if ($legCanRemove)
            <button type="button" wire:click="removeMailLeg({{ $legIndex }})" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50">
                <x-heroicon-o-x-mark class="h-3.5 w-3.5" /> {{ __('Remove') }}
            </button>
        @endif
    </div>

    <div class="mt-3 space-y-3">
        @if ($legProvider === 'smtp')
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="leg_{{ $legIndex }}_host" :value="__('SMTP host')" />
                    <x-text-input id="leg_{{ $legIndex }}_host" wire:model="bindingForm.legs.{{ $legIndex }}.host" class="mt-1 block w-full font-mono text-sm" placeholder="smtp.example.com" />
                </div>
                <div>
                    <x-input-label for="leg_{{ $legIndex }}_port" :value="__('Port')" />
                    <x-text-input id="leg_{{ $legIndex }}_port" wire:model="bindingForm.legs.{{ $legIndex }}.port" class="mt-1 block w-full font-mono text-sm" placeholder="587" />
                </div>
                <div>
                    <x-input-label for="leg_{{ $legIndex }}_enc" :value="__('Encryption')" />
                    <select id="leg_{{ $legIndex }}_enc" wire:model="bindingForm.legs.{{ $legIndex }}.encryption" class="dply-input">
                        <option value="tls">{{ __('TLS / STARTTLS') }}</option>
                        <option value="ssl">{{ __('SSL (implicit)') }}</option>
                        <option value="">{{ __('None') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="leg_{{ $legIndex }}_user" :value="__('Username')" />
                    <x-text-input id="leg_{{ $legIndex }}_user" wire:model="bindingForm.legs.{{ $legIndex }}.username" class="mt-1 block w-full font-mono text-sm" />
                </div>
                <div>
                    <x-input-label for="leg_{{ $legIndex }}_pass" :value="__('Password')" />
                    <x-text-input id="leg_{{ $legIndex }}_pass" type="password" wire:model="bindingForm.legs.{{ $legIndex }}.password" class="mt-1 block w-full font-mono text-sm" />
                </div>
            </div>
        @elseif ($legProvider === 'mailgun')
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="leg_{{ $legIndex }}_domain" :value="__('Mailgun domain')" />
                    <x-text-input id="leg_{{ $legIndex }}_domain" wire:model="bindingForm.legs.{{ $legIndex }}.domain" class="mt-1 block w-full font-mono text-sm" placeholder="mg.example.com" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="leg_{{ $legIndex }}_secret" :value="__('Mailgun secret')" />
                    <x-text-input id="leg_{{ $legIndex }}_secret" type="password" wire:model="bindingForm.legs.{{ $legIndex }}.secret" class="mt-1 block w-full font-mono text-sm" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="leg_{{ $legIndex }}_endpoint" :value="__('API endpoint')" />
                    <x-text-input id="leg_{{ $legIndex }}_endpoint" wire:model="bindingForm.legs.{{ $legIndex }}.endpoint" class="mt-1 block w-full font-mono text-sm" placeholder="api.mailgun.net" />
                </div>
            </div>
        @elseif ($legProvider === 'postmark')
            <div>
                <x-input-label for="leg_{{ $legIndex }}_token" :value="__('Server token')" />
                <x-text-input id="leg_{{ $legIndex }}_token" type="password" wire:model="bindingForm.legs.{{ $legIndex }}.token" class="mt-1 block w-full font-mono text-sm" />
            </div>
        @elseif ($legProvider === 'ses')
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="leg_{{ $legIndex }}_ak" :value="__('Access key ID')" />
                    <x-text-input id="leg_{{ $legIndex }}_ak" wire:model="bindingForm.legs.{{ $legIndex }}.access_key_id" class="mt-1 block w-full font-mono text-sm" />
                </div>
                <div>
                    <x-input-label for="leg_{{ $legIndex }}_region" :value="__('Region')" />
                    <x-text-input id="leg_{{ $legIndex }}_region" wire:model="bindingForm.legs.{{ $legIndex }}.region" class="mt-1 block w-full font-mono text-sm" placeholder="us-east-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="leg_{{ $legIndex }}_sk" :value="__('Secret access key')" />
                    <x-text-input id="leg_{{ $legIndex }}_sk" type="password" wire:model="bindingForm.legs.{{ $legIndex }}.secret_access_key" class="mt-1 block w-full font-mono text-sm" />
                </div>
            </div>
        @elseif ($legProvider === 'resend')
            <div>
                <x-input-label for="leg_{{ $legIndex }}_key" :value="__('Resend API key')" />
                <x-text-input id="leg_{{ $legIndex }}_key" type="password" wire:model="bindingForm.legs.{{ $legIndex }}.key" class="mt-1 block w-full font-mono text-sm" placeholder="re_…" />
            </div>
        @else
            <p class="text-xs text-brand-moss">{{ __('Writes to the application log — useful as a last-resort leg.') }}</p>
        @endif

        @if ($legPackage)
            <p class="text-[11px] text-brand-moss">{{ __('Requires') }} <code class="font-mono font-semibold text-brand-ink">{{ $legPackage }}</code> {{ __('in composer.json.') }}</p>
        @endif
    </div>
</div>
