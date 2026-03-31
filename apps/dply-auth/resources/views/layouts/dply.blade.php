<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    @hasSection('meta_description')
        <meta name="description" content="@yield('meta_description')">
    @endif

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(ellipse_100%_80%_at_50%_-30%,rgba(205,169,66,0.08),transparent_55%)]"></div>

    @unless ($hideShell ?? false)
        <header class="relative z-10 border-b border-brand-ink/10 bg-white/70 backdrop-blur-md">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
                <a href="{{ url('/') }}" class="flex items-center gap-3 shrink-0 group">
                    <img src="{{ asset('images/dply-logo.svg') }}" alt="" class="h-9 w-auto" width="120" height="36" />
                    <span class="text-sm font-semibold text-brand-forest tracking-tight hidden sm:inline">Auth</span>
                </a>
                <nav class="flex items-center gap-2 sm:gap-3 text-sm">
                    @auth
                        <a href="{{ url('/home') }}" class="px-3 py-2 rounded-lg text-brand-moss hover:text-brand-forest hover:bg-brand-ink/5 transition-colors">Account</a>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="px-3 py-2 rounded-lg font-medium text-brand-moss hover:text-brand-forest hover:bg-brand-ink/5 transition-colors">
                                Log out
                            </button>
                        </form>
                    @else
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="px-3 py-2 rounded-lg text-brand-moss hover:text-brand-forest hover:bg-brand-ink/5 transition-colors">Log in</a>
                        @endif
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-flex items-center px-4 py-2 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-md shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors">
                                Create account
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>
    @endunless

    <main class="relative z-0 flex-1 flex flex-col @yield('main_class', '')">
        @yield('content')
    </main>

    @unless ($hideShell ?? false)
        <footer class="relative z-10 mt-auto border-t border-brand-ink/10 bg-white/50 py-8">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-xs text-brand-moss">
                <p>dply central sign-in — OAuth tokens for Edge, Cloud, Serverless, WordPress, and BYO.</p>
            </div>
        </footer>
    @endunless
</body>
</html>
