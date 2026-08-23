{{-- Security body: the same card composition as the profile and servers pages.
     Password and passkeys are the two you actually change, so they lead; OAuth
     and 2FA sit beside them rather than a screen further down. --}}
<div class="grid gap-3 p-3 sm:p-4 lg:grid-cols-2">
    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.security._password')
    </section>

    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.security._passkeys')
    </section>

    <section @class([
        'overflow-hidden rounded-xl border bg-white shadow-sm [&>div]:border-b-0',
        'border-amber-200' => ! $twoFactorOn,
        'border-brand-ink/12' => $twoFactorOn,
    ])>
        @include('livewire.settings.partials.security._two-factor')
    </section>

    @if (! empty($oauthProviders))
        <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
            @include('livewire.settings.partials.security._oauth')
        </section>
    @endif
</div>
