<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">
                {{ $this->isManageMode ? __('Two-factor authentication') : __('Set up two-factor authentication') }}
            </h2>
        </div>
    </header>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <x-livewire-validation-errors />
                @if ($this->isManageMode)
                    <p class="text-sm text-gray-600 mb-6">
                        {{ __('Two-factor authentication is enabled. Enter your password and a code from your authenticator app (or a recovery code) to disable it.') }}
                    </p>
                    <form wire:submit="disable" class="space-y-6">
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
                            <a href="{{ route('profile.edit') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                @elseif ($this->needsStart)
                    <p class="text-sm text-gray-600 mb-6">
                        {{ __('Enable two-factor authentication to add an extra layer of security to your account.') }}
                    </p>
                    <button type="button" wire:click="store" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        {{ __('Enable two-factor authentication') }}
                    </button>
                    <div class="mt-4">
                        <a href="{{ route('profile.edit') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Cancel') }}</a>
                    </div>
                @else
                    <p class="text-sm text-gray-600 mb-6">
                        {{ __('Scan the QR code below with your authenticator app (e.g. Google Authenticator, Authy). Then enter the 6-digit code to confirm.') }}
                    </p>
                    @if ($this->qrSvg)
                        <div class="flex justify-center mb-6">
                            <div class="inline-block p-4 bg-white border border-gray-200 rounded-lg">
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
                            <a href="{{ route('profile.edit') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
