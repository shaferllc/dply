<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('profile.edit'), 'icon' => 'user-circle'],
        ['label' => __('Referrals'), 'icon' => 'gift'],
    ]" />

    <div class="space-y-8">
        <div class="dply-card overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your referral link') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
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
                <div id="referral-link" class="lg:col-span-8 space-y-4 scroll-mt-8">
                    <label for="referral-url" class="block text-sm font-medium text-brand-ink">{{ __('URL') }}</label>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <input
                            id="referral-url"
                            type="text"
                            readonly
                            value="{{ $referralUrl }}"
                            class="w-full min-w-0 rounded-xl border border-brand-mist shadow-sm focus:border-brand-forest focus:ring-brand-forest px-3 py-2.5 text-sm font-mono text-brand-ink bg-white"
                        />
                        <button
                            type="button"
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-transparent bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest transition-colors"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(@js($referralUrl)); copied = true; clearTimeout(window._refCopyT); window._refCopyT = setTimeout(() => copied = false, 2000)"
                        >
                            <x-heroicon-o-clipboard-document class="h-4 w-4 text-brand-cream/90" aria-hidden="true" />
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak class="text-brand-cream">{{ __('Copied') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="dply-card overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Referred users') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('People who signed up with your link appear here. Rewards apply when their organization upgrades to Pro.') }}
                    </p>
                </div>
                <div class="lg:col-span-8">
                    @if ($referredUsers->isEmpty())
                        <div class="relative overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-sand/10">
                            <div class="absolute inset-0 bg-mesh-brand opacity-[0.07]" aria-hidden="true"></div>
                            <div class="relative flex flex-col items-center px-6 py-14 sm:py-16 text-center">
                                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                                    <x-heroicon-o-user-plus class="h-8 w-8 text-brand-moss" aria-hidden="true" />
                                </div>
                                <h3 class="mt-6 text-base font-semibold text-brand-ink">{{ __('No referred users yet') }}</h3>
                                <p class="mt-3 max-w-md text-sm leading-relaxed text-brand-moss">
                                    {{ __('When someone signs up with your link and their organization upgrades to Pro, they show up here with reward status.') }}
                                </p>
                                <a
                                    href="#referral-link"
                                    class="mt-8 inline-flex items-center gap-2 rounded-xl border border-brand-ink/12 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30 hover:border-brand-ink/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
                                >
                                    <x-heroicon-o-arrow-up class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    {{ __('Go to your referral link') }}
                                </a>
                            </div>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden">
                            @foreach ($referredUsers as $ref)
                                <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-white px-4 py-3 text-sm">
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
                </div>
            </div>
        </div>
    </div>
</div>
