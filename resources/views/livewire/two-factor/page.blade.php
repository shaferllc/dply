@php
    $shellTitle = $this->isManageMode ? __('Two-factor authentication') : __('Set up two-factor authentication');
    $shellDescription = $this->isManageMode
        ? __('Two-factor authentication is enabled. Enter your password and a code from your authenticator app (or a recovery code) to disable it.')
        : ($this->needsStart
            ? __('Enable two-factor authentication to add an extra layer of security to your account.')
            : __('Scan the QR code below with your authenticator app (e.g. Google Authenticator, Authy). Then enter the 6-digit code to confirm.'));
@endphp

<div>
    @push('breadcrumbs')
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Security'), 'href' => route('profile.security'), 'icon' => 'shield-check'],
            ['label' => __('Two-factor authentication'), 'icon' => 'lock-closed'],
        ]" />
    @endpush

    <x-profile-shell
        :title="$shellTitle"
        :description="$shellDescription"
        icon="heroicon-o-lock-closed"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('profile.security') }}" wire:navigate>
                <x-heroicon-o-shield-check class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Back to security') }}
            </x-outline-link>
        </x-slot:actions>

        <div class="px-5 py-5 sm:px-6">
            <x-livewire-validation-errors />

            @if ($this->isManageMode)
                <form wire:submit="disable" class="space-y-6" autocomplete="on">
                    <div class="sr-only">
                        <label for="two_factor_disable_username">{{ __('Account email') }}</label>
                        <input
                            id="two_factor_disable_username"
                            type="email"
                            name="username"
                            autocomplete="username"
                            value="{{ auth()->user()->email }}"
                            readonly
                            tabindex="-1"
                        />
                    </div>
                    <div>
                        <x-input-label for="password" :value="__('Password')" />
                        <x-text-input id="password" wire:model="password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="disable_code" :value="__('Code')" />
                        <x-text-input id="disable_code" wire:model="disable_code" type="text" class="mt-1 block w-full font-mono" inputmode="numeric" autocomplete="one-time-code" placeholder="{{ __('6-digit code or recovery code') }}" />
                        <x-input-error :messages="$errors->get('disable_code')" class="mt-2" />
                    </div>
                    <div class="flex items-center gap-4">
                        <x-danger-button type="submit">{{ __('Disable two-factor') }}</x-danger-button>
                        <a href="{{ route('profile.security') }}" wire:navigate class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                    </div>
                </form>
            @elseif ($this->needsStart)
                <button
                    type="button"
                    wire:click="store"
                    class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Enable two-factor authentication') }}
                </button>
                <div class="mt-4">
                    <a href="{{ route('profile.security') }}" wire:navigate class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                </div>
            @else
                @if ($this->qrSvg)
                    <div class="mb-6 flex justify-center">
                        <div class="inline-block rounded-xl border border-brand-ink/10 bg-white p-4">
                            {!! $this->qrSvg !!}
                        </div>
                    </div>
                @endif
                <form wire:submit="confirm" class="space-y-6">
                    <div>
                        <x-input-label for="code" :value="__('Verification code')" />
                        <x-text-input id="code" wire:model="code" type="text" class="mt-1 block w-full font-mono" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" />
                        <x-input-error :messages="$errors->get('code')" class="mt-2" />
                    </div>
                    <div class="flex items-center gap-4">
                        <x-primary-button type="submit">{{ __('Confirm') }}</x-primary-button>
                        <a href="{{ route('profile.security') }}" wire:navigate class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                    </div>
                </form>
            @endif
        </div>
    </x-profile-shell>
</div>
