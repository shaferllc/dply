<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} – Enterprise infrastructure operations</title>
    <meta name="description" content="Connect your cloud, govern credentials, and run commands across servers—with organizations, teams, and audit-ready workflows.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(ellipse_100%_80%_at_50%_-30%,rgba(205,169,66,0.08),transparent_55%)]"></div>

    <x-site-header active="home" />

    <main>
        {{-- Hero --}}
        <section class="relative pt-16 pb-20 sm:pt-24 sm:pb-28 lg:pt-28 lg:pb-32 px-4 sm:px-6 lg:px-8 overflow-hidden">
            <div class="max-w-6xl mx-auto">
                <div class="lg:grid lg:grid-cols-12 lg:gap-12 lg:items-center">
                    <div class="lg:col-span-6 text-center lg:text-left">
                        <p class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-white/60 px-4 py-1.5 text-xs font-semibold tracking-wide text-brand-forest uppercase">
                            <span class="h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                            Cloud · SSH · Teams
                        </p>
                        <h1 class="mt-8 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl lg:text-[3.25rem] lg:leading-[1.08]">
                            Operate infrastructure with
                            <span class="relative whitespace-nowrap">
                                <span class="relative z-10 text-brand-forest">command-center</span>
                                <span class="absolute bottom-1 left-0 right-0 h-3 bg-brand-gold/35 -rotate-1 rounded-sm -z-0" aria-hidden="true"></span>
                            </span>
                            clarity
                        </h1>
                        <p class="mt-6 text-lg sm:text-xl text-brand-moss max-w-xl mx-auto lg:mx-0 leading-relaxed">
                            One console for servers, credentials, and remote execution—scoped to organizations, ready for scale, and designed for how serious teams actually work.
                        </p>
                        <div class="mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4">
                            @auth
                                <a
                                    href="{{ route('dashboard') }}"
                                    class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors"
                                >Open dashboard</a>
                            @else
                                <a
                                    href="{{ route('register') }}"
                                    class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors"
                                >Start trial</a>
                            @endauth
                            <a
                                href="{{ route('pricing') }}"
                                class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white/70 text-brand-ink text-sm font-semibold hover:border-brand-sage/40 hover:bg-white transition-colors"
                            >View pricing</a>
                        </div>
                        <p class="mt-5 text-center lg:text-left">
                            <a href="{{ route('features') }}" class="text-sm font-semibold text-brand-sage hover:text-brand-forest transition-colors">Full platform tour →</a>
                        </p>
                        <dl class="mt-12 grid grid-cols-3 gap-6 max-w-md mx-auto lg:mx-0 border-t border-brand-ink/10 pt-10">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-brand-mist">Model</dt>
                                <dd class="mt-1 text-sm font-semibold text-brand-ink">Org-scoped</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-brand-mist">Access</dt>
                                <dd class="mt-1 text-sm font-semibold text-brand-ink">Role-based</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-brand-mist">Secrets</dt>
                                <dd class="mt-1 text-sm font-semibold text-brand-ink">Never exposed</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="lg:col-span-6 mt-16 lg:mt-0 flex justify-center lg:justify-end">
                        <div class="relative w-full max-w-md lg:max-w-none">
                            <div class="absolute -inset-4 bg-gradient-to-br from-brand-gold/20 via-brand-sage/15 to-transparent rounded-[2rem] blur-2xl opacity-80" aria-hidden="true"></div>
                            <div class="relative rounded-3xl border border-brand-ink/10 bg-white/80 backdrop-blur-sm p-8 sm:p-10 shadow-xl shadow-brand-forest/5">
                                <img
                                    src="{{ asset('images/dply-logo.svg') }}"
                                    alt="{{ config('app.name') }}"
                                    class="w-48 sm:w-56 mx-auto drop-shadow-sm"
                                    width="224"
                                    height="254"
                                />
                                <p class="mt-8 text-center text-sm text-brand-moss leading-relaxed">
                                    Your mark for disciplined deploys—olive, gold, and sage tones signal stability and precision across every customer touchpoint.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Trust strip --}}
        <section class="border-y border-brand-ink/10 bg-brand-ink text-brand-cream py-10 px-4 sm:px-6 lg:px-8">
            <div class="max-w-6xl mx-auto flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">
                <p class="text-sm font-medium text-brand-sand/90 max-w-md">
                    Built for engineering leaders who need predictable access patterns, clearer deploy workflows, and flat organization pricing.
                </p>
                <ul class="flex flex-wrap gap-3 justify-center lg:justify-end">
                    @foreach (['Credential vaulting', 'Team boundaries', 'Provider linking', 'Remote execution'] as $chip)
                        <li class="inline-flex items-center rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-medium text-brand-sand">
                            {{ $chip }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- Bento features --}}
        <section class="py-20 sm:py-28 px-4 sm:px-6 lg:px-8">
            <div class="max-w-6xl mx-auto">
                <div class="max-w-2xl mx-auto text-center lg:mx-0 lg:text-left">
                    <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Everything your platform team expects</h2>
                    <p class="mt-4 text-lg text-brand-moss">Provisioning, access, and day-two operations—without stitching together five different tools.</p>
                </div>
                <div class="mt-16 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <article class="sm:col-span-2 rounded-2xl border border-brand-ink/10 bg-white/70 p-8 sm:p-10 shadow-sm hover:shadow-md transition-shadow">
                        <div class="flex items-start gap-4">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold text-brand-ink">Cloud &amp; SSH in one inventory</h3>
                                <p class="mt-3 text-brand-moss leading-relaxed">Link DigitalOcean or any SSH host. Servers land in the right organization automatically—no manual key handoffs.</p>
                            </div>
                        </div>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-gradient-to-b from-brand-sand/40 to-white/80 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-gold/25 text-brand-rust">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Secrets that stay vaulted</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Members use infrastructure without copying tokens to laptops.</p>
                    </article>
                    <article class="rounded-2xl border border-brand-ink/10 bg-white/70 p-8 shadow-sm">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-sage">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </div>
                        <h3 class="mt-6 text-lg font-semibold text-brand-ink">Organizations &amp; teams</h3>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">Invite by email, segment access, align billing to the org.</p>
                    </article>
                    <article class="sm:col-span-2 rounded-2xl border border-brand-ink/10 bg-brand-ink text-brand-cream p-8 sm:p-10 shadow-lg">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-6">
                            <div class="flex-1">
                                <h3 class="text-xl font-semibold text-brand-cream">Run commands from the console</h3>
                                <p class="mt-3 text-brand-sand/85 leading-relaxed">Execute one-off commands remotely when you need a fast fix—without opening a local terminal or distributing SSH config.</p>
                            </div>
                            <div class="shrink-0 rounded-xl border border-white/15 bg-black/30 px-4 py-3 font-mono text-xs text-brand-sand/90 w-full sm:w-auto sm:min-w-[220px]">
                                <div><span class="text-brand-gold">$</span> ssh deploy@web-1</div>
                                <div class="mt-2 text-brand-mist"># Routed via {{ config('app.name') }}</div>
                                <div class="mt-3 text-brand-sage">→ Session authorized · audited</div>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        {{-- CTA --}}
        <section class="pb-24 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-white via-brand-cream to-brand-sand/30 px-8 py-16 sm:px-14 sm:py-20 text-center shadow-lg shadow-brand-forest/5">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Ready for a calmer operations posture?</h2>
                <p class="mt-4 text-lg text-brand-moss max-w-xl mx-auto">Spin up an organization, connect your first provider, and run a real trial on infrastructure you already control before moving to Pro.</p>
                @guest
                    <a
                        href="{{ route('register') }}"
                        class="mt-10 inline-flex items-center px-8 py-3.5 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest transition-colors shadow-md"
                    >Start trial</a>
                @else
                    <a
                        href="{{ route('dashboard') }}"
                        class="mt-10 inline-flex items-center px-8 py-3.5 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest transition-colors shadow-md"
                    >Go to dashboard</a>
                @endguest
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
