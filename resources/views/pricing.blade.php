<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pricing – {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-slate-50 text-slate-900" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    {{-- Nav --}}
    <header class="border-b border-slate-200 bg-white/80 backdrop-blur-sm sticky top-0 z-10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="{{ url('/') }}" class="text-xl font-semibold text-slate-900">{{ config('app.name') }}</a>
                <nav class="flex items-center gap-6 text-sm font-medium text-slate-600">
                    <a href="{{ url('/') }}" class="hover:text-slate-900">Home</a>
                    <a href="{{ route('pricing') }}" class="text-slate-900">Pricing</a>
                    @auth
                        <a href="{{ route('dashboard') }}" class="hover:text-slate-900">Dashboard</a>
                        <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="hover:text-slate-900">Log out</a>
                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">@csrf</form>
                    @else
                        <a href="{{ route('login') }}" class="hover:text-slate-900">Log in</a>
                        <a href="{{ route('register') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-medium hover:bg-slate-800">Get started</a>
                    @endauth
                </nav>
            </div>
        </div>
    </header>

    <main class="flex-1">
        {{-- Hero --}}
        <section class="pt-16 pb-12 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h1 class="text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">Simple, transparent pricing</h1>
                <p class="mt-4 text-lg text-slate-600">Deploy and manage servers with one workflow. Start free, scale when you’re ready.</p>
            </div>
        </section>

        {{-- Toggle: monthly / annual (optional) --}}
        <div class="flex justify-center mb-12" x-data="{ annual: false }">
            <div class="inline-flex items-center gap-3 p-1 rounded-lg bg-slate-200/60">
                <button type="button" @click="annual = false" :class="!annual ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'" class="px-4 py-2 rounded-md text-sm font-medium transition">Monthly</button>
                <button type="button" @click="annual = true" :class="annual ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'" class="px-4 py-2 rounded-md text-sm font-medium transition">Annual</button>
                <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Save 20%</span>
            </div>
        </div>

        {{-- Pricing cards --}}
        <section class="pb-24 px-4 sm:px-6 lg:px-8">
            <div class="max-w-5xl mx-auto grid gap-8 md:grid-cols-3">
                {{-- Starter --}}
                <div class="relative flex flex-col rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-900">Starter</h2>
                    <p class="mt-1 text-sm text-slate-500">For side projects and experiments</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-slate-900">$0</span>
                        <span class="text-slate-500">/mo</span>
                    </div>
                    <ul class="mt-8 space-y-4 flex-1">
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Up to 3 servers
                        </li>
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            DigitalOcean &amp; SSH
                        </li>
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Run commands from dashboard
                        </li>
                    </ul>
                    <a href="{{ route('register') }}" class="mt-8 block w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">Get started</a>
                </div>

                {{-- Pro (featured) --}}
                <div class="relative flex flex-col rounded-2xl border-2 border-slate-900 bg-white p-8 shadow-lg ring-1 ring-slate-900/5">
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-slate-900 px-3 py-0.5 text-xs font-medium text-white">Most popular</div>
                    <h2 class="text-lg font-semibold text-slate-900">Pro</h2>
                    <p class="mt-1 text-sm text-slate-500">For growing teams and production</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-slate-900">$29</span>
                        <span class="text-slate-500">/mo</span>
                    </div>
                    <ul class="mt-8 space-y-4 flex-1">
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Unlimited servers
                        </li>
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Everything in Starter
                        </li>
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Priority support
                        </li>
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Server setup scripts (coming soon)
                        </li>
                    </ul>
                    <a href="{{ route('register') }}" class="mt-8 block w-full rounded-lg bg-slate-900 px-4 py-2.5 text-center text-sm font-medium text-white shadow-sm hover:bg-slate-800">Get started</a>
                </div>

                {{-- Team --}}
                <div class="relative flex flex-col rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-900">Team</h2>
                    <p class="mt-1 text-sm text-slate-500">For organizations</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-slate-900">Custom</span>
                    </div>
                    <ul class="mt-8 space-y-4 flex-1">
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Everything in Pro
                        </li>
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Team seats &amp; SSO
                        </li>
                        <li class="flex items-start gap-3 text-sm text-slate-600">
                            <svg class="h-5 w-5 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            Dedicated support
                        </li>
                    </ul>
                    <a href="mailto:hello@dply.io?subject=Team%20plan" class="mt-8 block w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">Contact sales</a>
                </div>
            </div>
        </section>

        {{-- FAQ or trust line --}}
        <section class="border-t border-slate-200 bg-white py-16 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <p class="text-slate-600">All plans include secure credential storage, SSH key management, and DigitalOcean integration. You only pay for the cloud resources you use.</p>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 py-8 px-4 sm:px-6 lg:px-8">
        <div class="max-w-6xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-4 text-sm text-slate-500">
            <span>{{ config('app.name') }}</span>
            <div class="flex gap-6">
                <a href="{{ url('/') }}" class="hover:text-slate-700">Home</a>
                <a href="{{ route('pricing') }}" class="hover:text-slate-700">Pricing</a>
            </div>
        </div>
    </footer>
</body>
</html>
