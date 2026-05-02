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

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('profile.edit'), 'icon' => 'user-circle'],
        ['label' => __('Security'), 'icon' => 'shield-check'],
    ]" />

    <div class="space-y-8">
        {{-- Password --}}
        <div class="dply-card overflow-hidden">
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
                <p x-show="passwordSaved" x-transition class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 sm:px-8 py-3 text-sm text-emerald-700" x-cloak>{{ __('Saved.') }}</p>
            </form>
        </div>

        <x-unsaved-changes-bar
            :message="__('You have unsaved changes to your password.')"
            saveAction="updatePassword"
            discardAction="discardPasswordUnsaved"
            targets="current_password,password,password_confirmation"
            :saveLabel="__('Save password')"
        />

        {{-- Passkeys --}}
        <div class="dply-card overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Passkeys') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Sign in without a password using your device PIN, fingerprint, or security key. You can register multiple passkeys.') }}
                    </p>
                </div>
                <div class="lg:col-span-8 space-y-4">
                    @error('passkey')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="mb-4">
                        <x-input-label for="dply-passkey-alias" :value="__('Passkey name')" />
                        <x-text-input
                            id="dply-passkey-alias"
                            type="text"
                            class="mt-1 block w-full max-w-md"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="{{ __('e.g. Work laptop') }}"
                        />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Optional. Helps you recognize this passkey in the list below.') }}</p>
                    </div>
                    <fieldset class="space-y-3 mb-4">
                        <legend class="text-sm font-medium text-brand-ink">{{ __('Passkey type') }}</legend>
                        <p class="text-xs text-brand-moss leading-relaxed">{{ __('Choose where to save this passkey. Pick “This device” for Touch ID, Face ID, or Windows Hello.') }}</p>
                        <div class="space-y-2">
                            <label class="flex items-start gap-3 cursor-pointer rounded-lg border border-brand-mist/80 bg-white px-4 py-3 text-sm has-[:checked]:border-brand-ink/30 has-[:checked]:bg-brand-sand/20">
                                <input
                                    type="radio"
                                    name="dply-passkey-attachment"
                                    value="platform"
                                    class="mt-0.5 rounded-full border-brand-mist text-brand-forest focus:ring-brand-forest"
                                    checked
                                />
                                <span>
                                    <span class="font-medium text-brand-ink">{{ __('This device') }}</span>
                                    <span class="block text-xs text-brand-moss mt-0.5">{{ __('Operating system passkey (recommended for signing in on this computer)') }}</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer rounded-lg border border-brand-mist/80 bg-white px-4 py-3 text-sm has-[:checked]:border-brand-ink/30 has-[:checked]:bg-brand-sand/20">
                                <input
                                    type="radio"
                                    name="dply-passkey-attachment"
                                    value="cross-platform"
                                    class="mt-0.5 rounded-full border-brand-mist text-brand-forest focus:ring-brand-forest"
                                />
                                <span>
                                    <span class="font-medium text-brand-ink">{{ __('Security key or password manager') }}</span>
                                    <span class="block text-xs text-brand-moss mt-0.5">{{ __('YubiKey or an app such as 1Password / Bitwarden') }}</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 cursor-pointer rounded-lg border border-brand-mist/80 bg-white px-4 py-3 text-sm has-[:checked]:border-brand-ink/30 has-[:checked]:bg-brand-sand/20">
                                <input
                                    type="radio"
                                    name="dply-passkey-attachment"
                                    value=""
                                    class="mt-0.5 rounded-full border-brand-mist text-brand-forest focus:ring-brand-forest"
                                />
                                <span>
                                    <span class="font-medium text-brand-ink">{{ __('Let my browser decide') }}</span>
                                    <span class="block text-xs text-brand-moss mt-0.5">{{ __('No preference—the browser may offer several options.') }}</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>
                    <div>
                        <button
                            type="button"
                            id="dply-passkey-register-btn"
                            data-options-url="{{ route('webauthn.register.options', absolute: false) }}"
                            data-register-url="{{ route('webauthn.register', absolute: false) }}"
                            class="inline-flex items-center px-5 py-2.5 bg-brand-ink border border-transparent rounded-xl font-semibold text-sm text-brand-cream shadow-md hover:bg-brand-forest transition-colors disabled:opacity-60"
                        >
                            {{ __('Add a passkey') }}
                        </button>
                        <p id="dply-passkey-register-error" class="mt-2 hidden text-sm text-red-700" role="alert"></p>
                    </div>
                    @if ($passkeys->isEmpty())
                        <p class="text-sm text-brand-moss">{{ __('No passkeys registered yet.') }}</p>
                    @else
                        <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden">
                            @foreach ($passkeys as $cred)
                                <li class="flex flex-wrap items-center justify-between gap-3 bg-white px-4 py-3 text-sm">
                                    <div class="min-w-0 flex-1 space-y-2">
                                        <div>
                                            <label class="sr-only" for="passkey-alias-{{ $cred->getKey() }}">{{ __('Passkey name') }}</label>
                                            <input
                                                id="passkey-alias-{{ $cred->getKey() }}"
                                                type="text"
                                                wire:key="passkey-alias-{{ $cred->getKey() }}"
                                                wire:model="passkeyAliases.{{ $cred->getKey() }}"
                                                wire:blur="savePasskeyAlias(@js($cred->getKey()))"
                                                maxlength="255"
                                                autocomplete="off"
                                                class="block w-full max-w-md rounded-lg border-brand-mist shadow-sm focus:border-brand-forest focus:ring-brand-forest text-sm font-medium text-brand-ink"
                                                placeholder="{{ __('Passkey name') }}"
                                            />
                                        </div>
                                        <span class="block text-xs text-brand-moss">{{ __('Added :time', ['time' => $cred->created_at->diffForHumans()]) }}</span>
                                        @error('passkeyAliases.'.$cred->getKey())
                                            <p class="text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('removePasskey', @js([(string) $cred->getKey()]), @js(__('Remove passkey')), @js(__('Remove this passkey from your account? You will need another way to sign in if it was your only method.')), @js(__('Remove')), true)"
                                        class="inline-flex items-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                                    >
                                        {{ __('Remove') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- OAuth sign-in --}}
        @if (! empty($oauthProviders))
            <div class="dply-card overflow-hidden">
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('OAuth sign-in') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Link GitHub, GitLab, or Bitbucket to sign in with the same accounts you use for Git in Dply.') }}
                        </p>
                    </div>
                    <div class="lg:col-span-8 space-y-4">
                        @error('unlink')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        @foreach ($oauthProviders as $p)
                            @php
                                $linked = $socialAccounts->where('provider', $p['id']);
                            @endphp
                            <div class="rounded-xl border border-brand-mist p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                                    <span class="inline-flex items-center gap-2 text-sm font-medium text-brand-ink">
                                        <x-oauth-provider-icon :provider="$p['id']" />
                                        {{ $p['name'] }}
                                    </span>
                                    <a
                                        href="{{ route('oauth.redirect', ['provider' => $p['id'], 'return' => 'security']) }}"
                                        class="text-sm font-medium text-brand-sage hover:text-brand-forest"
                                    >
                                        {{ __('Link account') }}
                                    </a>
                                </div>
                                @if ($linked->isEmpty())
                                    <p class="text-sm text-brand-moss">{{ __('No accounts linked.') }}</p>
                                @else
                                    <ul class="space-y-2">
                                        @foreach ($linked as $account)
                                            <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                                <span class="text-brand-ink">{{ $account->nickname ?? $account->provider_id }}</span>
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('unlinkOAuthAccount', [{{ $account->id }}], @js(__('Unlink account')), @js(__('Unlink this account? You can link it again later from this page.')), @js(__('Unlink')), true)"
                                                    class="inline-flex items-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                                                >
                                                    {{ __('Unlink') }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Two-factor authentication --}}
        <div class="dply-card overflow-hidden">
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

    @include('livewire.partials.confirm-action-modal')
</div>
