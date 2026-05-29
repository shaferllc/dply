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
        $baseCents = (int) config('subscription.standard.base_cents', 2500);
        $creditCents = (int) config('subscription.standard.included_credit_cents', 1000);
        $capCents = (int) config('subscription.standard.per_server_cap_cents', 4000);
        $annualPct = (int) config('subscription.standard.annual_discount_pct', 20);
        $tiers = config('subscription.standard.tiers', []);
        $freeEntry = filter_var(config('subscription.standard.free_entry_tier', true), FILTER_VALIDATE_BOOLEAN);
        $xsCents = (int) ($tiers['xs'] ?? 0);
        $base = $baseCents / 100;
        $credit = $creditCents / 100;
        $cap = $capCents / 100;
    @endphp

    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header active="pricing" />

    <main class="flex-1"
          x-data="{
              annual: false,
              counts: { xs: 0, s: 0, m: 1, l: 0, xl: 0 },
              tiers: { xs: {{ ($tiers['xs'] ?? 0) / 100 }}, s: {{ ($tiers['s'] ?? 0) / 100 }}, m: {{ ($tiers['m'] ?? 0) / 100 }}, l: {{ ($tiers['l'] ?? 0) / 100 }}, xl: {{ ($tiers['xl'] ?? 0) / 100 }} },
              base: {{ $base }},
              creditValue: {{ $credit }},
              annualPct: {{ $annualPct }},
              freeEntry: {{ $freeEntry ? 'true' : 'false' }},
              get serverCount() {
                  return ['xs','s','m','l','xl'].reduce((sum, k) => sum + (this.counts[k] || 0), 0);
              },
              get serverSubtotal() {
                  return ['xs','s','m','l','xl'].reduce((sum, k) => sum + (this.counts[k] || 0) * this.tiers[k], 0);
              },
              // Free entry tier: base is waived while the only billable unit is
              // a single XS server. Mirrors OrganizationBillingStateComputer.
              get baseDue() {
                  const onlyOneXs = this.freeEntry && this.serverCount === 1 && (this.counts.xs || 0) === 1;
                  return onlyOneXs ? 0 : this.base;
              },
              get appliedCredit() {
                  return Math.min(this.creditValue, this.serverSubtotal);
              },
              get monthlyTotal() {
                  return Math.max(0, this.baseDue + this.serverSubtotal - this.appliedCredit);
              },
              get billedTotal() {
                  return this.annual
                      ? Math.round(this.monthlyTotal * 12 * (1 - this.annualPct / 100))
                      : this.monthlyTotal;
              },
              fmt(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2); }
          }">
        <section class="pt-16 pb-6 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h1 class="text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">One plan. Pay for what you run.</h1>
                <p class="mt-4 text-lg text-brand-moss">${{ number_format($base, 0) }}/mo base, plus per-server pricing that scales with server size. Same fee whether you're on DigitalOcean, Hetzner, AWS, or your own SSH box.</p>
                @if ($freeEntry)
                    <p class="mt-3 inline-flex items-center gap-2 rounded-full bg-brand-sand/40 px-4 py-1.5 text-sm font-semibold text-brand-forest">
                        <x-heroicon-s-sparkles class="h-4 w-4" aria-hidden="true" />
                        Your first small server has no base fee — start from just ${{ number_format($xsCents / 100, 0) }}/mo.
                    </p>
                @endif
            </div>
        </section>

        {{-- Real-world example fleets — concrete dollar amounts visitors can map to themselves --}}
        @php
            $exampleFleets = [
                ['label' => 'Indie dev', 'desc' => '1 small server', 'monthly_cents' => ($freeEntry ? 0 : $baseCents) + ($tiers['xs'] ?? 0)],
                ['label' => 'Side project', 'desc' => '1 mid-size server', 'monthly_cents' => $baseCents + ($tiers['m'] ?? 0)],
                ['label' => 'Small team', 'desc' => '3 mid servers', 'monthly_cents' => $baseCents + 3 * ($tiers['m'] ?? 0)],
                ['label' => 'Growing fleet', 'desc' => '5 mid + 2 big', 'monthly_cents' => $baseCents + 5 * ($tiers['m'] ?? 0) + 2 * ($tiers['l'] ?? 0)],
            ];
        @endphp
        <section class="pb-10 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-5xl grid grid-cols-2 md:grid-cols-4 gap-3">
                @foreach ($exampleFleets as $fleet)
                    <div class="rounded-xl border border-brand-ink/10 bg-white/70 p-4 text-center">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ $fleet['label'] }}</p>
                        <p class="mt-1 text-2xl font-bold tracking-tight text-brand-ink">${{ number_format($fleet['monthly_cents'] / 100, 0) }}<span class="text-sm font-normal text-brand-moss">/mo</span></p>
                        <p class="mt-1 text-xs text-brand-moss/80">{{ $fleet['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex justify-center mb-12 px-4">
            <div class="inline-flex items-center gap-3 p-1 rounded-xl border border-brand-ink/10 bg-white/70 shadow-sm">
                <button type="button" @click="annual = false" :class="!annual ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Monthly</button>
                <button type="button" @click="annual = true" :class="annual ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Annual</button>
                <span class="text-xs font-semibold text-brand-forest bg-brand-sand/50 px-2.5 py-1 rounded-md mr-1">Save {{ $annualPct }}%</span>
            </div>
        </div>

        <section class="pb-16 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto grid w-full max-w-5xl gap-8 md:grid-cols-2">
                {{-- Standard plan --}}
                <div class="relative flex flex-col rounded-2xl border-2 border-brand-gold bg-white p-8 shadow-xl shadow-brand-gold/10 ring-1 ring-brand-gold/20">
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-brand-gold px-3 py-1 text-xs font-bold text-brand-ink uppercase tracking-wide">Self-serve</div>
                    <h2 class="text-lg font-semibold text-brand-ink">Standard</h2>
                    <p class="mt-1 text-sm text-brand-moss">For teams shipping real apps to real servers.</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-brand-ink"
                              x-text="annual
                                ? '$' + Math.round({{ $base }} * 12 * (1 - {{ $annualPct }} / 100))
                                : '${{ number_format($base, 0) }}'">${{ number_format($base, 0) }}</span>
                        <span class="text-brand-moss" x-text="annual ? '/yr' : '/mo'">/mo</span>
                        <span class="ml-2 text-sm text-brand-moss">+ per-server</span>
                    </div>
                    <p class="mt-2 text-sm text-brand-moss">Add servers and dply detects the size automatically. A typical first server runs $5–10/mo on top of the base.</p>

                    <ul class="mt-6 space-y-3 text-sm text-brand-moss">
                        @if ($freeEntry)
                            <li class="flex items-start gap-3">
                                <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span><span class="font-semibold text-brand-ink">First small server has no base fee</span> — pay only the ${{ number_format($xsCents / 100, 0) }}/mo server tier until you scale up.</span>
                            </li>
                        @endif
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            Every feature dply ships. No tier gating.
                        </li>
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            Servers detected and billed by size — never more than ${{ number_format($cap, 0) }}/server.
                        </li>
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            Unlimited sites, deploys, and team members.
                        </li>
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            Public REST API + <code class="text-xs bg-brand-sand/60 px-1.5 py-0.5 rounded">@dply/cli</code> — script every deploy.
                        </li>
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            14-day free trial. No credit card to start.
                        </li>
                    </ul>

                    <details class="mt-6 group">
                        <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink/80 hover:text-brand-ink flex items-center gap-2">
                            <span>Per-server pricing</span>
                            <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </summary>
                        <div class="mt-3 overflow-hidden rounded-lg border border-brand-ink/10">
                            <table class="w-full text-sm">
                                <thead class="bg-brand-cream/60 text-brand-ink/70">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold">Tier</th>
                                        <th class="px-3 py-2 text-left font-semibold">Typical specs</th>
                                        <th class="px-3 py-2 text-right font-semibold">Per day</th>
                                        <th class="px-3 py-2 text-right font-semibold">Per month</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5 text-brand-moss">
                                    @foreach (['xs' => '≤1 vCPU · ≤2 GB', 's' => '2 vCPU · ≤4 GB', 'm' => '≤4 vCPU · ≤8 GB', 'l' => '≤8 vCPU · ≤16 GB', 'xl' => 'Above L'] as $key => $spec)
                                        @php $tierMonthly = ($tiers[$key] ?? 0) / 100; @endphp
                                        <tr>
                                            <td class="px-3 py-2 font-semibold uppercase">{{ $key }}</td>
                                            <td class="px-3 py-2">{{ $spec }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums">${{ number_format($tierMonthly / 30, 2) }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums">${{ number_format($tierMonthly, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="px-3 py-2 text-xs text-brand-moss/80 bg-brand-cream/30">Billed per server-day. New servers are free for their first day.</p>
                        </div>
                    </details>

                    <a href="{{ route('register') }}" class="mt-8 block w-full rounded-xl bg-brand-ink px-4 py-3 text-center text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest transition-colors">Start 14-day free trial</a>
                </div>

                {{-- Enterprise --}}
                <div class="relative flex flex-col rounded-2xl border border-brand-ink/10 bg-white/80 backdrop-blur-sm p-8 shadow-sm">
                    <h2 class="text-lg font-semibold text-brand-ink">Enterprise</h2>
                    <p class="mt-1 text-sm text-brand-moss">For procurement-led rollouts and larger fleets.</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-brand-ink">Talk to us</span>
                    </div>
                    <p class="mt-2 text-sm text-brand-moss">Volume pricing, custom contract, SLAs.</p>

                    <ul class="mt-6 space-y-3 text-sm text-brand-moss flex-1">
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            Everything in Standard
                        </li>
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            Volume pricing on per-server fees
                        </li>
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            SSO, audit logs, custom MSA
                        </li>
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                            Dedicated support, SLA, rollout planning
                        </li>
                    </ul>

                    <a href="mailto:hello@dply.io?subject=Dply%20enterprise%20pricing" class="mt-8 block w-full rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-3 text-center text-sm font-semibold text-brand-ink hover:border-brand-sage/40 transition-colors">Talk to sales</a>
                </div>
            </div>
        </section>

        @feature('global.billing_enabled')
            @include('partials.pricing-calculator', [
                'baseCents' => $baseCents,
                'creditCents' => $creditCents,
                'annualPct' => $annualPct,
                'tiers' => $tiers,
            ])
        @else
            <section class="border-t border-brand-ink/10 bg-white/60 py-16 px-4 sm:px-6 lg:px-8">
                <div class="max-w-2xl mx-auto text-center">
                    <h2 class="text-2xl font-semibold text-brand-ink">Pricing TBA</h2>
                    <p class="mt-3 text-brand-moss leading-relaxed">
                        dply is in invite-only beta. The pricing tiers above describe what
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
                    'q' => 'Why per-server and not per-seat or per-site?',
                    'a' => 'dply\'s cost scales with the work it does for each server: agent traffic, metrics ingestion, deploys, scheduler ticks, audit. Per-server pricing matches that cost honestly. Seats are unlimited; sites are unlimited.',
                ],
                [
                    'q' => 'What if I run on Hetzner / cheap providers — am I overpaying?',
                    'a' => 'No. dply prices its own work, not your cloud invoice. A Hetzner customer and an AWS customer pay the same dply fee for the same spec. If your infra is cheap, that\'s a great deal for you — dply is the platform fee, your provider bill is separate.',
                ],
                [
                    'q' => 'Can I cancel anytime?',
                    'a' => 'Yes. Cancel any time through Stripe\'s billing portal. We don\'t hold your data hostage — your servers keep running on your provider; dply just stops managing them. Re-subscribe later and reconnect anytime.',
                ],
                [
                    'q' => 'What about a free tier?',
                    'a' => $freeEntry
                        ? 'Your first small (XS) server carries no organization base fee — you pay only the $2/mo server tier for it. The $15 base kicks in once you add a second server, run a larger server, or use a managed product (Cloud, Edge, or Serverless). Every account also gets a 14-day trial with no credit card required.'
                        : 'There isn\'t one, but every account gets a 14-day trial with no credit card required — full product, real servers, real deploys. After 14 days, deploys pause unless you add a card. You decide when you\'re ready to pay.',
                ],
                [
                    'q' => 'What if I add a server mid-cycle?',
                    'a' => 'Stripe immediately bills the prorated amount for the rest of your cycle. No surprise renewal bills. New servers under one day old don\'t count yet — short-lived test boxes are free.',
                ],
                [
                    'q' => 'How do you handle Enterprise / large fleets?',
                    'a' => 'Talk to us. Above ~20 servers most teams want a contract, volume pricing, SSO, and an MSA. We do that as a custom Enterprise plan rather than a higher self-serve tier.',
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
