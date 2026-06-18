@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $u = auth()->user();
    $twoFactorOn = $u->hasTwoFactorEnabled();
    $passkeyCount = $passkeys->count();
    $linkedOAuth = collect($oauthProviders ?? [])->sum(fn ($p) => $socialAccounts->where('provider', $p['id'])->count());

    // Overall posture: green when 2FA + ≥1 passkey OR ≥1 OAuth link;
    // amber if password-only; neutral when nothing is set up yet.
    if ($twoFactorOn && ($passkeyCount > 0 || $linkedOAuth > 0)) {
        $postureTone = 'success';
        $postureLabel = __('Hardened');
        $postureSub = __('2FA + passkey/OAuth');
    } elseif ($twoFactorOn) {
        $postureTone = 'info';
        $postureLabel = __('Good');
        $postureSub = __('Password + 2FA');
    } else {
        $postureTone = 'warning';
        $postureLabel = __('Password only');
        $postureSub = __('Add 2FA or a passkey');
    }
    $postureTile = [
        'success' => 'border-brand-sage/30 bg-brand-sage/8',
        'info' => 'border-sky-200 bg-sky-50',
        'warning' => 'border-amber-200 bg-amber-50',
    ][$postureTone];
    $postureDot = [
        'success' => 'bg-brand-sage',
        'info' => 'bg-sky-500',
        'warning' => 'bg-amber-500',
    ][$postureTone];
@endphp

@vite(['resources/js/dply-passkeys-lazy.js'])

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

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Security'), 'icon' => 'shield-check'],
        ]" />
    @endpush

    {{-- Hero: posture + at-a-glance counts. --}}
    <x-hero-card
        :eyebrow="__('Account')"
        :title="__('Security')"
        :description="__('Password, passkeys, OAuth sign-in, and two-factor authentication. Layer at least two of these so a stolen credential alone can\'t reach your account.')"
        icon="shield-check"
        iconSize="md"
    >
        <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
            <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Back to profile') }}
        </x-outline-link>
        <x-outline-link href="{{ route('two-factor.setup') }}" wire:navigate>
            <x-heroicon-o-key class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Two-factor') }}
        </x-outline-link>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-2">
                <div class="rounded-2xl border px-4 py-3 shadow-sm {{ $postureTile }}">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Posture') }}</dt>
                    <dd class="mt-1 flex items-center gap-1.5">
                        <span class="inline-block h-2 w-2 rounded-full {{ $postureDot }}" aria-hidden="true"></span>
                        <span class="text-sm font-semibold text-brand-ink">{{ $postureLabel }}</span>
                    </dd>
                    <p class="mt-1 truncate text-[11px] text-brand-moss" title="{{ $postureSub }}">{{ $postureSub }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Passkeys') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $passkeyCount }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('registered|registered', $passkeyCount) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Device PIN / fingerprint') }}</p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-brand-sage/30 bg-brand-sage/8' => $twoFactorOn,
                    'border-amber-200 bg-amber-50' => ! $twoFactorOn,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('2FA') }}</dt>
                    <dd class="mt-1 flex items-center gap-1.5">
                        @if ($twoFactorOn)
                            <x-heroicon-m-check-circle class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                            <span class="text-sm font-semibold text-brand-ink">{{ __('Enabled') }}</span>
                        @else
                            <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-900" aria-hidden="true" />
                            <span class="text-sm font-semibold text-brand-ink">{{ __('Off') }}</span>
                        @endif
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Authenticator code') }}</p>
                </div>
            </dl>
        </x-slot:stats>
    </x-hero-card>

    <div class="mt-6 space-y-6">

        {{-- Password --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Credential') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Password') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Use a long, random password and store it in a password manager. Saving here only updates the fields below.') }}</p>
                </div>
                <p x-show="passwordSaved" x-transition x-cloak class="shrink-0 inline-flex items-center gap-1.5 text-[11px] font-semibold text-emerald-700">
                    <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Saved') }}
                </p>
            </div>
            <form wire:submit="updatePassword" autocomplete="on">
                {{-- Hidden username so password managers can pair the new value
                     with the right login. Without this, browsers complain. --}}
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
                <div class="space-y-5 p-6 sm:p-7">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="security_current_password" :value="__('Current password')" />
                            <x-text-input id="security_current_password" wire:model="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
                            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="security_password" :value="__('New password')" />
                            <x-text-input id="security_password" wire:model="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="security_password_confirmation" :value="__('Confirm new password')" />
                            <x-text-input id="security_password_confirmation" wire:model="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>
                    </div>
                </div>
            </form>
        </section>

        <x-unsaved-changes-bar
            :message="__('You have unsaved changes to your password.')"
            saveAction="updatePassword"
            discardAction="discardPasswordUnsaved"
            targets="current_password,password,password_confirmation"
            :saveLabel="__('Save password')"
        />

        {{-- Passkeys --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-finger-print class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Passwordless') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Passkeys') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Sign in with your device PIN, fingerprint, or a security key. Multiple passkeys per account are supported.') }}</p>
                </div>
                @if ($passkeyCount > 0)
                    <span class="shrink-0 rounded-full bg-brand-sage/15 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-forest ring-1 ring-brand-sage/20">{{ $passkeyCount }}</span>
                @endif
            </div>
            <div class="p-6 sm:p-7">
                @error('passkey')
                    <p class="mb-3 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="dply-passkey-alias" :value="__('Passkey name')" />
                        <x-text-input
                            id="dply-passkey-alias"
                            type="text"
                            class="mt-1 block w-full"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="{{ __('e.g. Work laptop') }}"
                        />
                    </div>
                    <button
                        type="button"
                        id="dply-passkey-register-btn"
                        class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:opacity-60"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add a passkey') }}
                    </button>
                </div>
                <p class="mt-1.5 text-[11px] text-brand-mist">{{ __('Optional — helps you recognize this passkey in the list below.') }}</p>
                <p id="dply-passkey-register-error" class="mt-2 hidden text-sm text-red-700" role="alert"></p>
            </div>

            <div class="border-t border-brand-ink/10 bg-brand-sand/35 px-6 py-2.5 sm:px-7">
                <p class="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Registered') }}</p>
            </div>
            @if ($passkeys->isEmpty())
                <div class="px-6 py-10 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-finger-print class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm text-brand-moss">{{ __('No passkeys registered yet.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($passkeys as $cred)
                        <li class="flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <div class="min-w-0 flex-1 space-y-1">
                                <label class="sr-only" for="passkey-alias-{{ $cred->getKey() }}">{{ __('Passkey name') }}</label>
                                <input
                                    id="passkey-alias-{{ $cred->getKey() }}"
                                    type="text"
                                    wire:key="passkey-alias-{{ $cred->getKey() }}"
                                    wire:model="passkeyAliases.{{ $cred->getKey() }}"
                                    wire:blur="savePasskeyAlias(@js($cred->getKey()))"
                                    maxlength="255"
                                    autocomplete="off"
                                    class="block w-full max-w-md border-0 bg-transparent p-0 text-sm font-semibold text-brand-ink focus:ring-0"
                                    placeholder="{{ __('Passkey name') }}"
                                />
                                <p class="text-[11px] text-brand-mist">{{ __('Added :time', ['time' => $cred->created_at->diffForHumans()]) }}</p>
                                @error('passkeyAliases.'.$cred->getKey())
                                    <p class="text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removePasskey', @js([(string) $cred->getKey()]), @js(__('Remove passkey')), @js(__('Remove this passkey? You\'ll need another way to sign in if it was your only method.')), @js(__('Remove')), true)"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                            >
                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Remove') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- OAuth sign-in --}}
        @if (! empty($oauthProviders))
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-arrow-top-right-on-square class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Single sign-on') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('OAuth sign-in') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Link GitHub, GitLab, or Bitbucket so you can sign in with the same account you use for Git.') }}</p>
                    </div>
                    @if ($linkedOAuth > 0)
                        <span class="shrink-0 rounded-full bg-brand-sage/15 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-forest ring-1 ring-brand-sage/20">{{ $linkedOAuth }}</span>
                    @endif
                </div>
                <div class="space-y-3 p-6 sm:p-7">
                    @error('unlink')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @foreach ($oauthProviders as $p)
                        @php $linked = $socialAccounts->where('provider', $p['id']); @endphp
                        <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/30 px-4 py-2.5">
                                <span class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                    <x-oauth-provider-icon :provider="$p['id']" />
                                    {{ $p['name'] }}
                                    @if ($linked->isNotEmpty())
                                        <span class="ms-1 inline-flex items-center gap-1 text-[11px] font-medium text-brand-forest">
                                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                            {{ trans_choice(':n linked|:n linked', $linked->count(), ['n' => $linked->count()]) }}
                                        </span>
                                    @endif
                                </span>
                                <a
                                    href="{{ route('oauth.redirect', ['provider' => $p['id'], 'return' => 'security']) }}"
                                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Link account') }}
                                </a>
                            </div>
                            @if ($linked->isEmpty())
                                <p class="px-4 py-3 text-sm text-brand-mist">{{ __('No accounts linked.') }}</p>
                            @else
                                <ul class="divide-y divide-brand-ink/10">
                                    @foreach ($linked as $account)
                                        <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm transition-colors hover:bg-brand-sand/15">
                                            <span class="truncate font-medium text-brand-ink">{{ $account->nickname ?? $account->provider_id }}</span>
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('unlinkOAuthAccount', [{{ $account->id }}], @js(__('Unlink account')), @js(__('Unlink this account? You can link it again later from this page.')), @js(__('Unlink')), true)"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                                            >
                                                <x-heroicon-o-link-slash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Unlink') }}
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Two-factor authentication --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-device-phone-mobile class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Step-up') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Two-factor authentication') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Require a code from your authenticator app when signing in. A stolen password alone won\'t reach your account.') }}</p>
                </div>
                <span @class([
                    'shrink-0 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1',
                    'bg-brand-sage/15 text-brand-forest ring-brand-sage/20' => $twoFactorOn,
                    'bg-amber-50 text-amber-900 ring-amber-200' => ! $twoFactorOn,
                ])>
                    <span @class([
                        'inline-block h-1.5 w-1.5 rounded-full',
                        'bg-brand-sage' => $twoFactorOn,
                        'bg-amber-500' => ! $twoFactorOn,
                    ])></span>
                    {{ $twoFactorOn ? __('Enabled') : __('Disabled') }}
                </span>
            </div>
            <div class="p-6 sm:p-7">
                @if (session('status') === 'two-factor-enabled' && session('recovery_codes'))
                    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-amber-900">
                            <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Store these recovery codes in a secure place. Each code can only be used once.') }}
                        </p>
                        <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-amber-950">
                            @foreach (session('recovery_codes') as $code)
                                <span class="rounded bg-white/60 px-2 py-1">{{ $code }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if ($twoFactorOn)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-forest">
                            <x-heroicon-m-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Two-factor authentication is enabled.') }}
                        </p>
                        <a href="{{ route('two-factor.setup') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-cog-6-tooth class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Manage or disable') }}
                        </a>
                    </div>
                @else
                    <a href="{{ route('two-factor.setup') }}" class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest">
                        <x-heroicon-o-shield-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Set up two-factor authentication') }}
                    </a>
                @endif
            </div>
        </section>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
