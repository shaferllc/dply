@extends('layouts.marketing')

@section('title', config('app.name').' – Functions across your clouds')

@section('meta_description', 'dply Serverless: deploy and track functions on AWS, Cloudflare, Netlify, Vercel, and more—Bearer API, webhooks, and idempotent pipelines.')

@section('content')
    <section class="relative pt-16 pb-20 sm:pt-20 sm:pb-28 lg:pt-28 lg:pb-32 px-4 sm:px-6 lg:px-8 overflow-hidden">
        <div class="max-w-6xl mx-auto">
            <div class="lg:grid lg:grid-cols-12 lg:gap-14 lg:items-center">
                <div class="lg:col-span-6 text-center lg:text-left">
                    <p class="inline-flex items-center gap-2 rounded-full border border-brand-sage/30 bg-white/70 px-4 py-1.5 text-xs font-semibold tracking-wide text-brand-forest uppercase shadow-sm shadow-brand-forest/5">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                        Lambda · Workers · Zip deploys
                    </p>
                    <h1 class="mt-8 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl lg:text-[3.35rem] lg:leading-[1.06]">
                        Serverless without
                        <span class="relative whitespace-nowrap">
                            <span class="relative z-10 text-brand-forest">console chaos</span>
                            <span class="absolute bottom-1 left-0 right-0 h-3 bg-brand-gold/40 -rotate-1 rounded-sm -z-0" aria-hidden="true"></span>
                        </span>
                    </h1>
                    <p class="mt-6 text-lg sm:text-xl text-brand-moss max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        One control plane for functions and zip-shaped deploys—provider adapters, audit-friendly deployment records, and the same dply account you already use.
                    </p>
                    <div class="mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4">
                        <a
                            href="{{ $dplyMainUrl }}/register"
                            class="w-full sm:w-auto inline-flex justify-center items-center px-8 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors"
                        >Create your dply account</a>
                        <a
                            href="{{ url('/serverless') }}"
                            class="w-full sm:w-auto inline-flex justify-center items-center px-8 py-3.5 rounded-xl border-2 border-brand-ink/15 bg-white/80 text-brand-ink text-sm font-semibold hover:border-brand-sage/40 hover:bg-white transition-colors"
                        >Operator overview</a>
                    </div>
                    <p class="mt-6 text-sm text-brand-moss lg:text-left text-center max-w-xl mx-auto lg:mx-0">
                        <strong class="text-brand-forest font-semibold">Same identity as dply.</strong>
                        Register and log in on the main app; this surface is API + ops, not a second user database for web auth.
                    </p>
                </div>
                <div class="lg:col-span-6 mt-16 lg:mt-0 flex justify-center lg:justify-end">
                    <div class="relative w-full max-w-lg">
                        <div class="absolute -inset-6 bg-gradient-to-br from-brand-gold/25 via-brand-sage/20 to-transparent rounded-[2.5rem] blur-2xl opacity-90" aria-hidden="true"></div>
                        <div class="relative rounded-3xl border border-brand-ink/10 bg-white/85 backdrop-blur-md p-8 sm:p-10 shadow-xl shadow-brand-forest/10 ring-1 ring-brand-ink/5">
                            <div class="flex items-center gap-2 text-xs font-mono text-brand-moss/90 bg-brand-ink/[0.04] rounded-lg px-3 py-2 border border-brand-ink/8">
                                <span class="h-2 w-2 rounded-full bg-emerald-500/90" aria-hidden="true"></span>
                                POST {{ url('/api/serverless/deploy') }}
                            </div>
                            <p class="mt-6 text-sm font-semibold text-brand-forest uppercase tracking-wider">Also available</p>
                            <ul class="mt-4 space-y-3 text-sm text-brand-moss">
                                <li class="flex gap-3">
                                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-gold"></span>
                                    <span><strong class="text-brand-ink">Webhooks</strong> — <code class="text-xs bg-brand-ink/5 px-1 rounded">POST /api/webhooks/serverless/deploy</code> with HMAC.</span>
                                </li>
                                <li class="flex gap-3">
                                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-gold"></span>
                                    <span><strong class="text-brand-ink">SERVERLESS_API_TOKEN</strong> for Bearer-authenticated automation.</span>
                                </li>
                                <li class="flex gap-3">
                                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-gold"></span>
                                    <span><strong class="text-brand-ink">Provider switches</strong> in config—AWS, Cloudflare, Netlify, Vercel, and stubs for the roadmap.</span>
                                </li>
                            </ul>
                            <div class="mt-8 pt-6 border-t border-brand-ink/10 flex items-center justify-between gap-4">
                                <span class="text-xs text-brand-mist">Health</span>
                                <a href="{{ url('/health') }}" class="text-xs font-semibold text-brand-sage hover:text-brand-forest transition-colors">/health →</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/8 bg-white/50">
        <div class="max-w-6xl mx-auto">
            <h2 class="text-center text-2xl sm:text-3xl font-bold text-brand-ink tracking-tight">Multi-cloud, one discipline</h2>
            <p class="mt-4 text-center text-brand-moss max-w-2xl mx-auto text-lg">
                Pick the FaaS target that fits the project; keep deployments, idempotency, and audit trails in dply-shaped records.
            </p>
            <div class="mt-14 grid sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/80 p-8 shadow-sm shadow-brand-forest/5">
                    <h3 class="text-lg font-semibold text-brand-ink">Webhook + API</h3>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">Trigger from CI with HMAC webhooks or call the deploy API directly with a Bearer token.</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/80 p-8 shadow-sm shadow-brand-forest/5">
                    <h3 class="text-lg font-semibold text-brand-ink">Idempotent by design</h3>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">Reuse keys across retries without double-shipping when a run is still active or succeeded.</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-cream/80 p-8 shadow-sm shadow-brand-forest/5 sm:col-span-2 lg:col-span-1">
                    <h3 class="text-lg font-semibold text-brand-ink">Feature flags</h3>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">Laravel Pennant gates internal spikes and the lightweight <code class="text-xs bg-brand-ink/5 px-1 rounded">/serverless</code> overview.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8">
        <div class="max-w-4xl mx-auto text-center rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-forest/90 to-brand-ink text-brand-cream px-8 py-14 sm:px-12 shadow-xl shadow-brand-forest/20">
            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">Automate functions with dply</h2>
            <p class="mt-4 text-brand-sand/90 text-lg max-w-xl mx-auto">
                Open a dply account on the main app, configure providers and tokens here, and wire CI to the Serverless APIs.
            </p>
            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                <a
                    href="{{ $dplyMainUrl }}/register"
                    class="w-full sm:w-auto inline-flex justify-center items-center px-8 py-3.5 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-black/20 hover:bg-[#d4b24d] transition-colors"
                >Get started</a>
                <a
                    href="{{ $dplyMainUrl }}/docs"
                    class="w-full sm:w-auto inline-flex justify-center items-center px-8 py-3.5 rounded-xl border-2 border-white/25 text-brand-cream text-sm font-semibold hover:bg-white/10 transition-colors"
                >Read documentation</a>
            </div>
        </div>
    </section>
@endsection
