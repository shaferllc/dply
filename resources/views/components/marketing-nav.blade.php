@props(['active' => null])

<header class="border-b border-brand-ink/10 bg-brand-cream/85 backdrop-blur-xl sticky top-0 z-30">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-y-3 py-3 sm:py-0 sm:h-16 lg:h-[4.25rem] sm:flex-nowrap">
            <a href="{{ url('/') }}" class="flex items-center gap-3 group min-w-0">
                <img
                    src="{{ asset('images/dply-logo.svg') }}"
                    alt=""
                    class="h-10 w-auto shrink-0 transition-transform duration-300 group-hover:scale-[1.02]"
                    width="56"
                    height="64"
                />
                <!-- <span class="text-lg font-semibold tracking-tight text-brand-ink">{{ config('app.name') }}</span> -->
            </a>
            <nav class="flex flex-wrap items-center justify-end gap-x-5 gap-y-2 sm:gap-6 lg:gap-8 text-sm font-medium w-full sm:w-auto">
                <a
                    href="{{ url('/') }}"
                    class="{{ $active === 'home' ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                >Home</a>
                <a
                    href="{{ route('pricing') }}"
                    class="{{ $active === 'pricing' ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                >Pricing</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="text-brand-moss hover:text-brand-ink transition-colors">Dashboard</a>
                    <a
                        href="{{ route('logout') }}"
                        onclick="event.preventDefault(); document.getElementById('marketing-logout-form').submit();"
                        class="text-brand-moss hover:text-brand-ink transition-colors"
                    >Log out</a>
                    <form id="marketing-logout-form" action="{{ route('logout') }}" method="POST" class="hidden">@csrf</form>
                @else
                    <a href="{{ route('login') }}" class="text-brand-moss hover:text-brand-ink transition-colors">Log in</a>
                    <a
                        href="{{ route('register') }}"
                        class="inline-flex items-center px-4 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors"
                    >Get started</a>
                @endauth
            </nav>
        </div>
    </div>
</header>
