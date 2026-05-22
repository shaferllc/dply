<div class="dply-card overflow-hidden">
    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
        <div class="lg:col-span-4">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('How billing works') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('A quick reference for what you\'re paying for.') }}</p>
        </div>
        <div class="lg:col-span-8">
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
</div>
