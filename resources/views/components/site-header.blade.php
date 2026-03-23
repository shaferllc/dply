@props(['active' => null])

@php
    $featuresActive = $active === 'features' || request()->routeIs('features');
    $pricingActive = $active === 'pricing' || request()->routeIs('pricing');
    $homeActive = $active === 'home' || (request()->is('/') && ! request()->routeIs('dashboard'));
    $headerCurrentOrg = auth()->check() ? auth()->user()->currentOrganization() : null;
@endphp

<header x-data="{ open: false }" class="border-b border-brand-ink/10 bg-brand-cream/85 backdrop-blur-xl sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-y-3 py-3 sm:py-3 sm:min-h-[5.75rem] lg:min-h-28">
            <div class="flex items-center justify-between w-full sm:w-auto sm:justify-start gap-4 min-w-0">
                <a
                    href="{{ auth()->check() ? route('dashboard') : url('/') }}"
                    class="flex items-center gap-3 group shrink-0"
                >
                    <img
                        src="{{ asset('images/dply-logo.svg') }}"
                        alt="{{ config('app.name') }}"
                        class="h-16 w-auto sm:h-20 lg:h-24 shrink-0 transition-transform duration-300 group-hover:scale-[1.02]"
                        width="120"
                        height="136"
                    />
                </a>
                @auth
                    <button
                        type="button"
                        @click="open = ! open"
                        class="inline-flex items-center justify-center p-2 rounded-lg text-brand-moss hover:text-brand-ink hover:bg-brand-sand/40 focus:outline-none sm:hidden"
                        aria-expanded="false"
                        :aria-expanded="open"
                        aria-label="Toggle navigation"
                    >
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endauth
            </div>

            @guest
                <nav class="flex flex-wrap items-center justify-end gap-x-5 gap-y-2 sm:gap-6 lg:gap-8 text-sm font-medium w-full sm:w-auto" aria-label="Primary">
                    <a
                        href="{{ url('/') }}"
                        class="{{ $homeActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >Home</a>
                    <a
                        href="{{ route('features') }}"
                        class="{{ $featuresActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >Features</a>
                    <a
                        href="{{ route('pricing') }}"
                        class="{{ $pricingActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >Pricing</a>
                    <a href="{{ route('login') }}" class="text-brand-moss hover:text-brand-ink transition-colors">Log in</a>
                    <a
                        href="{{ route('register') }}"
                        class="inline-flex items-center px-4 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors"
                    >Get started</a>
                </nav>
            @endguest

            @auth
                <nav class="hidden sm:flex flex-wrap items-center justify-end gap-x-5 lg:gap-8 text-sm font-medium" aria-label="Primary">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-nav-link>
                    <x-nav-link :href="route('servers.index')" :active="request()->routeIs('servers.*')">{{ __('Servers') }}</x-nav-link>
                    <x-nav-link :href="route('sites.index')" :active="request()->routeIs('sites.*')">{{ __('Sites') }}</x-nav-link>
                    <x-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">{{ __('Credentials') }}</x-nav-link>
                    <x-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">{{ __('Organizations') }}</x-nav-link>
                    @if ($headerCurrentOrg)
                        <span class="text-sm text-brand-moss hidden lg:inline">
                            <a href="{{ route('organizations.show', $headerCurrentOrg) }}" class="hover:text-brand-ink">{{ $headerCurrentOrg->name }}</a>
                        </span>
                    @endif
                    <a
                        href="{{ route('features') }}"
                        class="{{ $featuresActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >Features</a>
                    <a
                        href="{{ route('pricing') }}"
                        class="{{ $pricingActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >Pricing</a>
                    <x-nav-link :href="route('docs.index')" :active="request()->routeIs('docs.*')">{{ __('Docs') }}</x-nav-link>
                    <div class="flex items-center ps-2 border-l border-brand-ink/10">
                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button type="button" class="inline-flex items-center px-3 py-2 border border-brand-ink/10 text-sm leading-4 font-medium rounded-lg text-brand-moss bg-white/80 hover:text-brand-ink focus:outline-none transition ease-in-out duration-150">
                                    <span>{{ Auth::user()->name }}</span>
                                    <span class="ms-1">
                                        <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                        {{ __('Log Out') }}
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </nav>
            @endauth
        </div>
    </div>

    @auth
        <div
            x-cloak
            x-show="open"
            x-transition
            class="sm:hidden border-t border-brand-ink/10 bg-brand-cream/95"
            id="site-header-mobile-menu"
        >
            <div class="px-4 pt-2 pb-4 space-y-1 max-w-7xl mx-auto">
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('servers.index')" :active="request()->routeIs('servers.*')">{{ __('Servers') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('sites.index')" :active="request()->routeIs('sites.*')">{{ __('Sites') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">{{ __('Credentials') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">{{ __('Organizations') }}</x-responsive-nav-link>
                @if ($headerCurrentOrg)
                    <a href="{{ route('organizations.show', $headerCurrentOrg) }}" class="block border-l-4 border-transparent py-2 ps-4 pe-4 text-sm text-brand-moss hover:bg-brand-sand/30">Current: {{ $headerCurrentOrg->name }}</a>
                @endif
                <a href="{{ route('features') }}" class="block border-l-4 {{ $featuresActive ? 'border-brand-gold bg-brand-sand/30 text-brand-ink' : 'border-transparent text-brand-moss hover:bg-brand-sand/30' }} py-2 ps-4 pe-4 text-base font-medium">Features</a>
                <a href="{{ route('pricing') }}" class="block border-l-4 {{ $pricingActive ? 'border-brand-gold bg-brand-sand/30 text-brand-ink' : 'border-transparent text-brand-moss hover:bg-brand-sand/30' }} py-2 ps-4 pe-4 text-base font-medium">Pricing</a>
                <x-responsive-nav-link :href="route('docs.index')" :active="request()->routeIs('docs.*')">{{ __('Docs') }}</x-responsive-nav-link>
                <div class="pt-4 mt-2 border-t border-brand-ink/10">
                    <p class="px-4 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ Auth::user()->name }}</p>
                    <p class="px-4 text-sm text-brand-moss">{{ Auth::user()->email }}</p>
                    <div class="mt-2 space-y-1">
                        <x-responsive-nav-link :href="route('profile.edit')">{{ __('Profile') }}</x-responsive-nav-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log Out') }}</x-responsive-nav-link>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endauth
</header>
