<footer class="border-t border-brand-ink/10 bg-brand-ink text-brand-sand/90">
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
                    Infrastructure control for teams that ship. Start with a real trial on your own servers, then move to flat organization pricing when you are ready to standardize.
                </p>
            </div>
            <div class="flex flex-wrap gap-12 sm:gap-16 text-sm">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90 mb-3">Product</p>
                    <ul class="space-y-2.5 text-brand-sand/80">
                        <li><a href="{{ url('/') }}" class="hover:text-brand-cream transition-colors">Overview</a></li>
                        <li><a href="{{ route('features') }}" class="hover:text-brand-cream transition-colors">Features</a></li>
                        <li><a href="{{ route('pricing') }}" class="hover:text-brand-cream transition-colors">Pricing</a></li>
                        <li><a href="{{ route('docs.index') }}" class="hover:text-brand-cream transition-colors">Docs</a></li>
                        @auth
                            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-cream transition-colors">Dashboard</a></li>
                        @else
                            <li><a href="{{ route('register') }}" class="hover:text-brand-cream transition-colors">Start trial</a></li>
                        @endauth
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold/90 mb-3">Account</p>
                    <ul class="space-y-2.5 text-brand-sand/80">
                        @guest
                            <li><a href="{{ route('login') }}" class="hover:text-brand-cream transition-colors">Log in</a></li>
                        @endguest
                        <li><a href="{{ route('pricing') }}" class="hover:text-brand-cream transition-colors">Trial &amp; pricing</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t border-white/10 flex flex-col sm:flex-row justify-between items-center gap-4 text-xs text-brand-mist">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
            <span class="text-brand-sand/50">Built for regulated teams and growing engineering orgs.</span>
        </div>
    </div>
</footer>
