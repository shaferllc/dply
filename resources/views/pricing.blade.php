<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pricing – {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-marketing-nav active="pricing" />

    <main class="flex-1">
        <section class="pt-16 pb-12 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h1 class="text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">Transparent pricing</h1>
                <p class="mt-4 text-lg text-brand-moss">Start free, move to production when your org is ready. No surprise limits on the fundamentals.</p>
            </div>
        </section>

        <div class="flex justify-center mb-12 px-4" x-data="{ annual: false }">
            <div class="inline-flex items-center gap-3 p-1 rounded-xl border border-brand-ink/10 bg-white/70 shadow-sm">
                <button type="button" @click="annual = false" :class="!annual ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Monthly</button>
                <button type="button" @click="annual = true" :class="annual ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Annual</button>
                <span class="text-xs font-semibold text-brand-forest bg-brand-sand/50 px-2.5 py-1 rounded-md mr-1">Save 20%</span>
            </div>
        </div>

        <section class="pb-24 px-4 sm:px-6 lg:px-8">
            <div class="max-w-5xl mx-auto grid gap-8 md:grid-cols-3">
                <div class="relative flex flex-col rounded-2xl border border-brand-ink/10 bg-white/80 backdrop-blur-sm p-8 shadow-sm">
                    <h2 class="text-lg font-semibold text-brand-ink">Starter</h2>
                    <p class="mt-1 text-sm text-brand-moss">For side projects and experiments</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-brand-ink">$0</span>
                        <span class="text-brand-moss">/mo</span>
                    </div>
                    <ul class="mt-8 space-y-4 flex-1">
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Up to 3 servers
                        </li>
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            DigitalOcean &amp; SSH
                        </li>
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Run commands from dashboard
                        </li>
                    </ul>
                    <a href="{{ route('register') }}" class="mt-8 block w-full rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-3 text-center text-sm font-semibold text-brand-ink hover:border-brand-sage/40 transition-colors">Get started</a>
                </div>

                <div class="relative flex flex-col rounded-2xl border-2 border-brand-gold bg-white p-8 shadow-xl shadow-brand-gold/10 ring-1 ring-brand-gold/20 md:-mt-2 md:mb-2">
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-brand-gold px-3 py-1 text-xs font-bold text-brand-ink uppercase tracking-wide">Most popular</div>
                    <h2 class="text-lg font-semibold text-brand-ink">Pro</h2>
                    <p class="mt-1 text-sm text-brand-moss">For growing teams and production</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-brand-ink">$29</span>
                        <span class="text-brand-moss">/mo</span>
                    </div>
                    <ul class="mt-8 space-y-4 flex-1">
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Unlimited servers
                        </li>
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Everything in Starter
                        </li>
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Priority support
                        </li>
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Server setup scripts (coming soon)
                        </li>
                    </ul>
                    <a href="{{ route('register') }}" class="mt-8 block w-full rounded-xl bg-brand-ink px-4 py-3 text-center text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest transition-colors">Get started</a>
                </div>

                <div class="relative flex flex-col rounded-2xl border border-brand-ink/10 bg-white/80 backdrop-blur-sm p-8 shadow-sm">
                    <h2 class="text-lg font-semibold text-brand-ink">Team</h2>
                    <p class="mt-1 text-sm text-brand-moss">For organizations</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-brand-ink">Custom</span>
                    </div>
                    <ul class="mt-8 space-y-4 flex-1">
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Everything in Pro
                        </li>
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Team seats &amp; SSO
                        </li>
                        <li class="flex items-start gap-3 text-sm text-brand-moss">
                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Dedicated support
                        </li>
                    </ul>
                    <a href="mailto:hello@dply.io?subject=Team%20plan" class="mt-8 block w-full rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-3 text-center text-sm font-semibold text-brand-ink hover:border-brand-sage/40 transition-colors">Contact sales</a>
                </div>
            </div>
        </section>

        <section class="border-t border-brand-ink/10 bg-white/60 py-16 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <p class="text-brand-moss leading-relaxed">All plans include secure credential storage, SSH key management, and DigitalOcean integration. You only pay for the cloud resources you use.</p>
            </div>
        </section>
    </main>

    <x-marketing-footer />
</body>
</html>
