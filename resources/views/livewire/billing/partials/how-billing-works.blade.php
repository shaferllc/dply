<div class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Billing') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('How billing works') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('A quick reference for what you\'re paying for.') }}</p>
        </div>
    </div>
    <div class="px-6 py-6 sm:px-7">
        <dl class="space-y-4 text-sm">
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('You pay per server-day') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('dply auto-classifies every server into one of five size tiers (XS through XL) by its vCPU + RAM. Each tier has a per-day rate; you\'re billed only for the days a server is actually connected — same rate no matter which cloud you run on. The monthly figures on this page are just 30 server-days.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">
                    {{ trans_choice('{0} No grace window|{1} :days-day grace window for new servers|[2,*] :days-day grace window for new servers', (int) config('subscription.standard.min_billable_age_days', 1), ['days' => (int) config('subscription.standard.min_billable_age_days', 1)]) }}
                </dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('A freshly-connected server doesn\'t count toward your bill until it\'s been up past the grace window. Spin up, test, tear down — no charge.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Changes are billed immediately') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('When you add a server, Stripe immediately bills the prorated amount for the rest of your current billing cycle — no surprise renewal totals. Removing a server credits the unused portion to your next invoice.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Yearly saves 20%') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('Switch to yearly billing via Stripe\'s portal and the base + every per-server fee drops by 20%.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Capped at $40 per server') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('Even an XL-tier server costs no more than $40/mo regardless of how big it is.') }}
                </dd>
            </div>
        </dl>
    </div>
</div>
