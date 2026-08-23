@unlessfeature('global.billing_enabled')
    <p class="mx-auto mt-8 max-w-2xl rounded-xl border border-brand-gold/25 bg-brand-gold/10 px-4 py-3 text-center text-sm text-brand-forest">
        {{ __('dply is in invite-only beta — nothing is charged yet. These are the prices when billing turns on, with at least 30 days\' notice.') }}
    </p>
@endfeature
