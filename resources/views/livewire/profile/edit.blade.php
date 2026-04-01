@php
    $countries = collect(config('profile_options.countries'))->sort();
    $locales = config('profile_options.locales');
    $currencies = config('profile_options.currencies');
@endphp

<div
    x-data="{
        profileSaved: false,
        billingSaved: false,
        sessionRevoked: false,
        sessionsRevoked: false,
        init() {
            $wire.on('profile-updated', () => {
                this.profileSaved = true;
                setTimeout(() => { this.profileSaved = false }, 2000);
            });
            $wire.on('billing-updated', () => {
                this.billingSaved = true;
                setTimeout(() => { this.billingSaved = false }, 2000);
            });
            $wire.on('session-revoked', () => {
                this.sessionRevoked = true;
                setTimeout(() => { this.sessionRevoked = false }, 3000);
            });
            $wire.on('sessions-revoked', () => {
                this.sessionsRevoked = true;
                setTimeout(() => { this.sessionsRevoked = false }, 3000);
            });
        },
    }"
>
    <x-livewire-validation-errors />

    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Profile') }}</li>
        </ol>
    </nav>

    <div class="space-y-8">
        {{-- General information --}}
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4 space-y-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('General information') }}</h2>
                    <p class="text-sm text-brand-moss leading-relaxed">
                        {{ __('Edit your general profile information here. Your avatar is loaded via Gravatar—you can change it by updating the email associated with your Gravatar account.') }}
                    </p>
                    <div class="pt-2">
                        <img
                            src="{{ $this->gravatarUrl }}"
                            alt=""
                            width="96"
                            height="96"
                            class="rounded-full border border-brand-ink/10 shadow-sm"
                        />
                    </div>
                </div>
                <div class="lg:col-span-8">
                    <div class="space-y-5">
                        <div>
                            <x-input-label for="name" :value="__('Name')" required />
                            <x-text-input id="name" wire:model="profileForm.name" type="text" class="mt-1 block w-full" required autofocus autocomplete="name" />
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.name')" />
                        </div>
                        <div>
                            <x-input-label for="email" :value="__('Email')" required />
                            <x-text-input id="email" wire:model.live="profileForm.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.email')" />
                            @if ($this->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $this->user()->hasVerifiedEmail())
                                <div class="mt-2">
                                    <p class="text-sm text-brand-ink">{{ __('Your email address is unverified.') }}</p>
                                    <button type="button" wire:click="sendVerificationEmail" class="underline text-sm text-brand-moss hover:text-brand-ink rounded-md focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 mt-1">
                                        {{ __('Click here to re-send the verification email.') }}
                                    </button>
                                    @if ($verificationLinkSent)
                                        <p class="mt-2 font-medium text-sm text-green-700">{{ __('A new verification link has been sent to your email address.') }}</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div>
                            <x-input-label for="country_code" :value="__('Country')" />
                            <select
                                id="country_code"
                                wire:model="profileForm.country_code"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            >
                                <option value="">{{ __('Select a country') }}</option>
                                @foreach ($countries as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.country_code')" />
                        </div>
                        <div>
                            <x-input-label for="locale" :value="__('Language')" required />
                            <select
                                id="locale"
                                wire:model="profileForm.locale"
                                required
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            >
                                @foreach ($locales as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs text-brand-moss">
                                @if (config('dply.community_github_url'))
                                    {!! __('If you want to add your own language, head over to :link to send in a PR.', ['link' => '<a href="'.e(config('dply.community_github_url')).'" class="text-brand-forest underline hover:text-brand-ink" target="_blank" rel="noopener noreferrer">'.__('our GitHub repository').'</a>']) !!}
                                @else
                                    {{ __('Additional languages may be added over time.') }}
                                @endif
                            </p>
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.locale')" />
                        </div>
                        <div>
                            <x-input-label for="timezone" :value="__('Timezone')" required />
                            <select
                                id="timezone"
                                wire:model="profileForm.timezone"
                                required
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 max-h-48 sm:max-h-none"
                            >
                                @foreach ($this->timezones as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.timezone')" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 sm:px-8 py-4 flex justify-end items-center gap-4">
                <p x-show="profileSaved" x-transition class="text-sm text-brand-moss">{{ __('Saved.') }}</p>
                <x-primary-button type="button" wire:click="updateProfile" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="updateProfile">{{ __('Save profile') }}</span>
                    <span wire:loading wire:target="updateProfile" class="inline-flex items-center justify-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Saving…') }}
                    </span>
                </x-primary-button>
            </div>
        </div>

        {{-- Billing --}}
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Billing') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Edit your billing details here. If you have a valid VAT number you may also enter it below. Invoices use your organization subscription where applicable; these fields support invoicing details on file.') }}
                    </p>
                </div>
                <div class="lg:col-span-8">
                    <div class="space-y-5">
                        <div>
                            <x-input-label for="invoice_email" :value="__('Invoice email')" />
                            <x-text-input id="invoice_email" wire:model="billingForm.invoice_email" type="email" class="mt-1 block w-full" autocomplete="email" />
                            <p class="mt-1.5 text-xs text-brand-moss">{{ __('Enter an email address to receive invoices at, if different from your login email.') }}</p>
                            <x-input-error class="mt-2" :messages="$errors->get('billingForm.invoice_email')" />
                        </div>
                        <div>
                            <x-input-label for="vat_number" :value="__('VAT number')" />
                            <x-text-input id="vat_number" wire:model="billingForm.vat_number" type="text" class="mt-1 block w-full" placeholder="NL123456789B01" autocomplete="off" />
                            <p class="mt-1.5 text-xs text-brand-moss">{{ __('Include your country code (e.g. NL, DE, FR). EU businesses may receive invoices with a VAT exemption notice when valid.') }}</p>
                            <x-input-error class="mt-2" :messages="$errors->get('billingForm.vat_number')" />
                        </div>
                        <div>
                            <x-input-label for="billing_currency" :value="__('Currency')" />
                            <select
                                id="billing_currency"
                                wire:model="billingForm.billing_currency"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            >
                                <option value="">{{ __('Select a currency') }}</option>
                                @foreach ($currencies as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs text-brand-moss">{{ __('Select the currency you prefer for invoices and payment references.') }}</p>
                            <x-input-error class="mt-2" :messages="$errors->get('billingForm.billing_currency')" />
                        </div>
                        <div>
                            <x-input-label for="billing_details" :value="__('Billing details')" />
                            <textarea
                                id="billing_details"
                                wire:model="billingForm.billing_details"
                                rows="4"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                placeholder="{{ __('Legal name, address, and other details to show on invoices') }}"
                            ></textarea>
                            <p class="mt-1.5 text-xs text-brand-moss">{{ __('Shown on newly created invoices when provided.') }}</p>
                            <x-input-error class="mt-2" :messages="$errors->get('billingForm.billing_details')" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 sm:px-8 py-4 flex justify-end items-center gap-4">
                <p x-show="billingSaved" x-transition class="text-sm text-brand-moss">{{ __('Saved.') }}</p>
                <x-primary-button type="button" wire:click="updateBilling" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="updateBilling">{{ __('Save billing details') }}</span>
                    <span wire:loading wire:target="updateBilling" class="inline-flex items-center justify-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Saving…') }}
                    </span>
                </x-primary-button>
            </div>
        </div>

        {{-- Active Sessions --}}
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Active Sessions') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Manage your active sessions. Revoking a session will log out that device on its next request.') }}
                    </p>
                </div>
                <div class="lg:col-span-8 space-y-4">
                    <p x-show="sessionRevoked" x-transition class="text-sm text-green-700">{{ __('Session revoked.') }}</p>
                    <p x-show="sessionsRevoked" x-transition class="text-sm text-green-700">{{ __('All other sessions have been revoked.') }}</p>
                    @error('session')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="space-y-4">
                        @forelse ($this->sessions as $session)
                            <div class="flex items-center justify-between rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-brand-ink">{{ $session['device_label'] }}</span>
                                        @if ($session['is_current'])
                                            <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-xs font-medium text-green-800 ring-1 ring-inset ring-green-600/20">{{ __('This device') }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-brand-moss">
                                        {{ $session['ip_address'] ?? __('Unknown IP') }}
                                        · {{ __('Last active') }} {{ \Carbon\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans() }}
                                    </p>
                                </div>
                                @if (!$session['is_current'])
                                    <button type="button" wire:click="openConfirmActionModal('revokeSession', ['{{ $session['id'] }}'], @js(__('Revoke session')), @js(__('Are you sure you want to revoke this session? That device will be logged out.')), @js(__('Revoke')), true)" class="ml-4 shrink-0 inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-lg font-semibold text-xs text-white hover:bg-red-700">
                                        {{ __('Revoke') }}
                                    </button>
                                @else
                                    <span class="ml-4 shrink-0 text-sm text-brand-moss">{{ __('Current session') }}</span>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-brand-moss">{{ __('No active sessions.') }}</p>
                        @endforelse
                    </div>
                    @if (count(array_filter($this->sessions, fn ($s) => !$s['is_current'])) > 0)
                        <button type="button" wire:click="openConfirmActionModal('revokeOtherSessions', [], @js(__('Revoke all other sessions')), @js(__('Revoke all other sessions? You will stay logged in on this device only.')), @js(__('Revoke sessions')), true)" class="inline-flex items-center px-4 py-2 bg-white border border-brand-ink/15 rounded-lg font-semibold text-xs text-brand-ink hover:bg-brand-sand/40">
                            {{ __('Revoke all other sessions') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Danger zone — delete account (full flow on separate page) --}}
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-mist mb-3">{{ __('Danger zone') }}</p>
            <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Delete account') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Account deletion is permanent. You will be signed out and lose access to organizations and data tied to this login.') }}
                        </p>
                    </div>
                    <div class="lg:col-span-8">
                        <div class="border-l-4 border-amber-500 bg-amber-50 rounded-r-xl px-4 py-3">
                            <p class="text-sm text-amber-950 leading-relaxed">
                                {{ __('You can remove your account here. Note that this is an irreversible action and cannot be undone.') }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 sm:px-8 py-4 flex justify-end">
                    <a
                        href="{{ route('profile.delete-account') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center px-5 py-2.5 rounded-xl border border-brand-ink/15 bg-white font-semibold text-sm text-brand-ink shadow-sm hover:bg-brand-sand/50 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 transition-colors"
                    >{{ __('Go to delete account page') }}</a>
                </div>
            </div>
        </div>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
