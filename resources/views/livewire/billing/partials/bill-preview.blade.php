@php
    $tierSpecs = [
        'xs' => '≤1 vCPU · ≤2 GB',
        's' => '2 vCPU · ≤4 GB',
        'm' => '≤4 vCPU · ≤8 GB',
        'l' => '≤8 vCPU · ≤16 GB',
        'xl' => 'Above L',
    ];
    $presets = [
        ['label' => 'My fleet', 'hint' => 'Reset to current', 'counts' => collect($this->billingState->tierQuantities)->all()],
        ['label' => 'Solo dev', 'hint' => '1 small', 'counts' => ['xs' => 1, 's' => 0, 'm' => 0, 'l' => 0, 'xl' => 0]],
        ['label' => 'Small team', 'hint' => '3 mid', 'counts' => ['xs' => 0, 's' => 0, 'm' => 3, 'l' => 0, 'xl' => 0]],
        ['label' => 'Growing fleet', 'hint' => '5 mid + 2 big', 'counts' => ['xs' => 0, 's' => 0, 'm' => 5, 'l' => 2, 'xl' => 0]],
    ];
    $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);
@endphp

<div class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 p-6 sm:p-8 bg-brand-cream/30">
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

    <div class="px-6 sm:px-8 py-4 border-b border-brand-ink/10 flex flex-wrap items-center gap-2">
        <span class="text-xs font-semibold uppercase tracking-wider text-brand-ink/60 mr-2">{{ __('Quick picks') }}</span>
        @foreach ($presets as $preset)
            <button type="button"
                    @click="previewCounts = {{ json_encode($preset['counts']) }}"
                    class="inline-flex flex-col items-start rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors text-left">
                <span class="text-xs font-semibold text-brand-ink">{{ $preset['label'] }}</span>
                <span class="text-[10px] text-brand-moss/80">{{ $preset['hint'] }}</span>
            </button>
        @endforeach
        <button type="button"
                @click="previewCounts = { xs: 0, s: 0, m: 0, l: 0, xl: 0 }"
                class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs text-brand-moss hover:text-brand-ink transition-colors ml-auto">
            {{ __('Reset') }}
        </button>
    </div>

    <div class="px-6 sm:px-8 py-4 text-xs text-brand-moss">
        {{ __('Add servers of any size — your plan is set by total server count, not size. Managed products are billed separately.') }}
    </div>

    <div class="px-6 sm:px-8 pb-6 space-y-2">
        @foreach ($tierSpecs as $key => $spec)
            <div class="flex items-center gap-4 rounded-lg hover:bg-brand-cream/30 transition-colors px-3 py-2">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center min-w-[2.5rem] rounded-md bg-brand-sand/30 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-brand-ink">{{ $key }}</span>
                        <span class="text-sm text-brand-ink">{{ $spec }}</span>
                    </div>
                </div>
                <div class="inline-flex items-center gap-1">
                    <button type="button"
                            @click="previewCounts['{{ $key }}'] = Math.max(0, (previewCounts['{{ $key }}'] || 0) - 1)"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :disabled="(previewCounts['{{ $key }}'] || 0) === 0">
                        <span class="text-lg leading-none">−</span>
                    </button>
                    <input type="number" min="0" step="1"
                           x-model.number="previewCounts['{{ $key }}']"
                           class="w-14 rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 text-sm text-center tabular-nums focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                    <button type="button"
                            @click="previewCounts['{{ $key }}'] = (previewCounts['{{ $key }}'] || 0) + 1"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors">
                        <span class="text-lg leading-none">+</span>
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    <div class="px-6 sm:px-8 py-5 bg-brand-cream/40 border-t border-brand-ink/10 text-sm">
        <div class="flex items-center justify-between">
            <span class="text-brand-moss">
                {{ __('Plan') }} (<span x-text="previewServerCount"></span> <span x-text="previewServerCount === 1 ? '{{ __('server') }}' : '{{ __('servers') }}'"></span>)
            </span>
            <span class="font-semibold text-brand-ink tabular-nums">
                <span x-text="previewPlan ? previewPlan.label : ''"></span>
                · <span x-text="fmt(previewMonthlyTotal)"></span>
            </span>
        </div>
        <div x-show="billingPreviewAnnual" x-cloak class="flex items-center justify-between mt-1.5 text-brand-forest">
            <span>{{ __('Annual discount') }} ({{ $annualPct }}%)</span>
            <span class="font-semibold tabular-nums" x-text="'−' + fmt(previewMonthlyTotal * 12 * previewAnnualPct / 100)"></span>
        </div>
    </div>
</div>
