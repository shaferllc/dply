<div>
    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.edit') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Profile') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Referrals') }}</li>
        </ol>
    </nav>

    <header class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Referrals') }}</h1>
            <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
                @if ($bonusCreditCents > 0)
                    {{ __('Share your link. When someone registers and their organization pays for a Pro plan, you receive :amount account credit toward your next invoice (:desc).', [
                        'amount' => '$'.number_format($bonusCreditCents / 100, 2),
                        'desc' => $bonusDescription !== '' ? $bonusDescription : __('applied automatically in Stripe'),
                    ]) }}
                @else
                    {{ __('Share your link. When someone registers and their organization pays for a Pro plan, we record the referral in your account.') }}
                @endif
            </p>
        </div>
        <a href="{{ route('settings.profile') }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Settings overview') }}</a>
    </header>

    <section class="dply-card overflow-hidden mb-8">
        <div class="px-5 py-4 border-b border-brand-ink/10 bg-brand-cream/50 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Your referral link') }}</h2>
        </div>
        <div class="p-6 sm:p-8 space-y-4">
            <label for="referral-url" class="block text-sm font-medium text-brand-ink">{{ __('URL') }}</label>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input
                    id="referral-url"
                    type="text"
                    readonly
                    value="{{ $referralUrl }}"
                    class="w-full min-w-0 rounded-xl border border-brand-ink/15 bg-brand-sand/30 px-3 py-2.5 text-sm font-mono text-brand-ink shadow-sm"
                />
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 transition-colors"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(@js($referralUrl)); copied = true; clearTimeout(window._refCopyT); window._refCopyT = setTimeout(() => copied = false, 2000)"
                >
                    <svg class="h-4 w-4 text-brand-moss" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied" x-cloak class="text-green-800">{{ __('Copied') }}</span>
                </button>
            </div>
        </div>
    </section>

    <section class="dply-card overflow-hidden relative">
        <div class="absolute top-4 right-5 text-brand-sage/40" aria-hidden="true">
            <svg class="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1c0 1.384.56 2.635 1.464 3.544M15 10a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </div>
        <div class="px-5 py-4 border-b border-brand-ink/10 bg-brand-cream/50">
            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Referred users') }}</h2>
        </div>
        @if ($referredUsers->isEmpty())
            <div class="px-6 py-14 text-center sm:text-left sm:px-8">
                <h3 class="text-base font-medium text-brand-ink">{{ __('No referred users yet') }}</h3>
                <p class="mt-2 text-sm text-brand-moss max-w-lg">{{ __('Share your referral link above. When people sign up and upgrade an organization to Pro, they will appear here.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($referredUsers as $ref)
                    <li class="px-5 py-4 sm:px-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-brand-ink truncate">{{ $ref->name }}</p>
                            <p class="text-sm text-brand-moss truncate">{{ $ref->email }}</p>
                        </div>
                        <div class="shrink-0 text-sm">
                            @if ($ref->referral_converted_at)
                                <span class="inline-flex rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-900 ring-1 ring-inset ring-green-200">
                                    {{ __('Reward eligible') }} · {{ $ref->referral_converted_at->timezone($ref->timezone ?: config('app.timezone'))->format('M j, Y') }}
                                </span>
                            @else
                                <span class="text-brand-mist">{{ __('Signed up — Pro upgrade pending') }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
