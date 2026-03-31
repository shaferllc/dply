@php
    $main = $dplyMainUrl;
@endphp

<header class="border-b border-brand-ink/10 bg-brand-cream/85 backdrop-blur-xl sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between gap-3 py-2.5 sm:py-3">
            <a href="{{ url('/') }}" class="flex items-center gap-3 group shrink-0 min-w-0">
                <img
                    src="{{ asset('images/dply-logo.svg') }}"
                    alt="{{ config('app.name') }}"
                    class="h-12 w-auto sm:h-14 lg:h-[4rem] shrink-0 transition-transform duration-300 group-hover:scale-[1.02]"
                    width="120"
                    height="136"
                />
                <span class="hidden sm:inline text-sm font-semibold tracking-tight text-brand-forest truncate">
                    {{ config('app.name') }}
                </span>
            </a>

            <nav class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2 sm:gap-x-5 sm:gap-6 text-sm font-medium" aria-label="Primary">
                <a
                    href="{{ $main }}"
                    class="hidden md:inline-flex text-brand-moss hover:text-brand-ink transition-colors"
                >dply</a>
                <a
                    href="{{ $main }}/features"
                    class="hidden sm:inline-flex text-brand-moss hover:text-brand-ink transition-colors"
                >Features</a>
                <a
                    href="{{ $main }}/pricing"
                    class="hidden sm:inline-flex text-brand-moss hover:text-brand-ink transition-colors"
                >Pricing</a>
                <a
                    href="{{ $main }}/docs"
                    class="hidden lg:inline-flex text-brand-moss hover:text-brand-ink transition-colors"
                >Docs</a>
                <a href="{{ $main }}/login" class="inline-flex items-center text-brand-moss hover:text-brand-ink transition-colors">
                    Log in
                </a>
                <a
                    href="{{ $main }}/register"
                    class="inline-flex items-center px-4 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors"
                >
                    Get started
                </a>
            </nav>
        </div>
    </div>
</header>
