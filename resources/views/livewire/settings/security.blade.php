<div
    x-data="{
        passwordSaved: false,
        init() {
            $wire.on('password-updated', () => {
                this.passwordSaved = true;
                setTimeout(() => { this.passwordSaved = false }, 2000);
            });
        },
    }"
>
    <x-livewire-validation-errors />

    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.edit') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Profile') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Security') }}</li>
        </ol>
    </nav>

    <div class="space-y-8">
        {{-- Password --}}
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <form wire:submit="updatePassword" class="block" autocomplete="on">
                {{-- Browsers expect a username field on password-change forms (a11y + password managers). --}}
                <div class="sr-only">
                    <label for="security_autocomplete_username">{{ __('Account email') }}</label>
                    <input
                        id="security_autocomplete_username"
                        type="email"
                        name="username"
                        autocomplete="username"
                        value="{{ auth()->user()->email }}"
                        readonly
                        tabindex="-1"
                    />
                </div>
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Password') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Keep your password strong. Use a long, random password. Saving only applies to the fields below.') }}
                        </p>
                    </div>
                    <div class="lg:col-span-8">
                        <div class="space-y-5">
                            <div>
                                <x-input-label for="security_current_password" :value="__('Current Password')" />
                                <x-text-input id="security_current_password" wire:model="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
                                <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="security_password" :value="__('New Password')" />
                                <x-text-input id="security_password" wire:model="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="security_password_confirmation" :value="__('Confirm Password')" />
                                <x-text-input id="security_password_confirmation" wire:model="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 sm:px-8 py-4 flex justify-end items-center gap-4">
                    <p x-show="passwordSaved" x-transition class="text-sm text-brand-moss">{{ __('Saved.') }}</p>
                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="updatePassword">{{ __('Save password') }}</span>
                        <span wire:loading wire:target="updatePassword" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Saving…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </div>

        {{-- Two-factor authentication --}}
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Two-factor authentication') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Require a code from your authenticator app when signing in. Add 2FA so a stolen password alone cannot access your account. Setup and recovery codes are on the next screen.') }}
                    </p>
                </div>
                <div class="lg:col-span-8">
                    <div class="space-y-4">
                        @if (session('status') === 'two-factor-enabled' && session('recovery_codes'))
                            <div class="p-4 rounded-xl bg-amber-50 border border-amber-200">
                                <p class="text-sm font-medium text-amber-900">{{ __('Store these recovery codes in a secure place. Each code can only be used once.') }}</p>
                                <div class="mt-3 font-mono text-sm text-amber-950 break-all grid grid-cols-2 gap-2">
                                    @foreach (session('recovery_codes') as $code)
                                        <span>{{ $code }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($this->user()->hasTwoFactorEnabled())
                            <div class="flex flex-wrap items-center gap-4">
                                <span class="text-sm text-green-800 font-medium">{{ __('Two-factor authentication is enabled.') }}</span>
                                <a href="{{ route('two-factor.setup') }}" class="text-sm text-brand-moss hover:text-brand-ink underline">{{ __('Manage or disable') }}</a>
                            </div>
                        @else
                            <div>
                                <a href="{{ route('two-factor.setup') }}" class="inline-flex items-center px-5 py-2.5 bg-brand-ink border border-transparent rounded-xl font-semibold text-sm text-brand-cream shadow-md hover:bg-brand-forest transition-colors">
                                    {{ __('Set up two-factor authentication') }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
