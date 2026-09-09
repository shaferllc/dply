{{-- One compact row: mark, inline links, copyright. The long blurb and the
     column headings were furniture — the header already sells the product. --}}
<footer class="border-t border-brand-ink/10 bg-brand-ink text-brand-sand/90">
    <div class="dply-page-shell flex flex-col items-center gap-4 py-6 text-sm sm:flex-row sm:justify-between sm:gap-6">
        <a href="{{ url('/') }}" class="inline-flex shrink-0 items-center gap-1.5">
            {{-- Dark-background mark (inverse of the light header lockup): gold
                 square + ink "d". The mark's "d" is the word's first letter,
                 so the wordmark beside it is "ply" (reads "dply"). --}}
            <img
                src="{{ asset('images/dply-mark-dark.svg') }}"
                alt="{{ config('app.name') }}"
                class="h-7 w-7 shrink-0"
                width="28"
                height="28"
            />
            <span class="font-semibold tracking-tight text-brand-cream">ply</span>
        </a>

        <nav class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-brand-sand/80">
            <a href="{{ route('features') }}" class="hover:text-brand-cream transition-colors">Features</a>
            <a href="{{ route('pricing') }}" class="hover:text-brand-cream transition-colors">Pricing</a>
            <a href="{{ route('migrate.index') }}" class="hover:text-brand-cream transition-colors">Migrate</a>
            <a href="{{ route('docs.index') }}" class="hover:text-brand-cream transition-colors">Docs</a>
            @auth
                <a href="{{ route('dashboard') }}" class="hover:text-brand-cream transition-colors">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="hover:text-brand-cream transition-colors">Log in</a>
                <a href="{{ route('register') }}" class="hover:text-brand-cream transition-colors">Start trial</a>
            @endauth
        </nav>

        <p class="shrink-0 text-xs text-brand-mist">
            &copy; {{ date('Y') }} {{ config('app.name') }}
            <span class="ml-1.5 font-mono text-brand-sand/40" title="{{ \App\Support\AppVersion::sha() }}">v{{ \App\Support\AppVersion::date() }}</span>
        </p>
    </div>
</footer>
