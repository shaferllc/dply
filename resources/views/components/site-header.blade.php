@props([
    'active' => null,
    'showGuestSignup' => true,
])

@php
    $notificationTablesReady = \Illuminate\Support\Facades\Schema::hasTable('notification_inbox_items')
        && \Illuminate\Support\Facades\Schema::hasTable('notification_events');
    $featuresActive = $active === 'features' || request()->routeIs('features');
    $pricingActive = $active === 'pricing' || request()->routeIs('pricing');
    $homeActive = $active === 'home' || (request()->is('/') && ! request()->routeIs('dashboard'));
    $hi = 'h-5 w-5 shrink-0';
    $hiGuest = 'h-4 w-4 shrink-0 opacity-90';
    $moreMenuActive = request()->routeIs('status-pages.*')
        || request()->routeIs('marketplace.index')
        || request()->routeIs('backups.*')
        || request()->routeIs('scripts.*')
        || $featuresActive
        || $pricingActive
        || request()->routeIs('docs.*');
    $adminMenuActive = auth()->check()
        && \Illuminate\Support\Facades\Gate::check('viewPlatformAdmin')
        && (
            request()->routeIs('admin.dashboard')
            || request()->is('horizon*')
            || request()->is('pulse*')
        );
    $notificationMenuActive = request()->routeIs('notifications.*');
    $notificationUnreadCount = auth()->check() && $notificationTablesReady
        ? auth()->user()->notificationInboxItems()->whereNull('read_at')->count()
        : 0;
    $recentNotificationItems = auth()->check() && $notificationTablesReady
        ? auth()->user()->notificationInboxItems()->limit(5)->get()
        : collect();
@endphp

<header x-data="{ open: false }" class="border-b border-brand-ink/10 bg-brand-cream/85 backdrop-blur-xl sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between gap-3 py-2.5 sm:py-3">
            <div class="flex items-center justify-between sm:justify-start gap-3 min-w-0 shrink-0 w-full sm:w-auto">
                <a
                    href="{{ auth()->check() ? route('dashboard') : url('/') }}"
                    class="flex items-center gap-3 group shrink-0"
                >
                    <img
                        src="{{ asset('images/dply-logo.svg') }}"
                        alt="{{ config('app.name') }}"
                        class="h-14 w-auto sm:h-16 lg:h-[4.25rem] shrink-0 transition-transform duration-300 group-hover:scale-[1.02]"
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
                        <span class="relative block h-6 w-6 shrink-0" aria-hidden="true">
                            <x-heroicon-o-bars-3 class="absolute inset-0 h-6 w-6 text-current" x-show="! open" />
                            <x-heroicon-o-x-mark class="absolute inset-0 h-6 w-6 text-current" x-show="open" x-cloak />
                        </span>
                    </button>
                @endauth
            </div>

            @guest
                <nav class="flex flex-wrap items-center justify-end gap-x-5 gap-y-2 sm:gap-6 lg:gap-8 text-sm font-medium w-full sm:w-auto" aria-label="Primary">
                    <a
                        href="{{ url('/') }}"
                        class="inline-flex items-center gap-1.5 {{ $homeActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >
                        <x-heroicon-o-home class="{{ $hiGuest }}" />
                        {{ __('Home') }}
                    </a>
                    <a
                        href="{{ route('features') }}"
                        class="inline-flex items-center gap-1.5 {{ $featuresActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >
                        <x-heroicon-o-sparkles class="{{ $hiGuest }}" />
                        {{ __('Features') }}
                    </a>
                    <a
                        href="{{ route('pricing') }}"
                        class="inline-flex items-center gap-1.5 {{ $pricingActive ? 'text-brand-ink' : 'text-brand-moss hover:text-brand-ink' }} transition-colors"
                    >
                        <x-heroicon-o-credit-card class="{{ $hiGuest }}" />
                        {{ __('Pricing') }}
                    </a>
                    <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 text-brand-moss hover:text-brand-ink transition-colors">
                        <x-heroicon-o-arrow-right-end-on-rectangle class="{{ $hiGuest }}" />
                        {{ __('Log in') }}
                    </a>
                    @if ($showGuestSignup)
                        <a
                            href="{{ route('register') }}"
                            class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors"
                        >
                            <x-heroicon-o-rocket-launch class="{{ $hiGuest }}" />
                            {{ __('Get started') }}
                        </a>
                    @endif
                </nav>
            @endguest

            @auth
                <div class="hidden sm:flex flex-1 min-w-0 items-center justify-end gap-1 ms-2 lg:ms-4">
                    <div class="min-w-0 flex-1 overflow-x-auto overscroll-x-contain [scrollbar-width:thin] [scrollbar-color:rgba(55_65_50/0.35)_transparent]">
                        <nav class="flex h-full min-h-[2.75rem] flex-nowrap items-center justify-end gap-x-0.5 lg:gap-x-1 pe-2 text-sm font-medium" aria-label="{{ __('App') }}">
                            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                                <x-slot name="icon">
                                    <x-heroicon-o-squares-2x2 class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Dashboard') }}
                            </x-nav-link>
                            <x-nav-link :href="route('servers.index')" :active="request()->routeIs('servers.*')">
                                <x-slot name="icon">
                                    <x-heroicon-o-server class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Servers') }}
                            </x-nav-link>
                            <x-nav-link :href="route('sites.index')" :active="request()->routeIs('sites.*')">
                                <x-slot name="icon">
                                    <x-heroicon-o-globe-alt class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Sites') }}
                            </x-nav-link>
                            <x-nav-link :href="route('projects.index')" :active="request()->routeIs('projects.*')">
                                <x-slot name="icon">
                                    <x-heroicon-o-rectangle-stack class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Projects') }}
                            </x-nav-link>
                            <x-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">
                                <x-slot name="icon">
                                    <x-heroicon-o-building-office-2 class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Organizations') }}
                            </x-nav-link>
                        </nav>
                    </div>
                    @can('viewPlatformAdmin')
                        <div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-2 lg:ps-3" aria-label="{{ __('Platform admin') }}">
                            <x-dropdown align="right" width="w-64" contentClasses="py-1 bg-white">
                                <x-slot name="trigger">
                                    <button
                                        type="button"
                                        class="group inline-flex shrink-0 items-center gap-1 whitespace-nowrap px-1.5 py-2 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40 rounded-t {{ $adminMenuActive ? 'border-brand-gold text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink hover:border-brand-sage/40' }}"
                                        aria-haspopup="menu"
                                    >
                                        <x-heroicon-o-shield-check class="h-5 w-5 shrink-0 opacity-90" />
                                        <span>{{ __('Admin') }}</span>
                                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <x-dropdown-link :href="route('admin.dashboard')">
                                        <x-slot name="icon">
                                            <x-heroicon-o-squares-2x2 class="{{ $hi }}" />
                                        </x-slot>
                                        {{ __('Platform overview') }}
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('horizon.index')">
                                        <x-slot name="icon">
                                            <x-heroicon-o-queue-list class="{{ $hi }}" />
                                        </x-slot>
                                        {{ __('Horizon') }}
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('pulse')">
                                        <x-slot name="icon">
                                            <x-heroicon-o-chart-bar class="{{ $hi }}" />
                                        </x-slot>
                                        {{ __('Laravel Pulse') }}
                                    </x-dropdown-link>
                                    @if (reverb_health_check_url())
                                        <x-dropdown-link :href="reverb_health_check_url()" target="_blank" rel="noopener noreferrer">
                                            <x-slot name="icon">
                                                <x-heroicon-o-signal class="{{ $hi }}" />
                                            </x-slot>
                                            {{ __('Reverb health') }}
                                        </x-dropdown-link>
                                    @endif
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @endcan
                    <div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-2 lg:ps-3" aria-label="{{ __('Notifications') }}">
                        <x-dropdown align="right" width="w-80" contentClasses="py-1 bg-white">
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    class="group inline-flex shrink-0 items-center gap-1 whitespace-nowrap px-1.5 py-2 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40 rounded-t {{ $notificationMenuActive ? 'border-brand-gold text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink hover:border-brand-sage/40' }}"
                                    aria-haspopup="menu"
                                >
                                    <span class="relative inline-flex">
                                        <x-heroicon-o-bell class="h-5 w-5 shrink-0 opacity-90" />
                                        @if ($notificationUnreadCount > 0)
                                            <span class="absolute -right-1.5 -top-1.5 inline-flex min-h-4 min-w-4 items-center justify-center rounded-full bg-brand-gold px-1 text-[10px] font-semibold text-brand-ink">
                                                {{ $notificationUnreadCount > 9 ? '9+' : $notificationUnreadCount }}
                                            </span>
                                        @endif
                                    </span>
                                    <span>{{ __('Notifications') }}</span>
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <div class="border-b border-brand-ink/10 px-4 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-brand-ink">{{ __('Notifications') }}</p>
                                            <p class="text-xs text-brand-moss">{{ __('Unread: :count', ['count' => $notificationUnreadCount]) }}</p>
                                        </div>
                                        @if ($notificationTablesReady)
                                            <a href="{{ route('notifications.index') }}" class="text-xs font-medium text-brand-forest hover:text-brand-ink">
                                                {{ __('Open inbox') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                                @forelse ($recentNotificationItems as $notificationItem)
                                    <a href="{{ $notificationItem->url ?: route('notifications.index') }}" class="block border-b border-brand-ink/5 px-4 py-3 last:border-b-0 hover:bg-brand-sand/20">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-medium text-brand-ink">{{ $notificationItem->title }}</p>
                                                @if ($notificationItem->body)
                                                    <p class="mt-1 line-clamp-2 text-xs text-brand-moss">{{ $notificationItem->body }}</p>
                                                @endif
                                            </div>
                                            @if (! $notificationItem->read_at)
                                                <span class="mt-1 inline-flex h-2.5 w-2.5 rounded-full bg-brand-gold"></span>
                                            @endif
                                        </div>
                                    </a>
                                @empty
                                    <div class="px-4 py-4 text-sm text-brand-moss">
                                        {{ $notificationTablesReady
                                            ? __('No notifications yet.')
                                            : __('Notifications will appear here after the latest database migrations are applied.') }}
                                    </div>
                                @endforelse
                            </x-slot>
                        </x-dropdown>
                    </div>
                    <div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-2 lg:ps-3" aria-label="{{ __('More navigation') }}">
                        <x-dropdown align="right" width="w-56" contentClasses="py-1 bg-white">
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    class="group inline-flex shrink-0 items-center gap-1 whitespace-nowrap px-1.5 py-2 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40 rounded-t {{ $moreMenuActive ? 'border-brand-gold text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink hover:border-brand-sage/40' }}"
                                    aria-haspopup="menu"
                                >
                                    <x-heroicon-o-ellipsis-horizontal class="h-5 w-5 shrink-0 opacity-90" />
                                    <span>{{ __('More') }}</span>
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('status-pages.index')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-check-circle class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Status') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('marketplace.index')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-squares-plus class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Marketplace') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('backups.databases')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-archive-box class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Backups') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('scripts.index')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-code-bracket-square class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Scripts') }}
                                </x-dropdown-link>
                                <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                <x-dropdown-link :href="route('features')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-sparkles class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Features') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('pricing')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-credit-card class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Pricing') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('docs.index')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-book-open class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Docs') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                    <div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-2 lg:ps-3" aria-label="{{ __('Account') }}">
                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white/90 px-2.5 py-2 text-sm font-medium text-brand-ink shadow-sm shadow-brand-ink/5 transition hover:border-brand-ink/20 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/50">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-brand-sand/50 text-brand-forest ring-1 ring-brand-ink/5" aria-hidden="true">
                                        <x-heroicon-o-user-circle class="h-4 w-4 shrink-0" />
                                    </span>
                                    <span class="max-w-[10rem] truncate text-left leading-tight text-brand-moss">{{ Auth::user()->name }}</span>
                                    <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-moss" />
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('settings.index')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-cog-8-tooth class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Settings') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('profile.edit')">
                                    <x-slot name="icon">
                                        <x-heroicon-o-user class="{{ $hi }}" />
                                    </x-slot>
                                    {{ __('Profile') }}
                                </x-dropdown-link>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                        <x-slot name="icon">
                                            <x-heroicon-o-arrow-right-start-on-rectangle class="{{ $hi }}" />
                                        </x-slot>
                                        {{ __('Log Out') }}
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>
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
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    <x-slot name="icon">
                        <x-heroicon-o-squares-2x2 class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Dashboard') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('servers.index')" :active="request()->routeIs('servers.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-server class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Servers') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('sites.index')" :active="request()->routeIs('sites.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-globe-alt class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Sites') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('projects.index')" :active="request()->routeIs('projects.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-rectangle-stack class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Projects') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('status-pages.index')" :active="request()->routeIs('status-pages.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-check-circle class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Status') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('marketplace.index')" :active="request()->routeIs('marketplace.index')">
                    <x-slot name="icon">
                        <x-heroicon-o-squares-plus class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Marketplace') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('backups.databases')" :active="request()->routeIs('backups.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-archive-box class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Backups') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('scripts.index')" :active="request()->routeIs('scripts.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-code-bracket-square class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Scripts') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-building-office-2 class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Organizations') }}
                </x-responsive-nav-link>
                @can('viewPlatformAdmin')
                    <div class="border-t border-brand-ink/10 pt-2 mt-2">
                        <p class="px-4 pb-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Admin') }}</p>
                        <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.dashboard')">
                            <x-slot name="icon">
                                <x-heroicon-o-shield-check class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Platform overview') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('horizon.index')" :active="request()->is('horizon*')">
                            <x-slot name="icon">
                                <x-heroicon-o-queue-list class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Horizon') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('pulse')" :active="request()->is('pulse*')">
                            <x-slot name="icon">
                                <x-heroicon-o-chart-bar class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Laravel Pulse') }}
                        </x-responsive-nav-link>
                        @if (reverb_health_check_url())
                            <a
                                href="{{ reverb_health_check_url() }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="flex items-center gap-2.5 border-l-4 border-transparent py-2 ps-3 pe-4 text-base font-medium text-brand-moss hover:bg-brand-sand/30"
                            >
                                <x-heroicon-o-signal class="{{ $hi }}" />
                                {{ __('Reverb health') }}
                            </a>
                        @endif
                    </div>
                @endcan
                <a href="{{ route('features') }}" class="flex items-center gap-2.5 border-l-4 {{ $featuresActive ? 'border-brand-gold bg-brand-sand/30 text-brand-ink' : 'border-transparent text-brand-moss hover:bg-brand-sand/30' }} py-2 ps-3 pe-4 text-base font-medium">
                    <x-heroicon-o-sparkles class="h-5 w-5 shrink-0 opacity-90" />
                    {{ __('Features') }}
                </a>
                <a href="{{ route('pricing') }}" class="flex items-center gap-2.5 border-l-4 {{ $pricingActive ? 'border-brand-gold bg-brand-sand/30 text-brand-ink' : 'border-transparent text-brand-moss hover:bg-brand-sand/30' }} py-2 ps-3 pe-4 text-base font-medium">
                    <x-heroicon-o-credit-card class="h-5 w-5 shrink-0 opacity-90" />
                    {{ __('Pricing') }}
                </a>
                <x-responsive-nav-link :href="route('docs.index')" :active="request()->routeIs('docs.*')">
                    <x-slot name="icon">
                        <x-heroicon-o-book-open class="{{ $hi }}" />
                    </x-slot>
                    {{ __('Docs') }}
                </x-responsive-nav-link>
                <div class="pt-4 mt-2 border-t border-brand-ink/10">
                    <p class="px-4 text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ Auth::user()->name }}</p>
                    <p class="px-4 text-sm text-brand-moss">{{ Auth::user()->email }}</p>
                    <div class="mt-2 space-y-1">
                        <x-responsive-nav-link :href="route('settings.index')">
                            <x-slot name="icon">
                                <x-heroicon-o-cog-8-tooth class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Settings') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('profile.edit')">
                            <x-slot name="icon">
                                <x-heroicon-o-user class="{{ $hi }}" />
                            </x-slot>
                            {{ __('Profile') }}
                        </x-responsive-nav-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                <x-slot name="icon">
                                    <x-heroicon-o-arrow-right-start-on-rectangle class="{{ $hi }}" />
                                </x-slot>
                                {{ __('Log Out') }}
                            </x-responsive-nav-link>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endauth
</header>
