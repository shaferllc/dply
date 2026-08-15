@php
    // Server size is not a billing input — the plan is set by how many servers
    // you run — so the what-if is a single count, not five size buckets.
    $presets = [
        ['label' => 'My fleet', 'hint' => 'Reset to current', 'count' => $this->billingState->serverCount()],
        ['label' => 'Solo dev', 'hint' => '1 server', 'count' => 1],
        ['label' => 'Small team', 'hint' => '3 servers', 'count' => 3],
        ['label' => 'Growing fleet', 'hint' => '7 servers', 'count' => 7],
    ];
    $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);
@endphp

<section class="border-b border-brand-ink/10">
    <div class="border-b border-brand-ink/10 bg-brand-cream/30 px-3 py-3 sm:px-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90">{{ __('What would it cost?') }}</h2>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-4xl font-bold tracking-tight text-brand-ink" x-text="fmt(previewBilledTotal)"></span>
                    <span class="text-base text-brand-moss" x-text="billingPreviewAnnual ? '/yr' : '/mo'"></span>
                </div>
                <p class="mt-2 text-sm text-brand-moss" x-show="!billingPreviewAnnual" x-cloak>
                    <span class="font-semibold text-brand-forest" x-text="fmt(previewMonthlyTotal * 12 * previewAnnualPct / 100)"></span>
                    {{ __('/yr saved on annual billing') }}
                </p>
                <p class="mt-2 text-sm text-brand-moss" x-show="billingPreviewAnnual" x-cloak>
                    <span x-text="fmt(previewBilledTotal / 12)"></span> {{ __('/mo effective') }} ({{ $annualPct }}% {{ __('off monthly') }})
                </p>
            </div>
            <div class="inline-flex items-center gap-1 p-1 rounded-lg border border-brand-ink/10 bg-white shadow-sm">
                <button type="button" @click="billingPreviewAnnual = false" :class="!billingPreviewAnnual ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss'" class="px-4 py-1.5 rounded-md text-xs font-semibold transition">{{ __('Monthly') }}</button>
                <button type="button" @click="billingPreviewAnnual = true" :class="billingPreviewAnnual ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss'" class="px-4 py-1.5 rounded-md text-xs font-semibold transition">{{ __('Yearly') }}</button>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <span class="text-xs font-semibold uppercase tracking-wider text-brand-ink/60 mr-2">{{ __('Quick picks') }}</span>
        @foreach ($presets as $preset)
            <button type="button"
                    @click="previewServerCount = {{ (int) $preset['count'] }}"
                    class="inline-flex flex-col items-start rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors text-left">
                <span class="text-xs font-semibold text-brand-ink">{{ $preset['label'] }}</span>
                <span class="text-2xs text-brand-moss/80">{{ $preset['hint'] }}</span>
            </button>
        @endforeach
        <button type="button"
                @click="previewServerCount = 0"
                class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs text-brand-moss hover:text-brand-ink transition-colors ml-auto">
            {{ __('Reset') }}
        </button>
    </div>

    <div class="px-3 py-2 text-xs text-brand-moss sm:px-4">
        {{ __('Add servers of any size — your plan is set by total server count, not size. Managed products are billed separately.') }}
    </div>

    <div class="px-3 pb-3 sm:px-4">
        <div class="flex items-center gap-4 rounded-lg px-3 py-2 transition-colors hover:bg-brand-cream/30">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-brand-ink">{{ __('Servers') }}</p>
                <p class="text-xs text-brand-moss/80">{{ __('Any size, any provider') }}</p>
            </div>
            <div class="inline-flex items-center gap-1">
                <button type="button"
                        @click="previewServerCount = Math.max(0, previewServerCount - 1)"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                        :disabled="previewServerCount === 0"
                        aria-label="{{ __('Remove a server') }}">
                    <span class="text-lg leading-none">−</span>
                </button>
                <input type="number" min="0" step="1"
                       x-model.number="previewServerCount"
                       aria-label="{{ __('Server count') }}"
                       class="w-14 rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 text-sm text-center tabular-nums focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                <button type="button"
                        @click="previewServerCount = previewServerCount + 1"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors"
                        aria-label="{{ __('Add a server') }}">
                    <span class="text-lg leading-none">+</span>
                </button>
            </div>
        </div>
    </div>

    <div class="border-t border-brand-ink/10 bg-brand-cream/40 px-3 py-3 text-sm sm:px-4">
        <div class="flex items-center justify-between">
            <span class="text-brand-moss">
                {{ __('Plan') }} (<span x-text="previewServerCount"></span> <span x-text="previewServerCount === 1 ? '{{ __('server') }}' : '{{ __('servers') }}'"></span>)
            </span>
            <span class="font-semibold text-brand-ink tabular-nums">
                <span x-text="previewPlan ? previewPlan.label : ''"></span>
                · <span x-text="fmt(previewMonthlyTotal)"></span>
            </span>
        </div>
        <div x-show="billingPreviewAnnual" x-cloak class="mt-1.5 flex items-center justify-between text-brand-forest">
            <span>{{ __('Annual discount') }} ({{ $annualPct }}%)</span>
            <span class="font-semibold tabular-nums" x-text="'−' + fmt(previewMonthlyTotal * 12 * previewAnnualPct / 100)"></span>
        </div>
    </div>
</section>
