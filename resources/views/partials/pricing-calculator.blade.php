@php
    $tierSpecs = [
        'xs' => '≤1 vCPU · ≤2 GB',
        's' => '2 vCPU · ≤4 GB',
        'm' => '≤4 vCPU · ≤8 GB',
        'l' => '≤8 vCPU · ≤16 GB',
        'xl' => 'Above L',
    ];
    $presets = [
        ['label' => 'Solo dev', 'hint' => '1 small server', 'counts' => ['xs' => 1, 's' => 0, 'm' => 0, 'l' => 0, 'xl' => 0]],
        ['label' => 'Side project', 'hint' => '1 modest server', 'counts' => ['xs' => 0, 's' => 0, 'm' => 1, 'l' => 0, 'xl' => 0]],
        ['label' => 'Small team', 'hint' => '3 mid-size servers', 'counts' => ['xs' => 0, 's' => 0, 'm' => 3, 'l' => 0, 'xl' => 0]],
        ['label' => 'Growing fleet', 'hint' => '5 mid + 2 big servers', 'counts' => ['xs' => 0, 's' => 0, 'm' => 5, 'l' => 2, 'xl' => 0]],
    ];
@endphp

<section class="pb-16 px-4 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-5xl rounded-2xl border border-brand-ink/10 bg-white/80 backdrop-blur-sm shadow-sm overflow-hidden">
        {{-- HERO: total at the top --}}
        <div class="px-8 py-6 bg-brand-cream/40 border-b border-brand-ink/10">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-gold/90">Estimate your bill</h2>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-5xl font-bold tracking-tight text-brand-ink" x-text="fmt(billedTotal)"></span>
                        <span class="text-lg text-brand-moss" x-text="annual ? '/yr' : '/mo'"></span>
                    </div>
                    <p class="mt-2 text-sm text-brand-moss" x-show="!annual" x-cloak>
                        <span class="font-semibold text-brand-forest" x-text="fmt(monthlyTotal * 12 * annualPct / 100)"></span>
                        / yr saved if you switch to annual billing.
                    </p>
                    <p class="mt-2 text-sm text-brand-moss" x-show="annual" x-cloak>
                        <span x-text="fmt(billedTotal / 12)"></span> / mo effective ({{ $annualPct }}% off monthly).
                    </p>
                </div>
                <div class="inline-flex items-center gap-1 p-1 rounded-lg border border-brand-ink/10 bg-white shadow-sm">
                    <button type="button" @click="annual = false" :class="!annual ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss'" class="px-4 py-1.5 rounded-md text-xs font-semibold transition">Monthly</button>
                    <button type="button" @click="annual = true" :class="annual ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss'" class="px-4 py-1.5 rounded-md text-xs font-semibold transition">Yearly</button>
                </div>
            </div>
        </div>

        {{-- Presets --}}
        <div class="px-8 py-4 border-b border-brand-ink/10 flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-brand-ink/60 mr-2">Quick picks</span>
            @foreach ($presets as $preset)
                <button type="button"
                        @click="counts = {{ json_encode($preset['counts']) }}"
                        class="inline-flex flex-col items-start rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors text-left">
                    <span class="text-xs font-semibold text-brand-ink">{{ $preset['label'] }}</span>
                    <span class="text-[10px] text-brand-moss/80">{{ $preset['hint'] }}</span>
                </button>
            @endforeach
            <button type="button"
                    @click="counts = { xs: 0, s: 0, m: 0, l: 0, xl: 0 }"
                    class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs text-brand-moss hover:text-brand-ink transition-colors ml-auto">
                Reset
            </button>
        </div>

        {{-- Stepper rows --}}
        <div class="px-8 py-6 space-y-2">
            @foreach ($tierSpecs as $key => $spec)
                <div class="flex items-center gap-4 rounded-lg hover:bg-brand-cream/30 transition-colors px-3 py-2">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center min-w-[2.5rem] rounded-md bg-brand-sand/30 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-brand-ink">{{ $key }}</span>
                            <span class="text-sm text-brand-ink">{{ $spec }}</span>
                        </div>
                    </div>
                    <div class="tabular-nums w-24 text-right leading-tight">
                        <div class="text-sm text-brand-ink" x-text="fmt(tiers['{{ $key }}'] / 30) + '/day'"></div>
                        <div class="text-xs text-brand-moss" x-text="fmt(tiers['{{ $key }}']) + '/mo'"></div>
                    </div>
                    <div class="inline-flex items-center gap-1">
                        <button type="button"
                                @click="counts['{{ $key }}'] = Math.max(0, (counts['{{ $key }}'] || 0) - 1)"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="(counts['{{ $key }}'] || 0) === 0">
                            <span class="text-lg leading-none">−</span>
                        </button>
                        <input type="number" min="0" step="1"
                               x-model.number="counts['{{ $key }}']"
                               class="w-14 rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 text-sm text-center tabular-nums focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                        <button type="button"
                                @click="counts['{{ $key }}'] = (counts['{{ $key }}'] || 0) + 1"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors">
                            <span class="text-lg leading-none">+</span>
                        </button>
                    </div>
                    <div class="text-sm font-semibold text-brand-ink tabular-nums w-20 text-right" x-text="fmt((counts['{{ $key }}'] || 0) * tiers['{{ $key }}'])"></div>
                </div>
            @endforeach
        </div>

        {{-- Breakdown footer --}}
        <div class="px-8 py-5 bg-brand-cream/40 border-t border-brand-ink/10 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-brand-moss">Organization base</span>
                <span class="font-semibold text-brand-ink tabular-nums" x-text="fmt(base)"></span>
            </div>
            <div class="flex items-center justify-between mt-1.5">
                <span class="text-brand-moss">Server fees (<span x-text="['xs','s','m','l','xl'].reduce((n,k) => n + (counts[k]||0), 0)"></span> servers)</span>
                <span class="font-semibold text-brand-ink tabular-nums" x-text="fmt(serverSubtotal)"></span>
            </div>
            <div x-show="annual" x-cloak class="flex items-center justify-between mt-1.5 text-brand-forest">
                <span>Annual discount ({{ $annualPct }}%)</span>
                <span class="font-semibold tabular-nums" x-text="'−' + fmt(monthlyTotal * 12 * annualPct / 100)"></span>
            </div>
        </div>
    </div>
</section>
