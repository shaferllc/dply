<nav x-data="{ open: false }" class="border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="text-lg font-semibold text-slate-900">
                        {{ config('app.name') }}
                    </a>
                </div>
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex items-center">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('servers.index')" :active="request()->routeIs('servers.*')">
                        {{ __('Servers') }}
                    </x-nav-link>
                    <x-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">
                        {{ __('Credentials') }}
                    </x-nav-link>
                    <x-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">
                        {{ __('Organizations') }}
                    </x-nav-link>
                    @auth
                        @php $currentOrg = Auth::user()->currentOrganization(); @endphp
                        @if ($currentOrg)
                            <span class="text-sm text-slate-500">
                                <a href="{{ route('organizations.show', $currentOrg) }}" class="hover:text-slate-700">{{ $currentOrg->name }}</a>
                            </span>
                        @endif
                    @endauth
                    <a href="{{ route('pricing') }}" class="inline-flex items-center border-b-2 border-transparent px-1 pt-1 text-sm font-medium text-slate-600 hover:border-slate-300 hover:text-slate-900">Pricing</a>
                    @auth
                        <x-nav-link :href="route('docs.index')" :active="request()->routeIs('docs.*')">
                            {{ __('Docs') }}
                        </x-nav-link>
                    @endauth
                </div>
            </div>
            @auth
                <div class="hidden sm:flex sm:items-center sm:ms-6">
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-lg text-slate-600 bg-white hover:text-slate-900 focus:outline-none transition ease-in-out duration-150">
                                <div>{{ Auth::user()->name }}</div>
                                <div class="ms-1">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
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
                <div class="-me-2 flex items-center sm:hidden">
                    <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-lg text-slate-500 hover:text-slate-700 hover:bg-slate-100 focus:outline-none transition duration-150 ease-in-out">
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endauth
        </div>
    </div>
    @auth
        <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
            <div class="pt-2 pb-3 space-y-1">
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('servers.index')" :active="request()->routeIs('servers.*')">{{ __('Servers') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('credentials.index')" :active="request()->routeIs('credentials.*')">{{ __('Credentials') }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('organizations.index')" :active="request()->routeIs('organizations.*')">{{ __('Organizations') }}</x-responsive-nav-link>
                @if ($currentOrg = Auth::user()->currentOrganization())
                    <a href="{{ route('organizations.show', $currentOrg) }}" class="block border-l-4 border-transparent py-2 ps-4 pe-4 text-sm text-slate-500 hover:bg-slate-50">Current: {{ $currentOrg->name }}</a>
                @endif
                <a href="{{ route('pricing') }}" class="block border-l-4 border-transparent py-2 ps-4 pe-4 text-base font-medium text-slate-600 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900">Pricing</a>
                <x-responsive-nav-link :href="route('docs.index')" :active="request()->routeIs('docs.*')">{{ __('Docs') }}</x-responsive-nav-link>
            </div>
            <div class="pt-4 pb-1 border-t border-slate-200">
                <div class="px-4">
                    <div class="font-medium text-base text-slate-900">{{ Auth::user()->name }}</div>
                    <div class="font-medium text-sm text-slate-500">{{ Auth::user()->email }}</div>
                </div>
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('profile.edit')">{{ __('Profile') }}</x-responsive-nav-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log Out') }}</x-responsive-nav-link>
                    </form>
                </div>
            </div>
        </div>
    @endauth
</nav>
