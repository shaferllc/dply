@php
    $presets = [
        ['label' => 'Solo dev', 'hint' => '1 server', 'servers' => 1, 'edge' => 0, 'cloud' => 0, 'serverless' => 0],
        ['label' => 'Side project', 'hint' => '2 servers + 1 Edge site', 'servers' => 2, 'edge' => 1, 'cloud' => 0, 'serverless' => 0],
        ['label' => 'Small team', 'hint' => '5 servers + 2 Cloud apps', 'servers' => 5, 'edge' => 0, 'cloud' => 2, 'serverless' => 0],
        ['label' => 'Growing fleet', 'hint' => '12 servers, mixed managed', 'servers' => 12, 'edge' => 3, 'cloud' => 2, 'serverless' => 4],
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
                    <p class="mt-2 text-sm text-brand-moss">
                        You're on the <span class="font-semibold text-brand-ink" x-text="effectivePlan.label"></span> plan.
                        <span x-show="needsPaidForManaged" x-cloak class="text-brand-forest">Managed products need a paid plan, so Free is bumped up.</span>
                    </p>
                    <p class="mt-1 text-sm text-brand-moss" x-show="!annual" x-cloak>
                        <span class="font-semibold text-brand-forest" x-text="fmt(monthlyTotal * 12 * annualPct / 100)"></span>
                        / yr saved if you switch to annual billing.
                    </p>
                    <p class="mt-1 text-sm text-brand-moss" x-show="annual" x-cloak>
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
                        @click="servers = {{ $preset['servers'] }}; edge = {{ $preset['edge'] }}; cloud = {{ $preset['cloud'] }}; serverless = {{ $preset['serverless'] }}"
                        class="inline-flex flex-col items-start rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors text-left">
                    <span class="text-xs font-semibold text-brand-ink">{{ $preset['label'] }}</span>
                    <span class="text-[10px] text-brand-moss/80">{{ $preset['hint'] }}</span>
                </button>
            @endforeach
            <button type="button"
                    @click="servers = 1; edge = 0; cloud = 0; serverless = 0"
                    class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs text-brand-moss hover:text-brand-ink transition-colors ml-auto">
                Reset
            </button>
        </div>

        {{-- Stepper rows --}}
        <div class="px-8 py-6 space-y-2">
            {{-- Servers drive the plan --}}
            <div class="flex items-center gap-4 rounded-lg bg-brand-cream/30 px-3 py-3">
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold text-brand-ink">BYO servers</div>
                    <div class="text-xs text-brand-moss">Sets your plan — <span x-text="effectivePlan.label"></span></div>
                </div>
                <div class="text-sm font-semibold text-brand-ink tabular-nums w-24 text-right" x-text="planPrice > 0 ? fmt(planPrice) + '/mo' : 'Free'"></div>
                <div class="inline-flex items-center gap-1">
                    <button type="button"
                            @click="servers = Math.max(1, servers - 1)"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :disabled="servers <= 1">
                        <span class="text-lg leading-none">−</span>
                    </button>
                    <input type="number" min="1" step="1"
                           x-model.number="servers"
                           class="w-14 rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 text-sm text-center tabular-nums focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                    <button type="button"
                            @click="servers = servers + 1"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors">
                        <span class="text-lg leading-none">+</span>
                    </button>
                </div>
            </div>

            {{-- Managed products --}}
            @foreach ([
                ['key' => 'edge', 'label' => 'dply Edge sites', 'priceVar' => 'edgePrice', 'unit' => 'per site'],
                ['key' => 'cloud', 'label' => 'dply Cloud apps', 'priceVar' => 'cloudPrice', 'unit' => 'per app'],
                ['key' => 'serverless', 'label' => 'Serverless functions', 'priceVar' => 'serverlessPrice', 'unit' => 'per function'],
            ] as $row)
                <div class="flex items-center gap-4 rounded-lg hover:bg-brand-cream/30 transition-colors px-3 py-2">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm text-brand-ink">{{ $row['label'] }}</div>
                        <div class="text-xs text-brand-moss"><span x-text="fmt({{ $row['priceVar'] }})"></span> {{ $row['unit'] }} / mo</div>
                    </div>
                    <div class="text-sm font-semibold text-brand-ink tabular-nums w-24 text-right" x-text="fmt(({{ $row['key'] }} || 0) * {{ $row['priceVar'] }})"></div>
                    <div class="inline-flex items-center gap-1">
                        <button type="button"
                                @click="{{ $row['key'] }} = Math.max(0, ({{ $row['key'] }} || 0) - 1)"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="({{ $row['key'] }} || 0) === 0">
                            <span class="text-lg leading-none">−</span>
                        </button>
                        <input type="number" min="0" step="1"
                               x-model.number="{{ $row['key'] }}"
                               class="w-14 rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 text-sm text-center tabular-nums focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                        <button type="button"
                                @click="{{ $row['key'] }} = ({{ $row['key'] }} || 0) + 1"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-brand-ink/15 bg-white text-brand-ink hover:border-brand-gold/40 hover:bg-brand-cream/40 transition-colors">
                            <span class="text-lg leading-none">+</span>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Breakdown footer --}}
        <div class="px-8 py-5 bg-brand-cream/40 border-t border-brand-ink/10 text-sm">
            <div class="flex items-center justify-between">
                <span class="text-brand-moss"><span x-text="effectivePlan.label"></span> plan (<span x-text="servers"></span> <span x-text="servers === 1 ? 'server' : 'servers'"></span>)</span>
                <span class="tabular-nums font-semibold text-brand-ink" x-text="planPrice > 0 ? fmt(planPrice) : 'Free'"></span>
            </div>
            <div class="flex items-center justify-between mt-1.5">
                <span class="text-brand-moss">Managed products</span>
                <span class="font-semibold text-brand-ink tabular-nums" x-text="fmt(managedTotal)"></span>
            </div>
            <div x-show="annual" x-cloak class="flex items-center justify-between mt-1.5 text-brand-forest">
                <span>Annual discount ({{ $annualPct }}%)</span>
                <span class="font-semibold tabular-nums" x-text="'−' + fmt(monthlyTotal * 12 * annualPct / 100)"></span>
            </div>
            <p class="mt-3 text-xs text-brand-moss/80">Plus metered Edge delivery usage where applicable. Servers under one day old aren't counted.</p>
        </div>
    </div>
</section>
