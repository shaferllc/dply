{{-- Profile hub: the identity block. Extracted so the page body can be
     laid out differently without four copies of the controls. --}}
            {{-- Identity: name / email / country / locale / timezone.
                 Lifted from the old /profile/edit page so settings/profile
                 is the single personal-settings surface. --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-user-circle"
                    :title="__('Your details')"
                    :note="__('Name, login email, country, language, and timezone.')"
                >
                    <x-slot:actions>
                        <p x-show="profileSaved" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Saved') }}
                        </p>
                        {{-- Avatar is read-only chrome, so it rides in the header
                             rather than stealing a form column or a field row. --}}
                        <img
                            src="{{ $this->gravatarUrl }}"
                            alt=""
                            width="24"
                            height="24"
                            class="h-6 w-6 shrink-0 rounded-full border border-brand-ink/10 shadow-sm"
                            title="{{ __('Gravatar, resolved from your email address.') }}"
                        />
                    </x-slot:actions>
                </x-workspace-panel-head>
                <div class="px-3 py-2.5 sm:px-4">
                    {{-- Six-column base: the two identity fields take half each,
                         the three locale fields take a third each, so every row
                         fills the width instead of trailing off mid-panel. --}}
                    <div class="grid gap-2.5 sm:grid-cols-6">
                        <div class="sm:col-span-3">
                            <x-input-label for="profile-name" :value="__('Name')" required />
                            <x-text-input id="profile-name" wire:model="profileForm.name" type="text" class="mt-1 block w-full" required autocomplete="name" />
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.name')" />
                        </div>
                        <div class="sm:col-span-3">
                            <x-input-label for="profile-email" :value="__('Email')" required />
                            <x-text-input id="profile-email" wire:model.live="profileForm.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.email')" />
                            @if ($u instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $u->hasVerifiedEmail())
                                <div class="mt-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                                    <p class="font-semibold">{{ __('Your email address is unverified.') }}</p>
                                    <button type="button" wire:click="sendVerificationEmail" class="mt-0.5 inline-flex items-center gap-1 text-xs font-semibold text-amber-950 underline underline-offset-2 hover:no-underline">
                                        {{ __('Re-send verification email') }} →
                                    </button>
                                    @if ($verificationLinkSent)
                                        <p class="mt-1 inline-flex items-center gap-1 font-semibold text-emerald-800">
                                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Verification link sent.') }}
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="profile-country" :value="__('Country')" />
                            <select id="profile-country" wire:model="profileForm.country_code" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                <option value="">{{ __('Select a country') }}</option>
                                @foreach ($countries as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.country_code')" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="profile-locale" :value="__('Language')" required />
                            <select id="profile-locale" wire:model="profileForm.locale" required class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach ($locales as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.locale')" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label for="profile-timezone" :value="__('Timezone')" required />
                            <select id="profile-timezone" wire:model="profileForm.timezone" required class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach ($this->timezones as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-1" :messages="$errors->get('profileForm.timezone')" />
                        </div>
                    </div>
                </div>
            </div>
