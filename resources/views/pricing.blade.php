{{-- Design 3 — Two cards: Free, and one paid card with a tier switcher inside. --}}
<x-marketing-layout title="Pricing" description="Flat monthly pricing by server count. Your first server is free forever." active="pricing">
    @php
        $free = collect($plans)->firstWhere('price', 0);
        $paid = collect($plans)->where('price', '>', 0)->values();
    @endphp

    <section class="px-4 pt-16 pb-20 sm:px-6 lg:px-8"
             x-data="{
                i: {{ $paid->search(fn ($p) => $p['key'] === $highlight) ?: 0 }},
                annual: false,
                paid: {{ Illuminate\Support\Js::from($paid) }},
                get plan() { return this.paid[this.i] },
                get price() { return this.annual ? Math.round(this.plan.price * 12 * {{ (100 - $annual_pct) / 100 }}) : this.plan.price },
             }">
        <div class="mx-auto max-w-3xl text-center">
            <h1 class="text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">{{ __('Start free. Grow flat.') }}</h1>
            <p class="mt-4 text-lg text-brand-moss">{{ __('Your first server costs nothing, forever. After that it is one flat fee by fleet size — never per seat, per site, or per deploy.') }}</p>
        </div>

        <div class="mx-auto mt-12 grid max-w-4xl items-start gap-6 lg:grid-cols-2">
            {{-- Free --}}
            <div class="rounded-3xl border border-brand-ink/10 bg-white/80 p-8">
                <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __($free['label'] ?? 'Free') }}</h2>
                <p class="mt-3 flex items-baseline gap-1">
                    <span class="text-5xl font-bold tracking-tight text-brand-ink">$0</span>
                    <span class="text-sm text-brand-moss">{{ __('forever') }}</span>
                </p>
                <p class="mt-3 text-sm text-brand-moss">{{ __('One server, one site, the whole product. No credit card, no trial clock.') }}</p>
                <a href="{{ auth()->check() ? route('dashboard') : route('register') }}" class="mt-6 flex w-full items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-5 py-3 text-sm font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40">
                    {{ auth()->check() ? __('Open dashboard') : __('Create free account') }}
                </a>
            </div>

            {{-- Paid --}}
            <div class="relative rounded-3xl border-2 border-brand-gold/40 bg-white p-8 shadow-xl shadow-brand-ink/10">
                <span class="absolute -top-3 left-8 rounded-full bg-brand-gold px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-ink">{{ __('For teams') }}</span>

                <div class="flex flex-wrap gap-1 rounded-xl bg-brand-sand/40 p-1">
                    @foreach ($paid as $i => $plan)
                        <button type="button" @click="i = {{ $i }}" :class="i === {{ $i }} ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss'" class="flex-1 rounded-lg px-3 py-1.5 text-xs font-semibold transition">{{ __($plan['label']) }}</button>
                    @endforeach
                </div>

                <p class="mt-6 flex items-baseline gap-1">
                    <span class="text-5xl font-bold tracking-tight text-brand-ink tabular-nums">$<span x-text="price"></span></span>
                    <span class="text-sm text-brand-moss" x-text="annual ? '{{ __('/ year') }}' : '{{ __('/ month') }}'"></span>
                </p>
                <p class="mt-2 text-sm text-brand-moss">
                    <span x-text="plan.servers === null ? '{{ __('Unlimited servers') }}' : plan.servers + ' {{ __('servers') }}'"></span>
                    ·
                    <span x-text="plan.sites === null ? '{{ __('unlimited sites') }}' : plan.sites + ' {{ __('sites') }}'"></span>
                </p>

                <label class="mt-4 inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-brand-moss">
                    <input type="checkbox" x-model="annual" class="rounded border-brand-ink/20 text-brand-gold focus:ring-brand-gold/40" />
                    {{ __('Bill annually and save :pct%', ['pct' => $annual_pct]) }}
                </label>

                <ul class="mt-6 space-y-2 border-t border-brand-ink/10 pt-6">
                    @foreach ($included as $item)
                        <li class="flex items-start gap-2 text-sm text-brand-moss">
                            <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __($item) }}
                        </li>
                    @endforeach
                </ul>

                <a href="{{ auth()->check() ? route('dashboard') : route('register') }}" class="mt-7 flex w-full items-center justify-center rounded-xl bg-brand-ink px-5 py-3 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                    {{ __('Start free trial') }}
                </a>
            </div>
        </div>

        <div class="mx-auto max-w-4xl">
            @include('pricing._beta-note')
            @php $liveAddons = collect($addons)->filter(fn ($a) => \Laravel\Pennant\Feature::active($a['flag'])); @endphp
            @if ($liveAddons->isNotEmpty())
                <p class="mt-8 text-center text-sm text-brand-moss">
                    {{ __('Optional add-ons:') }}
                    {{ $liveAddons->map(fn ($a) => __($a['name']).' from $'.number_format($a['price'], 0).' '.__($a['unit']))->join(' · ') }}
                </p>
            @endif
        </div>
    </section>

    @include('pricing._faq')
</x-marketing-layout>
