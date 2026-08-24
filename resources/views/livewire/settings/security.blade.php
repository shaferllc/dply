@php
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
        'success' => 'bg-brand-sage/10',
        'info' => 'bg-sky-50',
        'warning' => 'bg-amber-50',
    ][$postureTone];
    $postureDot = [
        'success' => 'bg-brand-sage',
        'info' => 'bg-sky-500',
        'warning' => 'bg-amber-500',
    ][$postureTone];
@endphp

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
    {{-- Must stay INSIDE the root: Livewire injects wire:id into the first tag
         of the rendered view, so a @vite <link>/<script> above the root steals
         it and orphans this div. --}}
    @vite(['resources/js/dply-passkeys-lazy.js'])

    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Security'), 'icon' => 'shield-check'],
        ]" />
    @endpush

    <x-profile-shell
        dense
        :title="__('Security')"
        :description="__('Password, passkeys, OAuth sign-in, and 2FA — layer at least two so a stolen credential alone can\'t reach your account.')"
        icon="heroicon-o-shield-check"
    >
        {{-- No header actions: the breadcrumb already goes back to Profile, and
             2FA setup lives in its own card below.

             The three-tile stats strip is gone: passkey count and 2FA state were
             restated verbatim by those cards' own headers. Only posture said
             something new, and only when it is weak — so it is now a single band
             that appears when you are password-only, and nothing when you are
             covered. --}}
        @if ($postureTone === 'warning')
            <div class="border-b border-brand-ink/10 bg-amber-50 px-3 py-2 sm:px-4">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-amber-950">
                    <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                    <span class="font-semibold">{{ __('Password only') }}</span>
                    <span>{{ __('A stolen password alone reaches your account. Add 2FA or a passkey below.') }}</span>
                </p>
            </div>
        @endif


        @include('livewire.settings.partials.security.body')
    </x-profile-shell>

    @include('livewire.partials.confirm-action-modal')
</div>
