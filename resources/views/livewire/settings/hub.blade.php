@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $u = auth()->user();
    $isProfile = $section !== 'servers';
    $themeOptions = config('user_preferences.theme_options', []);
    $navLayoutOptions = config('user_preferences.navigation_layout_options', []);
    $countries = collect(config('profile_options.countries', []))->sort();
    $locales = config('profile_options.locales', []);
    $sessions = $this->sessions;
    $otherSessions = count(array_filter($sessions, fn ($s) => ! $s['is_current']));

    // Active values surfaced as stat tiles so the user can see at a glance
    // what they're currently set to without scrolling each form section.
    $currentTheme = $ui['theme'] ?? 'system';
    $currentNavLayout = $ui['navigation_layout'] ?? 'sidebar';
@endphp

<div>
    <x-breadcrumb-trail
        :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
            ['label' => $isProfile ? __('Profile') : __('Servers & Sites'), 'icon' => $isProfile ? 'user-circle' : 'server'],
        ]"
        wrapper-class="mb-2"
    />

    {{-- Hero card. Stat tiles show the user's current theme / nav layout /
         timezone so a glance reveals what's set without opening each form. --}}
    <section class="dply-card overflow-hidden">
        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
            <div class="lg:col-span-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-cog-6-tooth class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Settings') }}</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Profile') }}</h2>
                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Identity, preferences, sessions, and account on this page. Servers & Sites covers organization and team defaults — servers belong to teams.') }}
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-outline-link href="{{ route('docs.index') }}" wire:navigate>
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Documentation') }}
                    </x-outline-link>
                    <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
                        <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Profile') }}
                    </x-outline-link>
                    <x-outline-link href="{{ route('profile.security') }}" wire:navigate>
                        <x-heroicon-o-shield-check class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Security') }}
                    </x-outline-link>
                </div>
            </div>
            <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Theme') }}</dt>
                    <dd class="mt-1 flex items-center gap-1.5">
                        @if ($currentTheme === 'light')
                            <x-heroicon-m-sun class="h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" />
                        @elseif ($currentTheme === 'dark')
                            <x-heroicon-m-moon class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                        @else
                            <x-heroicon-m-computer-desktop class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                        @endif
                        <span class="text-sm font-semibold capitalize text-brand-ink">{{ __(ucfirst((string) $currentTheme)) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Appearance') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Nav') }}</dt>
                    <dd class="mt-1 flex items-center gap-1.5">
                        @if ($currentNavLayout === 'top')
                            <x-heroicon-m-bars-3 class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                            <span class="text-sm font-semibold text-brand-ink">{{ __('Top') }}</span>
                        @else
                            <x-heroicon-m-squares-2x2 class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                            <span class="text-sm font-semibold text-brand-ink">{{ __('Sidebar') }}</span>
                        @endif
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Settings layout') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Timezone') }}</dt>
                    <dd class="mt-1 truncate text-sm font-semibold text-brand-ink" title="{{ $u?->timezone ?? config('app.timezone') }}">{{ $u?->timezone ?? config('app.timezone') }}</dd>
                    <p class="mt-1 truncate text-[11px] text-brand-mist">{{ now($u?->timezone ?? config('app.timezone'))->format('g:i A') }} · {{ __('local time') }}</p>
                </div>
            </dl>
        </div>
    </section>

    {{-- Section tabs. Family's segmented control rather than the previous
         underlined nav — same affordance, lower visual weight. --}}
    <div class="mt-6">
        <nav class="inline-flex flex-wrap items-center gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm" aria-label="{{ __('Settings sections') }}">
            <a
                href="{{ route('settings.profile') }}"
                wire:navigate
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                    'bg-brand-ink text-brand-cream shadow-sm' => request()->routeIs('settings.profile'),
                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('settings.profile'),
                ])
            >
                <x-heroicon-o-user-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Profile') }}
            </a>
            <a
                href="{{ route('settings.servers') }}"
                wire:navigate
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                    'bg-brand-ink text-brand-cream shadow-sm' => request()->routeIs('settings.servers'),
                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('settings.servers'),
                ])
            >
                <x-heroicon-o-server class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Servers & Sites') }}
            </a>
        </nav>
    </div>

    <div class="mt-6 space-y-6"
         x-data="{
             profileSaved: false,
             sessionRevoked: false,
             sessionsRevoked: false,
             init() {
                 $wire.on('profile-updated', () => { this.profileSaved = true; setTimeout(() => { this.profileSaved = false }, 2000); });
                 $wire.on('session-revoked', () => { this.sessionRevoked = true; setTimeout(() => { this.sessionRevoked = false }, 3000); });
                 $wire.on('sessions-revoked', () => { this.sessionsRevoked = true; setTimeout(() => { this.sessionsRevoked = false }, 3000); });
             },
         }">
        @if ($section === 'profile')
            {{-- Identity: name / email / country / locale / timezone.
                 Lifted from the old /profile/edit page so settings/profile
                 is the single personal-settings surface. --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                            <x-heroicon-o-user-circle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Identity') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your details') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Name, login email, country, language, and timezone. Avatar is loaded via Gravatar — change it by updating the email tied to your Gravatar account.') }}</p>
                        </div>
                        <p x-show="profileSaved" x-transition x-cloak class="shrink-0 inline-flex items-center gap-1.5 text-[11px] font-semibold text-emerald-700">
                            <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Saved') }}
                        </p>
                    </div>
                </div>
                <div class="grid gap-6 p-6 sm:p-7 lg:grid-cols-3 lg:gap-8">
                    <div class="lg:col-span-1">
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4 text-center">
                            <img
                                src="{{ $this->gravatarUrl }}"
                                alt=""
                                width="96"
                                height="96"
                                class="mx-auto rounded-full border border-brand-ink/10 shadow-sm"
                            />
                            <p class="mt-3 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Gravatar') }}</p>
                            <p class="mt-1 text-[11px] leading-relaxed text-brand-moss">{{ __('Resolved from your email.') }}</p>
                        </div>
                    </div>
                    <div class="space-y-5 lg:col-span-2">
                        <div>
                            <x-input-label for="profile-name" :value="__('Name')" required />
                            <x-text-input id="profile-name" wire:model="profileForm.name" type="text" class="mt-1 block w-full" required autocomplete="name" />
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.name')" />
                        </div>
                        <div>
                            <x-input-label for="profile-email" :value="__('Email')" required />
                            <x-text-input id="profile-email" wire:model.live="profileForm.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.email')" />
                            @if ($u instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $u->hasVerifiedEmail())
                                <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    <p class="font-semibold">{{ __('Your email address is unverified.') }}</p>
                                    <button type="button" wire:click="sendVerificationEmail" class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-amber-950 underline underline-offset-2 hover:no-underline">
                                        {{ __('Re-send verification email') }} →
                                    </button>
                                    @if ($verificationLinkSent)
                                        <p class="mt-2 inline-flex items-center gap-1 font-semibold text-emerald-800">
                                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Verification link sent.') }}
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="profile-country" :value="__('Country')" />
                                <select id="profile-country" wire:model="profileForm.country_code" class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                    <option value="">{{ __('Select a country') }}</option>
                                    @foreach ($countries as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error class="mt-2" :messages="$errors->get('profileForm.country_code')" />
                            </div>
                            <div>
                                <x-input-label for="profile-locale" :value="__('Language')" required />
                                <select id="profile-locale" wire:model="profileForm.locale" required class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                    @foreach ($locales as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error class="mt-2" :messages="$errors->get('profileForm.locale')" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="profile-timezone" :value="__('Timezone')" required />
                            <select id="profile-timezone" wire:model="profileForm.timezone" required class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach ($this->timezones as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                            <x-input-error class="mt-2" :messages="$errors->get('profileForm.timezone')" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                            <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Personal') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your preferences') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Only you see these — not shared with your organization or teams.') }}</p>
                        </div>
                    </div>
                </div>
                <form wire:submit="saveProfile" class="p-6 sm:p-7">
                    <button type="submit" class="sr-only">{{ __('Save settings') }}</button>

                    {{-- Toggle stack. Same row pattern used on Automation
                         (per-row hover, mt-aligned checkbox). --}}
                    <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                        @foreach ([
                            ['key' => 'newsletter', 'title' => __('Receive newsletter'), 'desc' => __('Product updates only — no spam.')],
                            ['key' => 'keyboard_shortcuts', 'title' => __('Enable keyboard shortcuts'), 'desc' => __('Turns keyboard shortcuts on or off in the app.')],
                            ['key' => 'redirect_home_to_app', 'title' => __('Redirect to app when logged in'), 'desc' => __('Visiting the marketing homepage signed in sends you to the dashboard.')],
                            ['key' => 'subscription_invoice_emails', 'title' => __('Subscription invoice emails'), 'desc' => __('When your org moves from trial to Pro, include Stripe invoice PDFs in email.')],
                        ] as $toggle)
                            <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                <input type="checkbox" wire:model.boolean="ui.{{ $toggle['key'] }}" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-brand-ink">{{ $toggle['title'] }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $toggle['desc'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    {{-- Theme picker --}}
                    <div class="mt-6">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Theme mode') }}</p>
                            <p class="text-[11px] text-brand-mist">{{ __('Choose appearance or follow your system setting.') }}</p>
                        </div>
                        <div class="mt-2 inline-flex flex-wrap gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm">
                            @foreach ($themeOptions as $opt)
                                <button
                                    type="button"
                                    wire:click="persistTheme('{{ $opt }}')"
                                    @class([
                                        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                                        'bg-brand-ink text-brand-cream shadow-sm' => ($ui['theme'] ?? '') === $opt,
                                        'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ($ui['theme'] ?? '') !== $opt,
                                    ])
                                >
                                    @if ($opt === 'light')
                                        <x-heroicon-o-sun class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Light') }}
                                    @elseif ($opt === 'dark')
                                        <x-heroicon-o-moon class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Dark') }}
                                    @else
                                        <x-heroicon-o-computer-desktop class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('System') }}
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        @error('ui.theme') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Navigation layout --}}
                    <div class="mt-6">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Navigation layout') }}</p>
                            <p class="text-[11px] text-brand-mist">{{ __('Sidebar on large screens or a horizontal link row under the header.') }}</p>
                        </div>
                        <div class="mt-2 inline-flex flex-wrap gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm">
                            @foreach ($navLayoutOptions as $opt)
                                <button
                                    type="button"
                                    wire:click="persistNavigationLayout('{{ $opt }}')"
                                    @class([
                                        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                                        'bg-brand-ink text-brand-cream shadow-sm' => ($ui['navigation_layout'] ?? '') === $opt,
                                        'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ($ui['navigation_layout'] ?? '') !== $opt,
                                    ])
                                >
                                    @if ($opt === 'sidebar')
                                        <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Sidebar') }}
                                    @else
                                        <x-heroicon-o-bars-3 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Top') }}
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        @error('ui.navigation_layout') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Notification position --}}
                    <div class="mt-6">
                        <div class="flex items-baseline justify-between gap-3">
                            <label for="notification-position" class="text-sm font-semibold text-brand-ink">{{ __('Notification position') }}</label>
                            <p class="text-[11px] text-brand-mist">{{ __('Where toast notifications appear on screen.') }}</p>
                        </div>
                        <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-stretch">
                            <select
                                id="notification-position"
                                wire:model="ui.notification_position"
                                class="block w-full min-w-0 max-w-md flex-1 rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach (config('user_preferences.notification_positions', []) as $value => $label)
                                    <option value="{{ $value }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                            <button
                                type="button"
                                data-notification-preview-message="{{ __('This is where notifications will appear.') }}"
                                onclick="window.dispatchEvent(new CustomEvent('toast', { detail: { message: this.dataset.notificationPreviewMessage, type: 'success', position: document.getElementById('notification-position').value } }))"
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Test') }}
                            </button>
                        </div>
                        @error('ui.notification_position') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </form>
            </section>

            {{-- Active sessions --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['violet'] }}">
                            <x-heroicon-o-device-phone-mobile class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Devices') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Active sessions') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Each device currently signed in. Revoking a session logs that device out on its next request.') }}</p>
                        </div>
                        @if ($otherSessions > 0)
                            <button type="button" wire:click="openConfirmActionModal('revokeOtherSessions', [], @js(__('Revoke all other sessions')), @js(__('Revoke all other sessions? You will stay logged in on this device only.')), @js(__('Revoke sessions')), true)" class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm transition hover:bg-red-100">
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Revoke other devices') }}
                            </button>
                        @endif
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                    <p x-show="sessionRevoked" x-transition x-cloak class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                        <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Session revoked.') }}
                    </p>
                    <p x-show="sessionsRevoked" x-transition x-cloak class="mb-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                        <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('All other sessions revoked.') }}
                    </p>
                    @error('session')
                        <p class="mb-3 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    @if ($sessions === [])
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-5 py-8 text-center">
                            <p class="text-sm text-brand-moss">{{ __('No active sessions.') }}</p>
                        </div>
                    @else
                        <ul class="space-y-2">
                            @foreach ($sessions as $session)
                                <li class="flex items-center justify-between gap-4 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition-colors hover:bg-brand-sand/15">
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $session['device_label'] }}</span>
                                            @if ($session['is_current'])
                                                <span class="inline-flex items-center rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('This device') }}</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-[11px] text-brand-moss">
                                            <span class="font-mono">{{ $session['ip_address'] ?? __('Unknown IP') }}</span>
                                            <span class="text-brand-mist"> · </span>
                                            {{ __('Last active :time', ['time' => \Carbon\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans()]) }}
                                        </p>
                                    </div>
                                    @if (! $session['is_current'])
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('revokeSession', ['{{ $session['id'] }}'], @js(__('Revoke session')), @js(__('Revoke this session? That device will be logged out on its next request.')), @js(__('Revoke')), true)"
                                            class="shrink-0 inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-700 hover:underline"
                                        >
                                            <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Revoke') }}
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            {{-- Danger zone --}}
            <section>
                <p class="mb-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-red-600/80">{{ __('Danger zone') }}</p>
                <div class="dply-card overflow-hidden border-red-200">
                    <div class="border-b border-red-200/60 bg-red-50/60 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-700 ring-1 ring-red-200">
                                <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-red-700/80">{{ __('Permanent') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Delete account') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('You\'ll be signed out and lose access to organizations and data tied to this login. This cannot be undone.') }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-3 px-6 py-4 sm:px-7">
                        <a
                            href="{{ route('profile.delete-account') }}"
                            wire:navigate
                            class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 shadow-sm transition hover:bg-red-100"
                        >
                            <x-heroicon-o-arrow-right-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Go to delete account page') }}
                        </a>
                    </div>
                </div>
            </section>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your profile information.')"
                saveAction="updateProfile"
                discardAction="discardProfileFormUnsaved"
                :targets="$profileFormUnsavedTargets"
                :saveLabel="__('Save profile')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your profile preferences.')"
                saveAction="saveProfile"
                discardAction="discardProfileUnsaved"
                :targets="$profileUnsavedTargets"
                :saveLabel="__('Save settings')"
            />

            <x-slot name="modals">
                @include('livewire.partials.confirm-action-modal')
            </x-slot>
        @endif

        @if ($section === 'servers')
            {{-- Your timezone --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sky'] }}">
                            <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Time') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your timezone') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Used for schedules, Insights quiet hours, and when applying timezone on new servers below.') }}</p>
                        </div>
                    </div>
                </div>
                <form wire:submit="saveProfileTimezone" class="p-6 sm:p-7">
                    <button type="submit" class="sr-only">{{ __('Save timezone') }}</button>
                    <x-input-label for="hub-profile-timezone" :value="__('Timezone')" required />
                    <select
                        id="hub-profile-timezone"
                        wire:model="profileTimezone"
                        required
                        class="mt-1 block w-full max-w-md rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        @foreach ($this->timezones as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </select>
                    @error('profileTimezone') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </form>
            </section>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to your timezone.')"
                saveAction="saveProfileTimezone"
                discardAction="discardProfileTimezoneUnsaved"
                :targets="$profileTimezoneUnsavedTargets"
                :saveLabel="__('Save timezone')"
            />

            {{-- Organization defaults --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                            <x-heroicon-o-building-office-2 class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Org-wide') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Organization defaults') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Email and new-server policy for the current organization.') }}</p>
                        </div>
                        @if ($currentOrg)
                            <span class="shrink-0 rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss" title="{{ $currentOrg->name }}">{{ $currentOrg->name }}</span>
                        @endif
                    </div>
                </div>
                <form wire:submit="saveOrganizationServersSites" class="p-6 sm:p-7">
                    <button type="submit" class="sr-only">{{ __('Save organization settings') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            {{ __('Create or join an organization to configure these options.') }}
                        </div>
                    @elseif (! $canEditOrgPrefs)
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-cream/40 px-4 py-3 text-sm text-brand-moss">
                            {{ __('Only organization admins can change organization defaults.') }}
                        </div>
                    @else
                        <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                            <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                <input type="checkbox" wire:model.boolean="organizationServerSite.email_server_passwords" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-brand-ink">{{ __('Receive server passwords via email') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('When off, retrieve credentials from each server\'s settings in the app.') }}</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                <input type="checkbox" wire:model.boolean="organizationServerSite.set_timezone_on_new_servers" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-brand-ink">{{ __('Set timezone on new servers') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Apply the timezone above to new servers. (Currently: :tz)', ['tz' => $userTimezoneLabel]) }}</span>
                                </span>
                            </label>
                        </div>
                    @endif
                </form>
            </section>

            {{-- Insights preferences --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                            <x-heroicon-o-light-bulb class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Alerts') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Insights preferences') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Org defaults for alert batching and quiet hours. Critical findings still notify immediately when channels are subscribed.') }}</p>
                        </div>
                    </div>
                </div>
                <form wire:submit="saveOrganizationInsights" class="p-6 sm:p-7">
                    <button type="submit" class="sr-only">{{ __('Save Insights preferences') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            {{ __('Create or join an organization to configure these options.') }}
                        </div>
                    @elseif (! $canEditOrgPrefs)
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-cream/40 px-4 py-3 text-sm text-brand-moss">
                            {{ __('Only organization admins can change Insights preferences.') }}
                        </div>
                    @else
                        <div class="space-y-5">
                            <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                                <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.digest_non_critical" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Digest non-critical findings') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Batch warning and info findings into email. Critical stays immediate.') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.quiet_hours_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Quiet hours for non-critical') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Suppress immediate non-critical insight alerts within the window below. Uses the app timezone (:tz).', ['tz' => config('app.timezone')]) }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                    <input type="checkbox" wire:model.boolean="organizationInsights.allow_config_mutation" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                    <span class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-brand-ink">{{ __('Allow Insights to mutate server configs') }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Apply-fix actions that edit on-disk service configs (e.g. pm.max_children) can run. Restart-only fixes are unaffected. Backups are always taken; revert is one click.') }}</span>
                                    </span>
                                </label>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label for="org-insights-digest-frequency" class="block text-xs font-semibold text-brand-ink">{{ __('Digest frequency') }}</label>
                                    <select
                                        id="org-insights-digest-frequency"
                                        wire:model="organizationInsights.digest_frequency"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    >
                                        <option value="daily">{{ __('Daily (08:00)') }}</option>
                                        <option value="weekly">{{ __('Weekly (Mon 08:15)') }}</option>
                                    </select>
                                    @error('organizationInsights.digest_frequency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="org-insights-quiet-start" class="block text-xs font-semibold text-brand-ink">{{ __('Quiet start (hour)') }}</label>
                                    <input
                                        id="org-insights-quiet-start"
                                        type="number"
                                        min="0"
                                        max="23"
                                        wire:model="organizationInsights.quiet_hours_start"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                    @error('organizationInsights.quiet_hours_start') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="org-insights-quiet-end" class="block text-xs font-semibold text-brand-ink">{{ __('Quiet end (hour)') }}</label>
                                    <input
                                        id="org-insights-quiet-end"
                                        type="number"
                                        min="0"
                                        max="23"
                                        wire:model="organizationInsights.quiet_hours_end"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                    />
                                    @error('organizationInsights.quiet_hours_end') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    @endif
                </form>
            </section>

            {{-- Team defaults --}}
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['violet'] }}">
                            <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Per-team') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Team defaults') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('List and creation defaults for servers and sites in the selected team.') }}</p>
                        </div>
                    </div>
                </div>
                <form wire:submit="saveTeamServersSites" class="p-6 sm:p-7">
                    <button type="submit" class="sr-only">{{ __('Save team settings') }}</button>
                    @if (! $currentOrg)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            {{ __('Create or join an organization first.') }}
                        </div>
                    @elseif ($teams->isEmpty())
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-cream/40 px-4 py-3 text-sm text-brand-moss">
                            {{ __('Add a team to this organization to configure team defaults.') }}
                        </div>
                    @else
                        <div class="space-y-5">
                            <div>
                                <label for="settings-team" class="block text-xs font-semibold text-brand-ink">{{ __('Team') }}</label>
                                <select
                                    id="settings-team"
                                    wire:model.live="selectedTeamId"
                                    class="mt-1 block w-full max-w-md rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ($teams as $team)
                                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Choose which team\'s defaults you\'re editing.') }}</p>
                            </div>

                            @if (! $canEditTeamPrefs)
                                <div class="rounded-lg border border-brand-ink/10 bg-brand-cream/40 px-4 py-3 text-sm text-brand-moss">
                                    {{ __('Only team admins (or organization admins) can change team defaults.') }}
                                </div>
                            @else
                                <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                                    <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.show_server_updates_in_list" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        <span class="min-w-0 flex-1">
                                            <span class="text-sm font-medium text-brand-ink">{{ __('Show server updates in list') }}</span>
                                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Surface pending updates in the server list when available.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-3 bg-white px-4 py-3.5 transition-colors hover:bg-brand-sand/15">
                                        <input type="checkbox" wire:model.boolean="teamServerSite.isolate_new_sites" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        <span class="min-w-0 flex-1">
                                            <span class="text-sm font-medium text-brand-ink">{{ __('Always use isolation for new sites') }}</span>
                                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Prefer isolated system users for new sites when the stack supports it.') }}</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="team-default-server-sort" class="block text-xs font-semibold text-brand-ink">{{ __('Default server sort') }}</label>
                                        <select
                                            id="team-default-server-sort"
                                            wire:model="teamServerSite.default_server_sort"
                                            class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.server_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_server_sort') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="team-default-site-sort" class="block text-xs font-semibold text-brand-ink">{{ __('Default site sort') }}</label>
                                        <select
                                            id="team-default-site-sort"
                                            wire:model="teamServerSite.default_site_sort"
                                            class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                        >
                                            @foreach (config('user_preferences.site_sort_options', []) as $value => $label)
                                                <option value="{{ $value }}">{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                        @error('teamServerSite.default_site_sort') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </form>
            </section>

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to organization defaults.')"
                saveAction="saveOrganizationServersSites"
                discardAction="discardOrganizationServersSitesUnsaved"
                :targets="$organizationServerSiteUnsavedTargets"
                :save-disabled="! $currentOrg || ! $canEditOrgPrefs"
                :saveLabel="__('Save organization settings')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to Insights preferences.')"
                saveAction="saveOrganizationInsights"
                discardAction="discardOrganizationInsightsUnsaved"
                :targets="$organizationInsightsUnsavedTargets"
                :save-disabled="! $currentOrg || ! $canEditOrgPrefs"
                :saveLabel="__('Save Insights preferences')"
            />

            <x-unsaved-changes-bar
                :message="__('You have unsaved changes to team defaults.')"
                saveAction="saveTeamServersSites"
                discardAction="discardTeamServersSitesUnsaved"
                :targets="$teamServersSitesUnsavedTargets"
                :save-disabled="! $currentOrg || $teams->isEmpty() || ! $canEditTeamPrefs"
                :saveLabel="__('Save team settings')"
            />
        @endif
    </div>
</div>
