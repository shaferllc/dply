@php
    $main = $dplyMainUrl;
@endphp

<footer class="border-t border-brand-ink/10 bg-brand-ink text-brand-sand/90 mt-auto">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12 lg:py-14">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-10">
            <div class="max-w-sm">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-3">
                    <img
                        src="{{ asset('images/dply-logo.svg') }}"
                        alt=""
                        class="h-9 w-auto opacity-95"
                        width="50"
                        height="58"
                    />
                    <span class="text-lg font-semibold tracking-tight text-brand-cream">{{ config('app.name') }}</span>
                </a>
                <p class="mt-4 text-sm leading-relaxed text-brand-mist">
                    Functions and event-driven workloads across AWS, Cloudflare, Netlify, Vercel, and more—webhooks, Bearer tokens, and idempotent deploys.
                </p>
            </div>
            <div class="flex flex-wrap gap-12 sm:gap-16 text-sm">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90 mb-3">dply Serverless</p>
                    <ul class="space-y-2.5 text-brand-sand/80">
                        <li><a href="{{ url('/') }}" class="hover:text-brand-cream transition-colors">Overview</a></li>
                        <li><a href="{{ url('/serverless') }}" class="hover:text-brand-cream transition-colors">Operator view</a></li>
                        <li><a href="{{ $main }}/docs" class="hover:text-brand-cream transition-colors">Documentation</a></li>
                        <li><span class="text-brand-mist">API</span> <code class="text-xs text-brand-sand/60">/api/serverless/*</code></li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90 mb-3">Platform</p>
                    <ul class="space-y-2.5 text-brand-sand/80">
                        <li><a href="{{ $main }}" class="hover:text-brand-cream transition-colors">dply home</a></li>
                        <li><a href="{{ $main }}/features" class="hover:text-brand-cream transition-colors">Features</a></li>
                        <li><a href="{{ $main }}/pricing" class="hover:text-brand-cream transition-colors">Pricing</a></li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90 mb-3">Account</p>
                    <ul class="space-y-2.5 text-brand-sand/80">
                        <li><a href="{{ $main }}/login" class="hover:text-brand-cream transition-colors">Log in</a></li>
                        <li><a href="{{ $main }}/register" class="hover:text-brand-cream transition-colors">Register</a></li>
                        <li><a href="{{ $main }}/dashboard" class="hover:text-brand-cream transition-colors">Dashboard</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t border-white/10 flex flex-col sm:flex-row justify-between items-center gap-4 text-xs text-brand-mist">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}. Part of the dply platform.</span>
            <span class="text-brand-sand/50">Auth uses your main dply account.</span>
        </div>
    </div>
</footer>
