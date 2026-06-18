@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $total = $referredUsers->count();
    $converted = $referredUsers->filter(fn ($r) => $r->referral_converted_at !== null)->count();
    $pending = $total - $converted;
@endphp

<div>
    @push('breadcrumbs')
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Referrals'), 'icon' => 'gift'],
        ]" />
    @endpush

    {{-- Hero: positioning + at-a-glance referral stats. --}}
    <x-hero-card
        :eyebrow="__('Rewards')"
        :title="__('Referrals')"
        icon="gift"
        iconSize="md"
    >
        <x-slot:description>
            @if ($bonusCreditCents > 0)
                {{ __('Share your link. When someone signs up and their organization pays for a Pro plan, you get :amount in account credit on your next invoice (:desc).', [
                    'amount' => '$'.number_format($bonusCreditCents / 100, 2),
                    'desc' => $bonusDescription !== '' ? $bonusDescription : __('applied automatically in Stripe'),
                ]) }}
            @else
                {{ __('Share your link. When someone signs up and their organization pays for a Pro plan, we record the referral in your account.') }}
            @endif
        </x-slot:description>

        <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
            <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            {{ __('Back to profile') }}
        </x-outline-link>
        <button
            type="button"
            x-data="{ copied: false }"
            x-on:click="navigator.clipboard.writeText(@js($referralUrl)); copied = true; clearTimeout(window._refCopyT); window._refCopyT = setTimeout(() => copied = false, 2000)"
            class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
        >
            <span x-show="!copied" class="inline-flex items-center gap-2">
                <x-heroicon-o-clipboard-document class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Copy referral link') }}
            </span>
            <span x-show="copied" x-cloak class="inline-flex items-center gap-2">
                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Copied') }}
            </span>
        </button>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-2">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Referred') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $total }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('person|people', $total) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Signed up with your link') }}</p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-brand-sage/30 bg-brand-sage/8' => $converted > 0,
                    'border-brand-ink/10 bg-white' => $converted === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Converted') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $converted }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('reward|rewards', $converted) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Reached Pro') }}</p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-amber-200 bg-amber-50' => $pending > 0,
                    'border-brand-ink/10 bg-white' => $pending === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Pending') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $pending }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('upgrade|upgrades', $pending) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Awaiting Pro') }}</p>
                </div>
            </dl>
        </x-slot:stats>
    </x-hero-card>

    <div class="mt-6 space-y-6">
        {{-- Referral link --}}
        <section class="dply-card overflow-hidden" id="referral-link">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Share') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your referral link') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Send this to anyone evaluating dply. Their sign-up is attributed to you automatically.') }}</p>
                </div>
            </div>
            <div class="p-6 sm:p-7">
                <label for="referral-url" class="block text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('URL') }}</label>
                <div class="mt-1 flex flex-col gap-3 sm:flex-row sm:items-stretch">
                    <input
                        id="referral-url"
                        type="text"
                        readonly
                        value="{{ $referralUrl }}"
                        class="w-full min-w-0 rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    />
                    <button
                        type="button"
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard.writeText(@js($referralUrl)); copied = true; clearTimeout(window._refCopyTInline); window._refCopyTInline = setTimeout(() => copied = false, 2000)"
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <span x-show="!copied" class="inline-flex items-center gap-2">
                            <x-heroicon-o-clipboard-document class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Copy') }}
                        </span>
                        <span x-show="copied" x-cloak class="inline-flex items-center gap-2">
                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Copied') }}
                        </span>
                    </button>
                </div>
            </div>
        </section>

        {{-- Referred users --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-user-group class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Directory') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Referred users') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('People who signed up via your link. Rewards apply when their org upgrades to Pro.') }}</p>
                </div>
                @if ($total > 0)
                    <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $total }}</span>
                @endif
            </div>

            @if ($referredUsers->isEmpty())
                <div class="px-6 py-14 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-moss ring-1 ring-brand-ink/10">
                        <x-heroicon-o-user-plus class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No referred users yet') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('When someone signs up with your link and their organization upgrades to Pro, they show up here with reward status.') }}
                    </p>
                    <a
                        href="#referral-link"
                        class="mt-5 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/30"
                    >
                        <x-heroicon-o-arrow-up class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Copy your link') }}
                    </a>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($referredUsers as $ref)
                        @php
                            $initials = collect(preg_split('/\s+/', trim((string) $ref->name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') ?: mb_substr((string) ($ref->email ?? '?'), 0, 1);
                            $converted = $ref->referral_converted_at !== null;
                        @endphp
                        <li class="flex items-center gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-sand/55 text-xs font-semibold text-brand-forest ring-1 ring-brand-ink/10">
                                {{ strtoupper($initials) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-brand-ink">{{ $ref->name }}</p>
                                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $ref->email }}</p>
                            </div>
                            @if ($converted)
                                <span class="shrink-0 inline-flex items-center gap-1.5 rounded-md border border-brand-sage/30 bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest" title="{{ __('Converted :date', ['date' => $ref->referral_converted_at->timezone($ref->timezone ?: config('app.timezone'))->toFormattedDateString()]) }}">
                                    <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('Reward eligible') }}
                                </span>
                            @else
                                <span class="shrink-0 inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">
                                    <x-heroicon-m-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('Pro pending') }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
