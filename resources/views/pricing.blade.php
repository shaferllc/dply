<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <title>Pricing – {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    @php
        // Flat plans metered by BYO server COUNT. Mirrors SubscriptionPlanResolver.
        $plans = collect(config('subscription.standard.plans', []))
            ->map(fn ($plan, $key) => [
                'key' => $key,
                'label' => $plan['label'] ?? ucfirst($key),
                'price' => (int) ($plan['price_cents'] ?? 0) / 100,
                'max' => $plan['max_servers'] ?? null,
                'max_sites' => $plan['max_sites'] ?? null,
            ])
            ->values();

        $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);
        $serverless = (int) config('subscription.standard.serverless_cents', 200) / 100;
        $cloud = (int) config('subscription.standard.cloud_cents', 500) / 100;
        $edge = (int) config('subscription.standard.edge_cents', 200) / 100;

        $freePlan = $plans->firstWhere('key', 'free');
        $starterPlan = $plans->firstWhere('key', 'starter');

        // Marketing copy for each plan card.
        $planBlurbs = [
            'free' => 'For your first project. Connect one server and ship.',
            'starter' => 'For indie devs and small side projects.',
            'pro' => 'For teams running a real fleet.',
            'business' => 'For agencies and large fleets — no server cap.',
        ];
        $highlightKey = 'pro';
    @endphp

    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header active="pricing" />

    <main class="flex-1"
          x-data="{
              annual: false,
              servers: 3,
              edge: 0,
              cloud: 0,
              serverless: 0,
              plans: {{ Illuminate\Support\Js::from($plans) }},
              edgePrice: {{ $edge }},
              cloudPrice: {{ $cloud }},
              serverlessPrice: {{ $serverless }},
              annualPct: {{ $annualPct }},
              get plan() {
                  return this.plans.find(p => p.max === null || this.servers <= p.max)
                      || this.plans[this.plans.length - 1];
              },
              get managedTotal() {
                  return this.edge * this.edgePrice + this.cloud * this.cloudPrice + this.serverless * this.serverlessPrice;
              },
              // Managed products require a paid plan. If the fleet alone would
              // land on Free but managed units are selected, bill the cheapest
              // paid plan instead. Mirrors the require_paid_plan rule.
              get needsPaidForManaged() {
                  return this.plan.price === 0 && this.managedTotal > 0;
              },
              get effectivePlan() {
                  if (this.needsPaidForManaged) {
                      return this.plans.find(p => p.price > 0) || this.plan;
                  }
                  return this.plan;
              },
              get planPrice() {
                  return this.effectivePlan.price;
              },
              get monthlyTotal() {
                  return this.planPrice + this.managedTotal;
              },
              get billedTotal() {
                  return this.annual
                      ? Math.round(this.monthlyTotal * 12 * (1 - this.annualPct / 100))
                      : this.monthlyTotal;
              },
              fmt(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2); },
              fmt0(n) { return '$' + Math.round(n); }
          }">
        <section class="pt-16 pb-6 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h1 class="text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">Simple plans, priced by server count.</h1>
                <p class="mt-4 text-lg text-brand-moss">Start free with one server. Flat monthly plans as your fleet grows — same fee whether you run on DigitalOcean, Hetzner, AWS, or your own SSH box. You always pay your provider for the hardware; dply is just the platform fee.</p>
                <p class="mt-3 inline-flex items-center gap-2 rounded-full bg-brand-sand/40 px-4 py-1.5 text-sm font-semibold text-brand-forest">
                    <x-heroicon-s-sparkles class="h-4 w-4" aria-hidden="true" />
                    Your first server is free, forever. No credit card to start.
                </p>
            </div>
        </section>

        <div class="flex justify-center mb-10 px-4">
            <div class="inline-flex items-center gap-3 p-1 rounded-xl border border-brand-ink/10 bg-white/70 shadow-sm">
                <button type="button" @click="annual = false" :class="!annual ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Monthly</button>
                <button type="button" @click="annual = true" :class="annual ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Annual</button>
                <span class="text-xs font-semibold text-brand-forest bg-brand-sand/50 px-2.5 py-1 rounded-md mr-1">Save {{ $annualPct }}%</span>
            </div>
        </div>

        {{-- Plan cards --}}
        <section class="pb-12 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto grid w-full max-w-6xl gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($plans as $plan)
                    @php
                        $isHighlight = $plan['key'] === $highlightKey;
                        $ceiling = $plan['max'] === null
                            ? 'Unlimited servers'
                            : ($plan['max'] === 1 ? '1 server' : 'Up to ' . $plan['max'] . ' servers');
                        $siteCeiling = $plan['max_sites'] === null
                            ? 'Unlimited sites'
                            : ($plan['max_sites'] === 1 ? '1 site' : 'Up to ' . $plan['max_sites'] . ' sites');
                    @endphp
                    <div @class([
                        'relative flex flex-col rounded-2xl p-8 transition',
                        'border-2 border-brand-gold bg-white shadow-xl shadow-brand-gold/10 ring-1 ring-brand-gold/20' => $isHighlight,
                        'border border-brand-ink/10 bg-white/80 backdrop-blur-sm shadow-sm' => ! $isHighlight,
                    ])>
                        @if ($isHighlight)
                            <div class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-brand-gold px-3 py-1 text-xs font-bold text-brand-ink uppercase tracking-wide">Most popular</div>
                        @endif
                        <h2 class="text-lg font-semibold text-brand-ink">{{ $plan['label'] }}</h2>
                        <p class="mt-1 text-sm text-brand-moss min-h-[2.5rem]">{{ $planBlurbs[$plan['key']] ?? '' }}</p>
                        <div class="mt-5 flex items-baseline gap-1">
                            @if ($plan['price'] > 0)
                                <span class="text-4xl font-bold text-brand-ink"
                                      x-text="annual ? fmt0({{ $plan['price'] }} * 12 * (1 - {{ $annualPct }} / 100)) : '{{ '$' . number_format($plan['price'], 0) }}'">{{ '$' . number_format($plan['price'], 0) }}</span>
                                <span class="text-brand-moss" x-text="annual ? '/yr' : '/mo'">/mo</span>
                            @else
                                <span class="text-4xl font-bold text-brand-ink">$0</span>
                                <span class="text-brand-moss">/mo</span>
                            @endif
                        </div>
                        <p class="mt-4 text-sm font-semibold text-brand-ink">{{ $ceiling }}</p>
                        <ul class="mt-5 space-y-3 text-sm text-brand-moss flex-1">
                            <li class="flex items-start gap-2.5">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ $siteCeiling }}, unlimited deploys &amp; team members
                            </li>
                            <li class="flex items-start gap-2.5">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                Every feature — no tier gating
                            </li>
                            <li class="flex items-start gap-2.5">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                Public REST API + <code class="text-xs bg-brand-sand/60 px-1.5 py-0.5 rounded">@dply/cli</code>
                            </li>
                            @if ($plan['price'] > 0)
                                <li class="flex items-start gap-2.5">
                                    <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    Add managed Edge, Cloud &amp; Serverless à la carte
                                </li>
                            @else
                                <li class="flex items-start gap-2.5">
                                    <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                    Upgrade any time as you add servers
                                </li>
                            @endif
                        </ul>
                        <a href="{{ route('register') }}" @class([
                            'mt-8 block w-full rounded-xl px-4 py-3 text-center text-sm font-semibold transition-colors',
                            'bg-brand-ink text-brand-cream shadow-md hover:bg-brand-forest' => $isHighlight,
                            'border-2 border-brand-ink/15 bg-white text-brand-ink hover:border-brand-sage/40' => ! $isHighlight,
                        ])>
                            {{ $plan['price'] > 0 ? 'Start 14-day free trial' : 'Start free' }}
                        </a>
                    </div>
                @endforeach
            </div>
            <p class="mx-auto mt-6 max-w-2xl text-center text-sm text-brand-moss">
                Every paid plan starts with a 14-day free trial — no credit card to begin. Need more than a self-serve plan?
                <a href="mailto:hello@dply.io?subject=Dply%20enterprise%20pricing" class="font-semibold text-brand-ink underline underline-offset-2 hover:text-brand-sage">Talk to sales</a>
                about Enterprise: volume pricing, SSO, audit logs, and a custom MSA.
            </p>
        </section>

        {{-- Managed products — à la carte on top of any paid plan --}}
        @php
            $managedProducts = [
                ['name' => 'dply Edge', 'price' => $edge, 'unit' => 'per site / mo', 'desc' => 'Static & SSG sites on a global CDN. Plus metered delivery usage.', 'icon' => 'heroicon-o-globe-alt'],
                ['name' => 'dply Cloud', 'price' => $cloud, 'unit' => 'per app / mo', 'desc' => 'Managed long-running PHP & Rails containers.', 'icon' => 'heroicon-o-cloud'],
                ['name' => 'Serverless', 'price' => $serverless, 'unit' => 'per function / mo', 'desc' => 'Deploy functions to Lambda, Workers & more.', 'icon' => 'heroicon-o-bolt'],
            ];
        @endphp
        <section class="pb-12 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-5xl">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-brand-ink">Managed products, à la carte</h2>
                    <p class="mt-2 text-brand-moss">Stack first-party managed hosting on top of any paid plan. Billed per live unit — no separate base fee.</p>
                </div>
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    @foreach ($managedProducts as $product)
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6">
                            <x-dynamic-component :component="$product['icon']" class="h-7 w-7 text-brand-gold" aria-hidden="true" />
                            <h3 class="mt-3 text-base font-semibold text-brand-ink">{{ $product['name'] }}</h3>
                            <div class="mt-2 flex items-baseline gap-1">
                                <span class="text-2xl font-bold text-brand-ink">${{ number_format($product['price'], 0) }}</span>
                                <span class="text-sm text-brand-moss">{{ $product['unit'] }}</span>
                            </div>
                            <p class="mt-2 text-sm text-brand-moss">{{ $product['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-center text-xs text-brand-moss/80">Managed products require a paid plan (Starter or higher).</p>
            </div>
        </section>

        @feature('global.billing_enabled')
            @include('partials.pricing-calculator')
        @else
            <section class="border-t border-brand-ink/10 bg-white/60 py-16 px-4 sm:px-6 lg:px-8">
                <div class="max-w-2xl mx-auto text-center">
                    <h2 class="text-2xl font-semibold text-brand-ink">Pricing TBA</h2>
                    <p class="mt-3 text-brand-moss leading-relaxed">
                        dply is in invite-only beta. The plans above describe what
                        we plan to charge once billing turns on. While we're in beta there
                        is no charge, and there will be at least 30 days' notice before
                        billing begins for any account.
                    </p>
                </div>
            </section>
        @endfeature

        {{-- FAQ --}}
        @php
            $faqs = [
                [
                    'q' => 'Why per-server count and not per-seat?',
                    'a' => 'dply\'s cost scales with the work it does for each server: agent traffic, metrics ingestion, deploys, scheduler ticks, audit. Counting servers matches that cost honestly and keeps pricing predictable. Team seats are always unlimited, and each plan includes a generous site allowance that grows as you move up.',
                ],
                [
                    'q' => 'What if I run on Hetzner / cheap providers — am I overpaying?',
                    'a' => 'No. dply prices its own work, not your cloud invoice. A Hetzner customer and an AWS customer pay the same dply plan for the same number of servers. If your infra is cheap, that\'s a great deal for you — dply is the platform fee, your provider bill is separate.',
                ],
                [
                    'q' => 'Is there really a free tier?',
                    'a' => 'Yes. Your first server is free forever on the Free plan — full product, real deploys, no credit card. You only move to a paid plan when you add a second server or use a managed product (Cloud, Edge, or Serverless).',
                ],
                [
                    'q' => 'Can I cancel anytime?',
                    'a' => 'Yes. Cancel any time through Stripe\'s billing portal. We don\'t hold your data hostage — your servers keep running on your provider; dply just stops managing them. Re-subscribe and reconnect later anytime.',
                ],
                [
                    'q' => 'What happens when I cross a plan ceiling?',
                    'a' => 'dply moves you to the next plan automatically and Stripe prorates the difference for the rest of your cycle. Servers under one day old don\'t count toward your total, so short-lived test boxes are free.',
                ],
                [
                    'q' => 'How does managed Edge / Cloud / Serverless billing work?',
                    'a' => 'Those are first-party managed products on dply-owned infra, so they bill à la carte per live unit on top of any paid plan: Edge $' . number_format($edge, 0) . '/site (plus metered delivery), Cloud $' . number_format($cloud, 0) . '/app, Serverless $' . number_format($serverless, 0) . '/function. They require a paid plan.',
                ],
                [
                    'q' => 'How do you handle Enterprise / large fleets?',
                    'a' => 'Talk to us. Above the Business plan most teams want a contract, volume pricing, SSO, and an MSA. We do that as a custom Enterprise plan rather than a higher self-serve tier.',
                ],
                [
                    'q' => 'Is there an API or CLI?',
                    'a' => 'Yes — both are included on every plan, no add-on fee. The Edge REST API has a public OpenAPI 3 spec at /openapi/edge.json. The dply CLI ships as @dply/cli on npm and uses OAuth device flow to authenticate. Org-scoped tokens have granular abilities so CI pipelines stay narrowly permissioned.',
                ],
            ];
        @endphp
        <section class="border-t border-brand-ink/10 bg-white/60 py-16 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl">
                <h2 class="text-2xl font-bold text-center text-brand-ink">Frequently asked</h2>
                <div class="mt-8 space-y-2">
                    @foreach ($faqs as $i => $faq)
                        <details class="group rounded-xl border border-brand-ink/10 bg-white/80 px-5 py-4">
                            <summary class="cursor-pointer list-none flex items-center justify-between gap-4">
                                <span class="font-semibold text-brand-ink">{{ $faq['q'] }}</span>
                                <svg class="h-5 w-5 shrink-0 text-brand-moss transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                            </summary>
                            <p class="mt-3 text-sm text-brand-moss leading-relaxed">{{ $faq['a'] }}</p>
                        </details>
                    @endforeach
                </div>
                <p class="mt-10 text-sm text-brand-moss text-center">
                    Still curious? <a href="mailto:hello@dply.io" class="font-semibold text-brand-ink hover:text-brand-sage underline underline-offset-2">Email us</a>.
                </p>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
