<div>
    <div class="mb-6 space-y-4">
        <div class="flex gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm text-brand-moss">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-sage shadow-sm ring-1 ring-brand-ink/5" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </span>
            <p class="leading-relaxed">{{ __('Thanks for signing up! Before getting started, verify your email using the link we sent. If you did not receive it, you can request another below.') }}</p>
        </div>
        <div class="flex gap-3 rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-3 text-sm text-brand-forest">
            <svg class="h-5 w-5 shrink-0 text-brand-sage mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <p class="leading-relaxed">{{ __('Creating servers, organizations, and other sensitive actions require a verified email address.') }}</p>
        </div>
    </div>
    @if (session('error'))
        <div class="mb-5 flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <svg class="h-5 w-5 shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ session('error') }}</span>
        </div>
    @endif
    @if (session('status') == 'verification-link-sent')
        <div class="mb-5 flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            <svg class="h-5 w-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span class="font-medium">{{ __('A new verification link has been sent to the email address you provided during registration.') }}</span>
        </div>
    @endif
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-2 border-t border-brand-ink/10">
        <button type="button" wire:click="sendNotification" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-brand-ink font-semibold text-sm text-brand-cream shadow-md shadow-brand-ink/15 hover:bg-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 transition-colors">
            <svg class="h-4 w-4 shrink-0 opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            {{ __('Resend Verification Email') }}
        </button>
        <form method="POST" action="{{ route('logout') }}" class="inline flex justify-center sm:justify-end">
            @csrf
            <button type="submit" class="inline-flex items-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40 rounded-lg px-2 py-1">
                <svg class="h-4 w-4 text-brand-sage" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</div>
