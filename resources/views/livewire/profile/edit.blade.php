<div>
    <x-livewire-validation-errors />
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Profile') }}</h2>
        </div>
    </header>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Profile Information --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Profile Information') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __("Update your account's profile information and email address.") }}</p>
                        </header>
                        <form wire:submit="updateProfile" class="mt-6 space-y-6">
                            <div>
                                <x-input-label for="name" :value="__('Name')" />
                                <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required autofocus autocomplete="name" />
                                <x-input-error class="mt-2" :messages="$errors->get('name')" />
                            </div>
                            <div>
                                <x-input-label for="email" :value="__('Email')" />
                                <x-text-input id="email" wire:model="email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                                <x-input-error class="mt-2" :messages="$errors->get('email')" />
                                @if ($this->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $this->user()->hasVerifiedEmail())
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-800">{{ __('Your email address is unverified.') }}</p>
                                        <button type="button" wire:click="sendVerificationEmail" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 mt-1">
                                            {{ __('Click here to re-send the verification email.') }}
                                        </button>
                                        @if ($verificationLinkSent)
                                            <p class="mt-2 font-medium text-sm text-green-600">{{ __('A new verification link has been sent to your email address.') }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <div class="flex items-center gap-4">
                                <x-primary-button wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="updateProfile">{{ __('Save') }}</span>
                                    <span wire:loading wire:target="updateProfile">{{ __('Saving…') }}</span>
                                </x-primary-button>
                                <p x-data="{ show: false }" x-show="show" x-transition x-init="$wire.on('profile-updated', () => { show = true; setTimeout(() => show = false, 2000) })" class="text-sm text-gray-600">{{ __('Saved.') }}</p>
                            </div>
                        </form>
                    </section>
                </div>
            </div>

            {{-- Update Password --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Update Password') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('Ensure your account is using a long, random password to stay secure.') }}</p>
                        </header>
                        <form wire:submit="updatePassword" class="mt-6 space-y-6">
                            <div>
                                <x-input-label for="update_password_current_password" :value="__('Current Password')" />
                                <x-text-input id="update_password_current_password" wire:model="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
                                <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="update_password_password" :value="__('New Password')" />
                                <x-text-input id="update_password_password" wire:model="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="update_password_password_confirmation" :value="__('Confirm Password')" />
                                <x-text-input id="update_password_password_confirmation" wire:model="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                            </div>
                            <div class="flex items-center gap-4">
                                <x-primary-button wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="updatePassword">{{ __('Save') }}</span>
                                    <span wire:loading wire:target="updatePassword">{{ __('Saving…') }}</span>
                                </x-primary-button>
                                <p x-data="{ show: false }" x-show="show" x-transition x-init="$wire.on('password-updated', () => { show = true; setTimeout(() => show = false, 2000) })" class="text-sm text-gray-600">{{ __('Saved.') }}</p>
                            </div>
                        </form>
                    </section>
                </div>
            </div>

            {{-- Active Sessions --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section class="space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Active Sessions') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('Manage your active sessions. Revoking a session will log out that device on its next request.') }}</p>
                        </header>
                        <p x-data="{ show: false }" x-show="show" x-transition x-init="$wire.on('session-revoked', () => { show = true; setTimeout(() => show = false, 3000) })" class="text-sm text-green-600">{{ __('Session revoked.') }}</p>
                        <p x-data="{ show: false }" x-show="show" x-transition x-init="$wire.on('sessions-revoked', () => { show = true; setTimeout(() => show = false, 3000) })" class="text-sm text-green-600">{{ __('All other sessions have been revoked.') }}</p>
                        @error('session')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <div class="space-y-4">
                            @forelse ($this->sessions as $session)
                                <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50/50 p-4">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-gray-900">{{ $session['device_label'] }}</span>
                                            @if ($session['is_current'])
                                                <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">{{ __('This device') }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm text-gray-600">
                                            {{ $session['ip_address'] ?? __('Unknown IP') }}
                                            · {{ __('Last active') }} {{ \Carbon\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans() }}
                                        </p>
                                    </div>
                                    @if (!$session['is_current'])
                                        <button type="button" wire:click="revokeSession('{{ $session['id'] }}')" wire:confirm="{{ __('Are you sure you want to revoke this session? That device will be logged out.') }}" class="ml-4 shrink-0 inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white hover:bg-red-700">
                                            {{ __('Revoke') }}
                                        </button>
                                    @else
                                        <span class="ml-4 shrink-0 text-sm text-gray-500">{{ __('Current session') }}</span>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-600">{{ __('No active sessions.') }}</p>
                            @endforelse
                        </div>
                        @if (count(array_filter($this->sessions, fn ($s) => !$s['is_current'])) > 0)
                            <button type="button" wire:click="revokeOtherSessions" wire:confirm="{{ __('Revoke all other sessions? You will stay logged in on this device only.') }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 hover:bg-gray-50">
                                {{ __('Revoke all other sessions') }}
                            </button>
                        @endif
                    </section>
                </div>
            </div>

            {{-- Two-factor authentication --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Two-factor authentication') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('Add an extra layer of security by requiring a code from your authenticator app when signing in.') }}</p>
                        </header>
                        @if (session('status') === 'two-factor-enabled' && session('recovery_codes'))
                            <div class="mt-4 p-4 rounded-lg bg-amber-50 border border-amber-200">
                                <p class="text-sm font-medium text-amber-800">{{ __('Store these recovery codes in a secure place. Each code can only be used once.') }}</p>
                                <div class="mt-3 font-mono text-sm text-amber-900 break-all grid grid-cols-2 gap-2">
                                    @foreach (session('recovery_codes') as $code)
                                        <span>{{ $code }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($this->user()->hasTwoFactorEnabled())
                            <div class="mt-6 flex items-center gap-4">
                                <span class="text-sm text-green-600 font-medium">{{ __('Two-factor authentication is enabled.') }}</span>
                                <a href="{{ route('two-factor.setup') }}" class="text-sm text-gray-600 hover:text-gray-900 underline">{{ __('Disable') }}</a>
                            </div>
                        @else
                            <div class="mt-6">
                                <a href="{{ route('two-factor.setup') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                                    {{ __('Enable two-factor authentication') }}
                                </a>
                            </div>
                        @endif
                    </section>
                </div>
            </div>

            {{-- Delete Account --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section class="space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Delete Account') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}</p>
                        </header>
                        <x-danger-button type="button" wire:click="openDeleteModal">{{ __('Delete Account') }}</x-danger-button>

                        @if ($showDeleteModal)
                            <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
                                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                                    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" wire:click="closeDeleteModal"></div>
                                    <div class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                                        <form wire:submit="deleteAccount" class="p-6">
                                            <h2 class="text-lg font-medium text-gray-900">{{ __('Are you sure you want to delete your account?') }}</h2>
                                            <p class="mt-1 text-sm text-gray-600">{{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}</p>
                                            <div class="mt-6">
                                                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />
                                                <x-text-input id="password" wire:model="delete_password" type="password" class="mt-1 block w-full" placeholder="{{ __('Password') }}" />
                                                <x-input-error :messages="$errors->get('delete_password')" class="mt-2" />
                                            </div>
                                            <div class="mt-6 flex justify-end gap-3">
                                                <x-secondary-button type="button" wire:click="closeDeleteModal">{{ __('Cancel') }}</x-secondary-button>
                                                <x-danger-button type="submit">{{ __('Delete Account') }}</x-danger-button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </section>
                </div>
            </div>
        </div>
    </div>
</div>
