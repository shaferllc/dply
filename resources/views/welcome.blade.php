<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} – Deploy and manage servers from one place</title>
    <meta name="description" content="Connect your cloud, run commands, and manage credentials. Teams and organizations included.">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-stone-50 text-stone-900" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    {{-- Background texture --}}
    <div class="fixed inset-0 -z-10 bg-gradient-to-br from-stone-100/40 via-stone-50/80 to-stone-100/30"></div>
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(ellipse_80%_60%_at_50%_-20%,rgba(245,245,244,0.4),transparent)]"></div>

    {{-- Nav --}}
    <header class="border-b border-stone-200/80 bg-white/70 backdrop-blur-md sticky top-0 z-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="{{ url('/') }}" class="text-xl font-semibold tracking-tight text-stone-900">{{ config('app.name') }}</a>
                <nav class="flex items-center gap-8 text-sm font-medium text-stone-600">
                    <a href="{{ url('/') }}" class="text-stone-900">Home</a>
                    <a href="{{ route('pricing') }}" class="hover:text-stone-900 transition-colors">Pricing</a>
                    @auth
                        <a href="{{ route('dashboard') }}" class="hover:text-stone-900 transition-colors">Dashboard</a>
                        <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="hover:text-stone-900 transition-colors">Log out</a>
                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">@csrf</form>
                    @else
                        <a href="{{ route('login') }}" class="hover:text-stone-900 transition-colors">Log in</a>
                        <a href="{{ route('register') }}" class="inline-flex items-center px-4 py-2.5 rounded-lg bg-stone-900 text-white text-sm font-medium hover:bg-stone-800 transition-colors">Get started</a>
                    @endauth
                </nav>
            </div>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="pt-20 pb-16 sm:pt-28 sm:pb-24 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto text-center">
                <p class="inline-flex items-center gap-2 rounded-full border border-amber-200/80 bg-amber-50/80 px-3.5 py-1 text-xs font-medium text-amber-800 mb-8">Deploy from your dashboard · DigitalOcean &amp; SSH</p>
                <h1 class="text-4xl font-bold tracking-tight text-stone-900 sm:text-5xl lg:text-6xl lg:leading-[1.1]">
                    Servers, credentials, and commands in one place
                </h1>
                <p class="mt-6 text-lg sm:text-xl text-stone-600 max-w-2xl mx-auto leading-relaxed">
                    Connect your cloud provider once. Run commands, manage teams, and keep API keys secure—without leaving the app.
                </p>
                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-6 py-3 rounded-xl bg-stone-900 text-white text-sm font-semibold hover:bg-stone-800 transition-colors shadow-sm">Go to dashboard</a>
                    @else
                        <a href="{{ route('register') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-6 py-3 rounded-xl bg-stone-900 text-white text-sm font-semibold hover:bg-stone-800 transition-colors shadow-sm">Get started free</a>
                    @endauth
                    <a href="{{ route('pricing') }}" class="w-full sm:w-auto inline-flex justify-center items-center px-6 py-3 rounded-xl border border-stone-300 bg-white text-stone-700 text-sm font-medium hover:bg-stone-50 transition-colors">View pricing</a>
                </div>
            </div>

            {{-- Terminal / product tease --}}
            <div class="max-w-3xl mx-auto mt-16 sm:mt-20">
                <div class="rounded-2xl border border-stone-200/90 bg-white/80 shadow-xl shadow-stone-200/30 overflow-hidden">
                    <div class="flex items-center gap-2 px-4 py-3 border-b border-stone-200 bg-stone-50/80">
                        <span class="w-3 h-3 rounded-full bg-stone-300"></span>
                        <span class="w-3 h-3 rounded-full bg-stone-300"></span>
                        <span class="w-3 h-3 rounded-full bg-stone-300"></span>
                        <span class="ml-2 text-xs font-medium text-stone-500">dply · web-1</span>
                    </div>
                    <div class="p-4 sm:p-6 font-mono text-sm text-stone-700 leading-relaxed">
                        <div><span class="text-amber-600">$</span> ssh deploy@web-1</div>
                        <div class="mt-2 text-stone-500"># Run from your dashboard — no local SSH config</div>
                        <div class="mt-4"><span class="text-amber-600">$</span> php artisan deploy</div>
                        <div class="mt-2 text-emerald-600">Deployment complete. 3 files updated.</div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section class="py-20 sm:py-24 px-4 sm:px-6 lg:px-8 border-t border-stone-200/80">
            <div class="max-w-6xl mx-auto">
                <h2 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl text-center">One workflow for your whole stack</h2>
                <p class="mt-3 text-stone-600 text-center max-w-xl mx-auto">Connect providers, add servers, and give your team access—without sharing keys or terminals.</p>
                <div class="mt-14 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-stone-200/80 bg-white/60 p-6 shadow-sm">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                        </div>
                        <h3 class="mt-4 font-semibold text-stone-900">Cloud in minutes</h3>
                        <p class="mt-2 text-sm text-stone-600">Link DigitalOcean (or any SSH host). Servers show up in your org. No manual key copying.</p>
                    </div>
                    <div class="rounded-2xl border border-stone-200/80 bg-white/60 p-6 shadow-sm">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        </div>
                        <h3 class="mt-4 font-semibold text-stone-900">Credentials that stay put</h3>
                        <p class="mt-2 text-sm text-stone-600">API tokens and keys live in the app. Members use servers without ever seeing the secrets.</p>
                    </div>
                    <div class="rounded-2xl border border-stone-200/80 bg-white/60 p-6 shadow-sm">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <h3 class="mt-4 font-semibold text-stone-900">Teams &amp; organizations</h3>
                        <p class="mt-2 text-sm text-stone-600">Invite by email, create teams, and control who sees which servers. Billing per organization.</p>
                    </div>
                    <div class="rounded-2xl border border-stone-200/80 bg-white/60 p-6 shadow-sm">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <h3 class="mt-4 font-semibold text-stone-900">Run commands remotely</h3>
                        <p class="mt-2 text-sm text-stone-600">Execute one-off commands from the dashboard. No SSH client required for quick fixes.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- CTA --}}
        <section class="py-20 sm:py-24 px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center rounded-3xl border border-stone-200/80 bg-white/70 px-8 py-14 shadow-sm">
                <h2 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">Ready to simplify deployment?</h2>
                <p class="mt-3 text-stone-600">Start with a free account. Add servers and teammates when you’re ready.</p>
                @guest
                    <a href="{{ route('register') }}" class="mt-8 inline-flex items-center px-6 py-3 rounded-xl bg-stone-900 text-white text-sm font-semibold hover:bg-stone-800 transition-colors shadow-sm">Get started free</a>
                @endguest
            </div>
        </section>
    </main>

    <footer class="border-t border-stone-200/80 py-10 px-4 sm:px-6 lg:px-8 bg-white/40">
        <div class="max-w-6xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-4 text-sm text-stone-500">
            <span class="font-medium text-stone-700">{{ config('app.name') }}</span>
            <div class="flex gap-8">
                <a href="{{ url('/') }}" class="hover:text-stone-700 transition-colors">Home</a>
                <a href="{{ route('pricing') }}" class="hover:text-stone-700 transition-colors">Pricing</a>
            </div>
        </div>
    </footer>
</body>
</html>
