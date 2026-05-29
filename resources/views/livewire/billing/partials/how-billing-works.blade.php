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
                <dt class="font-semibold text-brand-ink">{{ __('One flat plan by server count') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('Your plan is chosen automatically by how many servers dply manages — not their size. Your first server is free; from there it\'s Starter, Pro, then unlimited Business. Run any size box on any cloud for the same flat price.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">
                    {{ trans_choice('{0} No grace window|{1} :days-day grace window for new servers|[2,*] :days-day grace window for new servers', (int) config('subscription.standard.min_billable_age_days', 1), ['days' => (int) config('subscription.standard.min_billable_age_days', 1)]) }}
                </dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('A freshly-connected server doesn\'t count toward your plan tier until it\'s been up past the grace window. Spin up, test, tear down — no charge.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Plan changes are billed immediately') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('When growing your fleet moves you to a higher plan, Stripe immediately bills the prorated difference for the rest of your cycle — no surprise renewal totals. Dropping back down credits the unused portion to your next invoice.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Managed products bill on top') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('dply Cloud apps, Edge sites, and serverless functions run on dply-owned infrastructure, so they\'re billed a la carte per unit on top of your plan — even on the Free plan.') }}
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-brand-ink">{{ __('Yearly saves 20%') }}</dt>
                <dd class="mt-1 text-brand-moss">
                    {{ __('Switch to yearly billing via Stripe\'s portal and your plan fee drops by 20%.') }}
                </dd>
            </div>
        </dl>
    </div>
</div>
