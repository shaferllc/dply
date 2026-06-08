<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        full-title="{{ config('app.name') }} – Infrastructure operations, in motion"
        description="Provision, secure, and run servers across any cloud—organization-scoped, audit-ready, and fast. Watch a deploy happen." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }

        /* ---- Ambient motion ---------------------------------------------- */
        @keyframes dply-float {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50%      { transform: translate3d(0, -28px, 0) scale(1.06); }
        }
        @keyframes dply-float-slow {
            0%, 100% { transform: translate3d(0, 0, 0); }
            50%      { transform: translate3d(24px, 18px, 0); }
        }
        @keyframes dply-aurora {
            0%   { transform: translate(-12%, -8%) rotate(0deg); }
            50%  { transform: translate(8%, 10%) rotate(180deg); }
            100% { transform: translate(-12%, -8%) rotate(360deg); }
        }
        @keyframes dply-grid-pan {
            from { background-position: 0 0; }
            to   { background-position: 56px 56px; }
        }
        @keyframes dply-marquee {
            from { transform: translateX(0); }
            to   { transform: translateX(-50%); }
        }
        @keyframes dply-shimmer {
            0%   { transform: translateX(-120%); }
            60%, 100% { transform: translateX(220%); }
        }
        @keyframes dply-blink { 0%, 49% { opacity: 1; } 50%, 100% { opacity: 0; } }
        @keyframes dply-pulse-ring {
            0%   { transform: scale(0.6); opacity: 0.7; }
            100% { transform: scale(2.4); opacity: 0; }
        }
        @keyframes dply-pop {
            0%   { transform: scale(0.4); opacity: 0; }
            60%  { transform: scale(1.15); }
            100% { transform: scale(1); opacity: 1; }
        }

        .dply-orb { will-change: transform; filter: blur(40px); border-radius: 9999px; }
        .dply-aurora-layer {
            position: absolute; inset: -40%;
            background:
                radial-gradient(closest-side, rgba(205,169,66,0.18), transparent),
                radial-gradient(closest-side, rgba(104,132,121,0.16), transparent),
                radial-gradient(closest-side, rgba(50,72,44,0.14), transparent);
            background-position: 20% 30%, 75% 25%, 50% 80%;
            background-repeat: no-repeat;
            background-size: 55% 55%, 45% 45%, 50% 50%;
            animation: dply-aurora 26s linear infinite;
            will-change: transform;
        }
        .dply-grid {
            background-image:
                linear-gradient(to right, rgba(23,26,14,0.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(23,26,14,0.05) 1px, transparent 1px);
            background-size: 56px 56px;
            animation: dply-grid-pan 18s linear infinite;
            mask-image: radial-gradient(ellipse 80% 60% at 50% 30%, #000 35%, transparent 75%);
            -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 30%, #000 35%, transparent 75%);
        }
        html.dark .dply-grid {
            background-image:
                linear-gradient(to right, rgba(238,240,232,0.06) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(238,240,232,0.06) 1px, transparent 1px);
        }

        /* ---- Scroll reveal ----------------------------------------------- */
        .reveal { opacity: 0; transform: translateY(28px); transition: opacity .7s cubic-bezier(.2,.7,.2,1), transform .7s cubic-bezier(.2,.7,.2,1); }
        .reveal.reveal-in { opacity: 1; transform: none; }

        /* ---- Buttons ----------------------------------------------------- */
        .dply-shine { position: relative; overflow: hidden; }
        .dply-shine::after {
            content: ""; position: absolute; top: 0; bottom: 0; width: 40%;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,0.55), transparent);
            transform: translateX(-120%);
        }
        .dply-shine:hover::after { animation: dply-shimmer 1.1s ease; }

        /* ---- Marquee ----------------------------------------------------- */
        .dply-marquee-track { display: flex; width: max-content; animation: dply-marquee 26s linear infinite; }
        .dply-marquee:hover .dply-marquee-track { animation-play-state: paused; }

        /* ---- Bento tilt -------------------------------------------------- */
        .dply-tilt { transform-style: preserve-3d; transition: transform .25s ease, box-shadow .25s ease; will-change: transform; }
        .dply-tilt .dply-glow {
            position: absolute; inset: -1px; border-radius: inherit; opacity: 0; transition: opacity .3s ease;
            background: radial-gradient(220px circle at var(--mx, 50%) var(--my, 50%), rgba(205,169,66,0.18), transparent 65%);
        }
        .dply-tilt:hover .dply-glow { opacity: 1; }

        .dply-cursor { display: inline-block; width: 0.6ch; animation: dply-blink 1s steps(1) infinite; color: var(--color-brand-gold); }
        .dply-step-dot::before {
            content: ""; position: absolute; inset: 0; border-radius: 9999px;
            border: 2px solid var(--color-brand-gold); animation: dply-pulse-ring 1.8s ease-out infinite;
        }
        .dply-pop { animation: dply-pop .4s cubic-bezier(.2,.7,.2,1) both; }

        @media (prefers-reduced-motion: reduce) {
            .dply-orb, .dply-aurora-layer, .dply-grid, .dply-marquee-track,
            .dply-step-dot::before { animation: none !important; }
            .reveal { opacity: 1 !important; transform: none !important; transition: none; }
        }
    </style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink overflow-x-hidden" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    {{-- Animated background --}}
    <div class="fixed inset-0 -z-30 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-20 dply-aurora-layer"></div>
    <div class="fixed inset-0 -z-10 dply-grid"></div>
    <div class="dply-orb fixed -z-10 top-[-6rem] left-[-4rem] h-72 w-72 bg-brand-gold/25" style="animation: dply-float 11s ease-in-out infinite;" aria-hidden="true"></div>
    <div class="dply-orb fixed -z-10 top-1/3 right-[-5rem] h-80 w-80 bg-brand-sage/25" style="animation: dply-float-slow 15s ease-in-out infinite;" aria-hidden="true"></div>
    <div class="dply-orb fixed -z-10 bottom-[-6rem] left-1/4 h-72 w-72 bg-brand-forest/20" style="animation: dply-float 13s ease-in-out infinite;" aria-hidden="true"></div>

    <x-site-header active="home" />

    <main>
        {{-- ============================= HERO ============================= --}}
        <section class="relative px-4 sm:px-6 lg:px-8 pt-16 pb-20 sm:pt-24 lg:pt-28">
            <div class="mx-auto max-w-7xl lg:grid lg:grid-cols-12 lg:gap-12 lg:items-center">
                <div class="lg:col-span-6 text-center lg:text-left">
                    <p class="reveal inline-flex items-center gap-2 rounded-full border border-brand-sage/30 bg-white/60 px-4 py-1.5 text-xs font-semibold tracking-wide text-brand-forest uppercase backdrop-blur-sm">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full rounded-full bg-brand-gold opacity-75 dply-step-dot"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-brand-gold"></span>
                        </span>
                        Cloud · SSH · Edge · Functions
                    </p>

                    <h1 class="reveal mt-8 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl lg:text-[3.4rem] lg:leading-[1.06]" style="transition-delay:.06s">
                        Ship
                        <span class="relative inline-grid align-baseline" aria-hidden="true">
                            <span id="rotator" class="bg-gradient-to-r from-brand-rust via-brand-gold to-brand-sage bg-clip-text text-transparent">deploys</span>
                        </span>
                        <span class="sr-only">deploys</span>
                        <br class="hidden sm:block" />
                        with command-center calm
                    </h1>

                    <p class="reveal mt-6 text-lg sm:text-xl text-brand-moss max-w-xl mx-auto lg:mx-0 leading-relaxed" style="transition-delay:.12s">
                        One console for servers, credentials, and remote execution—scoped to organizations, audit-ready, and built for how serious teams actually operate.
                    </p>

                    <div class="reveal mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4" style="transition-delay:.18s">
                        @auth
                            <a href="{{ route('dashboard') }}" class="dply-shine group w-full sm:w-auto inline-flex justify-center items-center gap-2 px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/30 hover:shadow-xl hover:shadow-brand-gold/40 hover:-translate-y-0.5 transition-all">
                                Open dashboard
                                <span class="transition-transform group-hover:translate-x-0.5">→</span>
                            </a>
                        @else
                            <a href="{{ route('register') }}" class="dply-shine group w-full sm:w-auto inline-flex justify-center items-center gap-2 px-7 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/30 hover:shadow-xl hover:shadow-brand-gold/40 hover:-translate-y-0.5 transition-all">
                                Start free trial
                                <span class="transition-transform group-hover:translate-x-0.5">→</span>
                            </a>
                        @endauth
                        <a href="{{ route('pricing') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-7 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white/70 text-brand-ink text-sm font-semibold hover:border-brand-sage/50 hover:bg-white hover:-translate-y-0.5 transition-all backdrop-blur-sm">
                            View pricing
                        </a>
                    </div>

                    <dl class="reveal mt-12 grid grid-cols-3 gap-6 max-w-md mx-auto lg:mx-0 border-t border-brand-ink/10 pt-8" style="transition-delay:.24s">
                        <div>
                            <dd class="text-2xl font-bold text-brand-ink tabular-nums" data-count="12" data-suffix="+">0</dd>
                            <dt class="mt-1 text-xs font-medium uppercase tracking-wider text-brand-mist">Cloud providers</dt>
                        </div>
                        <div>
                            <dd class="text-2xl font-bold text-brand-ink tabular-nums" data-count="40" data-suffix="s">0</dd>
                            <dt class="mt-1 text-xs font-medium uppercase tracking-wider text-brand-mist">Avg. provision</dt>
                        </div>
                        <div>
                            <dd class="text-2xl font-bold text-brand-ink tabular-nums" data-count="100" data-suffix="%">0</dd>
                            <dt class="mt-1 text-xs font-medium uppercase tracking-wider text-brand-mist">Secrets vaulted</dt>
                        </div>
                    </dl>
                </div>

                {{-- Live terminal --}}
                <div class="lg:col-span-6 mt-16 lg:mt-0">
                    <div class="reveal relative mx-auto w-full max-w-lg" style="transition-delay:.16s">
                        <div class="absolute -inset-4 bg-gradient-to-br from-brand-gold/25 via-brand-sage/20 to-transparent rounded-[2rem] blur-2xl" aria-hidden="true"></div>
                        <div class="dply-tilt relative rounded-2xl border border-brand-ink/10 bg-brand-ink shadow-2xl shadow-brand-forest/20 overflow-hidden">
                            <div class="dply-glow"></div>
                            <div class="flex items-center gap-2 px-4 py-3 border-b border-white/10">
                                <span class="h-3 w-3 rounded-full bg-brand-rust/80"></span>
                                <span class="h-3 w-3 rounded-full bg-brand-gold/80"></span>
                                <span class="h-3 w-3 rounded-full bg-brand-sage/80"></span>
                                <span class="ml-3 text-xs text-brand-mist font-mono">{{ str_replace('https://', '', config('app.url')) }} — deploy</span>
                            </div>
                            <div id="terminal" class="px-5 py-5 font-mono text-[13px] leading-relaxed text-brand-sand min-h-[16rem]" aria-hidden="true"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ===================== PROVIDER MARQUEE ===================== --}}
        <section class="py-8 border-y border-brand-ink/10 bg-white/40 backdrop-blur-sm">
            <p class="text-center text-xs font-semibold uppercase tracking-wider text-brand-mist mb-6">Provision anywhere — one inventory</p>
            <div class="dply-marquee relative overflow-hidden" style="mask-image:linear-gradient(to right,transparent,#000 8%,#000 92%,transparent);-webkit-mask-image:linear-gradient(to right,transparent,#000 8%,#000 92%,transparent);">
                <div class="dply-marquee-track gap-4 pr-4">
                    @php($providers = ['DigitalOcean','Hetzner','AWS', 'Linode','Vultr','Google Cloud','Azure','UpCloud','Oracle'])
                    @foreach (array_merge($providers, $providers) as $p)
                        <span class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/10 bg-white/70 px-5 py-2.5 text-sm font-semibold text-brand-forest whitespace-nowrap">
                            <span class="h-1.5 w-1.5 rounded-full bg-brand-gold"></span>{{ $p }}
                        </span>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ===================== INTERACTIVE BENTO ===================== --}}
        <section class="py-20 sm:py-28 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="reveal max-w-2xl mx-auto text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Everything your platform team expects</h2>
                    <p class="mt-4 text-lg text-brand-moss">Provisioning, access, and day-two operations—without stitching together five tools.</p>
                </div>

                <div class="mt-16 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @php($cards = [
                        ['icon' => 'server-stack', 'span' => 'sm:col-span-2', 'title' => 'Cloud &amp; SSH in one inventory', 'body' => 'Link any provider or bare SSH host. Servers land in the right organization automatically—no manual key handoffs.', 'tone' => 'forest'],
                        ['icon' => 'key', 'span' => '', 'title' => 'Secrets that stay vaulted', 'body' => 'Members use infrastructure without ever copying tokens to a laptop.', 'tone' => 'gold'],
                        ['icon' => 'user-group', 'span' => '', 'title' => 'Organizations &amp; teams', 'body' => 'Invite by email, segment access, align billing to the org.', 'tone' => 'sage'],
                        ['icon' => 'bolt', 'span' => '', 'title' => 'Edge &amp; serverless', 'body' => 'Ship containers and functions to the edge behind the same console.', 'tone' => 'copper'],
                        ['icon' => 'command-line', 'span' => '', 'title' => 'Remote execution', 'body' => 'Run audited one-off commands from the console—no local terminal.', 'tone' => 'forest'],
                    ])
                    @foreach ($cards as $i => $card)
                        <article class="reveal dply-tilt {{ $card['span'] }} group relative rounded-2xl border border-brand-ink/10 bg-white/70 backdrop-blur-sm p-7 sm:p-8 shadow-sm hover:shadow-xl hover:shadow-brand-forest/10 overflow-hidden"
                                 style="transition-delay: {{ $i * 60 }}ms">
                            <div class="dply-glow"></div>
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-{{ $card['tone'] }}/15 text-brand-{{ $card['tone'] }} transition-transform group-hover:scale-110 group-hover:-rotate-6">
                                @switch($card['icon'])
                                    @case('server-stack') <x-heroicon-o-server-stack class="h-6 w-6" /> @break
                                    @case('key') <x-heroicon-o-key class="h-6 w-6" /> @break
                                    @case('user-group') <x-heroicon-o-user-group class="h-6 w-6" /> @break
                                    @case('bolt') <x-heroicon-o-bolt class="h-6 w-6" /> @break
                                    @case('command-line') <x-heroicon-o-command-line class="h-6 w-6" /> @break
                                @endswitch
                            </div>
                            <h3 class="mt-6 text-lg font-semibold text-brand-ink">{!! $card['title'] !!}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{!! $card['body'] !!}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ===================== ANIMATED PIPELINE ===================== --}}
        <section class="py-20 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-5xl rounded-3xl border border-white/10 bg-brand-ink text-brand-cream p-8 sm:p-12 shadow-2xl shadow-brand-forest/20 overflow-hidden relative">
                <div class="absolute inset-0 dply-grid opacity-30" aria-hidden="true"></div>
                <div class="relative">
                    <div class="reveal text-center max-w-xl mx-auto">
                        <h2 class="text-2xl sm:text-3xl font-bold text-brand-cream">From commit to live, watched the whole way</h2>
                        <p class="mt-3 text-brand-sand/80">Every step is timed, audited, and reversible. Hover or scroll—the pipeline runs.</p>
                    </div>

                    <ol id="pipeline" class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @php($steps = [
                            ['t' => 'Provision', 'd' => 'Droplet created · hardened', 'ic' => 'server-stack'],
                            ['t' => 'Secure', 'd' => 'Keys vaulted · firewall set', 'ic' => 'shield-check'],
                            ['t' => 'Build', 'd' => 'Dependencies · assets', 'ic' => 'cube'],
                            ['t' => 'Live', 'd' => 'Routed · health green', 'ic' => 'rocket-launch'],
                        ])
                        @foreach ($steps as $i => $step)
                            <li class="dply-pipe-step relative rounded-2xl border border-white/10 bg-white/5 p-5 transition-all duration-500" data-step="{{ $i }}">
                                <div class="flex items-center gap-3">
                                    <span class="dply-pipe-icon relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-white/15 bg-black/30 text-brand-sand transition-colors">
                                        @switch($step['ic'])
                                            @case('server-stack') <x-heroicon-o-server-stack class="h-5 w-5" /> @break
                                            @case('shield-check') <x-heroicon-o-shield-check class="h-5 w-5" /> @break
                                            @case('cube') <x-heroicon-o-cube class="h-5 w-5" /> @break
                                            @case('rocket-launch') <x-heroicon-o-rocket-launch class="h-5 w-5" /> @break
                                        @endswitch
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold text-brand-cream">{{ $step['t'] }}</p>
                                        <p class="text-xs text-brand-mist">{{ $step['d'] }}</p>
                                    </div>
                                </div>
                                <span class="dply-pipe-check absolute top-4 right-4 text-brand-sage opacity-0 transition-opacity">
                                    <x-heroicon-s-check-circle class="h-5 w-5" />
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </section>

        {{-- ============================= CTA ============================= --}}
        <section class="pb-28 px-4 sm:px-6 lg:px-8">
            <div class="reveal relative max-w-4xl mx-auto rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-white via-brand-cream to-brand-sand/40 px-8 py-16 sm:px-14 sm:py-20 text-center shadow-xl shadow-brand-forest/5 overflow-hidden">
                <div class="dply-orb absolute -top-16 -right-10 h-48 w-48 bg-brand-gold/30" style="animation: dply-float 9s ease-in-out infinite;" aria-hidden="true"></div>
                <div class="relative">
                    <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl">Ready for a calmer operations posture?</h2>
                    <p class="mt-4 text-lg text-brand-moss max-w-xl mx-auto">Spin up an organization, connect your first provider, and run a real trial on infrastructure you already control.</p>
                    @guest
                        <a href="{{ route('register') }}" class="dply-shine mt-10 inline-flex items-center gap-2 px-8 py-3.5 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest hover:-translate-y-0.5 transition-all shadow-md">
                            Start free trial <span>→</span>
                        </a>
                    @else
                        <a href="{{ route('dashboard') }}" class="dply-shine mt-10 inline-flex items-center gap-2 px-8 py-3.5 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest hover:-translate-y-0.5 transition-all shadow-md">
                            Go to dashboard <span>→</span>
                        </a>
                    @endguest
                </div>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts

    <script>
    (() => {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        /* ---- Scroll reveal ---- */
        const revealEls = document.querySelectorAll('.reveal');
        if (reduce || !('IntersectionObserver' in window)) {
            revealEls.forEach((el) => el.classList.add('reveal-in'));
        } else {
            const io = new IntersectionObserver((entries) => {
                entries.forEach((e) => {
                    if (e.isIntersecting) { e.target.classList.add('reveal-in'); io.unobserve(e.target); }
                });
            }, { threshold: 0.15 });
            revealEls.forEach((el) => io.observe(el));
        }

        /* ---- Count-up stats ---- */
        const counters = document.querySelectorAll('[data-count]');
        const runCount = (el) => {
            const target = parseFloat(el.dataset.count);
            const suffix = el.dataset.suffix || '';
            if (reduce) { el.textContent = target + suffix; return; }
            const dur = 1100, start = performance.now();
            const tick = (now) => {
                const p = Math.min((now - start) / dur, 1);
                const eased = 1 - Math.pow(1 - p, 3);
                el.textContent = Math.round(target * eased) + suffix;
                if (p < 1) requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        };
        if ('IntersectionObserver' in window) {
            const cio = new IntersectionObserver((entries) => {
                entries.forEach((e) => { if (e.isIntersecting) { runCount(e.target); cio.unobserve(e.target); } });
            }, { threshold: 0.6 });
            counters.forEach((el) => cio.observe(el));
        } else {
            counters.forEach(runCount);
        }

        /* ---- Rotating headline word ---- */
        const rotator = document.getElementById('rotator');
        if (rotator && !reduce) {
            const words = ['deploys', 'servers', 'secrets', 'functions', 'the edge', 'teams'];
            let i = 0;
            setInterval(() => {
                i = (i + 1) % words.length;
                rotator.style.transition = 'opacity .3s, transform .3s';
                rotator.style.opacity = '0';
                rotator.style.transform = 'translateY(-8px)';
                setTimeout(() => {
                    rotator.textContent = words[i];
                    rotator.style.opacity = '1';
                    rotator.style.transform = 'none';
                }, 300);
            }, 2200);
        }

        /* ---- Terminal typing ---- */
        const term = document.getElementById('terminal');
        if (term) {
            const lines = [
                { txt: '$ dply provision web-1 --region nyc', cls: 'text-brand-cream' },
                { txt: '✓ Droplet created · 2.1s', cls: 'text-brand-sage' },
                { txt: '✓ SSH hardened · keys vaulted', cls: 'text-brand-sage' },
                { txt: '$ dply deploy main', cls: 'text-brand-cream' },
                { txt: '→ build · install · migrate', cls: 'text-brand-mist' },
                { txt: '✓ Live → web-1.' + '{{ str_replace(['https://','http://'], '', config('app.url')) }}'.replace(/^www\./,''), cls: 'text-brand-gold' },
            ];
            if (reduce) {
                term.innerHTML = lines.map((l) => `<div class="${l.cls}">${l.txt}</div>`).join('');
            } else {
                let li = 0;
                const cursor = '<span class="dply-cursor">▋</span>';
                const typeLine = () => {
                    if (li >= lines.length) {
                        setTimeout(() => { term.innerHTML = ''; li = 0; typeLine(); }, 3200);
                        return;
                    }
                    const line = lines[li];
                    const row = document.createElement('div');
                    row.className = line.cls;
                    term.appendChild(row);
                    let ci = 0;
                    const typeChar = () => {
                        row.innerHTML = line.txt.slice(0, ci) + cursor;
                        ci++;
                        if (ci <= line.txt.length) {
                            setTimeout(typeChar, line.txt.startsWith('$') ? 34 : 14);
                        } else {
                            row.innerHTML = line.txt;
                            li++;
                            setTimeout(typeLine, 360);
                        }
                    };
                    typeChar();
                };
                typeLine();
            }
        }

        /* ---- Pipeline run ---- */
        const pipeline = document.getElementById('pipeline');
        if (pipeline) {
            const steps = pipeline.querySelectorAll('.dply-pipe-step');
            const activate = (idx) => {
                const step = steps[idx];
                if (!step) return;
                step.classList.add('dply-pop');
                step.style.borderColor = 'rgba(205,169,66,0.5)';
                step.style.background = 'rgba(205,169,66,0.10)';
                const icon = step.querySelector('.dply-pipe-icon');
                if (icon) { icon.style.background = 'var(--color-brand-gold)'; icon.style.color = 'var(--color-brand-ink)'; }
                const check = step.querySelector('.dply-pipe-check');
                if (check) check.style.opacity = '1';
            };
            const reset = () => steps.forEach((s) => {
                s.classList.remove('dply-pop');
                s.style.borderColor = ''; s.style.background = '';
                const icon = s.querySelector('.dply-pipe-icon');
                if (icon) { icon.style.background = ''; icon.style.color = ''; }
                const check = s.querySelector('.dply-pipe-check');
                if (check) check.style.opacity = '0';
            });
            const run = () => {
                if (reduce) { steps.forEach((_, i) => activate(i)); return; }
                reset();
                steps.forEach((_, i) => setTimeout(() => activate(i), 500 + i * 650));
                setTimeout(run, 500 + steps.length * 650 + 2600);
            };
            if ('IntersectionObserver' in window) {
                let started = false;
                const pio = new IntersectionObserver((entries) => {
                    entries.forEach((e) => { if (e.isIntersecting && !started) { started = true; run(); } });
                }, { threshold: 0.4 });
                pio.observe(pipeline);
            } else { run(); }
        }

        /* ---- Magnetic glow on tilt cards ---- */
        if (!reduce && window.matchMedia('(pointer:fine)').matches) {
            document.querySelectorAll('.dply-tilt').forEach((card) => {
                card.addEventListener('pointermove', (ev) => {
                    const r = card.getBoundingClientRect();
                    const px = (ev.clientX - r.left) / r.width;
                    const py = (ev.clientY - r.top) / r.height;
                    card.style.setProperty('--mx', (px * 100) + '%');
                    card.style.setProperty('--my', (py * 100) + '%');
                    card.style.transform = `perspective(900px) rotateX(${(0.5 - py) * 6}deg) rotateY(${(px - 0.5) * 6}deg)`;
                });
                card.addEventListener('pointerleave', () => { card.style.transform = ''; });
            });
        }
    })();
    </script>
</body>
</html>
