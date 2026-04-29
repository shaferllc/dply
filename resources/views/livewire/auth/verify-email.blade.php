<div>
    <div class="mb-6 space-y-4">
        <div class="flex gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm text-brand-moss">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-sage shadow-sm ring-1 ring-brand-ink/5" aria-hidden="true">
                <x-heroicon-o-envelope class="h-5 w-5" />
            </span>
            <p class="leading-relaxed">{{ __('Thanks for signing up! Before getting started, verify your email using the link we sent. If you did not receive it, you can request another below.') }}</p>
        </div>
        <div class="flex gap-3 rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-3 text-sm text-brand-forest">
            <x-heroicon-o-shield-check class="mt-0.5 h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
            <p class="leading-relaxed">{{ __('Creating servers, organizations, and other sensitive actions require a verified email address.') }}</p>
        </div>
    </div>
    @if (session('error'))
        <div class="mb-5 flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <x-heroicon-o-exclamation-circle class="h-5 w-5 shrink-0 text-red-500" aria-hidden="true" />
            <span>{{ session('error') }}</span>
        </div>
    @endif
    @if (session('status') == 'verification-link-sent')
        <div class="mb-5 flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            <x-heroicon-o-check class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true" />
            <span class="font-medium">{{ __('A new verification link has been sent to the email address you provided during registration.') }}</span>
        </div>
    @endif
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-2 border-t border-brand-ink/10">
        <button type="button" wire:click="sendNotification" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-brand-ink font-semibold text-sm text-brand-cream shadow-md shadow-brand-ink/15 hover:bg-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 transition-colors">
            <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Resend Verification Email') }}
        </button>
        <form method="POST" action="{{ route('logout') }}" class="inline flex justify-center sm:justify-end">
            @csrf
            <button type="submit" class="inline-flex items-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40 rounded-lg px-2 py-1">
                <x-heroicon-o-arrow-left-end-on-rectangle class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</div>
