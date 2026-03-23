<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) && $title ? $title . ' – ' : '' }}{{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="font-sans antialiased bg-stone-50 text-stone-900 min-h-screen flex flex-col" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-10 bg-gradient-to-br from-stone-100/40 via-stone-50/80 to-stone-100/30"></div>
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(ellipse_80%_60%_at_50%_-20%,rgba(245,245,244,0.4),transparent)]"></div>
    <header class="border-b border-stone-200/80 bg-white/70 backdrop-blur-md sticky top-0 z-20">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="{{ url('/') }}" class="text-xl font-semibold tracking-tight text-stone-900">{{ config('app.name') }}</a>
                <nav class="flex items-center gap-8 text-sm font-medium text-stone-600">
                    <a href="{{ url('/') }}" class="hover:text-stone-900 transition-colors">Home</a>
                    <a href="{{ route('pricing') }}" class="hover:text-stone-900 transition-colors">Pricing</a>
                    @guest
                        <a href="{{ route('login') }}" class="hover:text-stone-900 transition-colors">Log in</a>
                        <a href="{{ route('register') }}" class="inline-flex items-center px-4 py-2.5 rounded-lg bg-stone-900 text-white text-sm font-medium hover:bg-stone-800 transition-colors">Get started</a>
                    @else
                        <a href="{{ route('dashboard') }}" class="hover:text-stone-900 transition-colors">Dashboard</a>
                    @endguest
                </nav>
            </div>
        </div>
    </header>
    <main class="flex-1 flex flex-col sm:justify-center items-center px-4 sm:px-6 py-12 sm:py-16">
        <div class="w-full sm:max-w-md">
            <div class="rounded-2xl border border-stone-200/90 bg-white/80 shadow-xl shadow-stone-200/30 overflow-hidden sm:rounded-2xl">
                <div class="px-6 py-8 sm:p-8">
                    @if (isset($title) && $title)
                        <h1 class="text-xl font-bold tracking-tight text-stone-900 mb-6">{{ $title }}</h1>
                    @endif
                    {{ $slot }}
                </div>
            </div>
        </div>
    </main>
    <footer class="border-t border-stone-200/80 py-6 px-4 sm:px-6 lg:px-8 bg-white/40 mt-auto">
        <div class="max-w-6xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-4 text-sm text-stone-500">
            <span class="font-medium text-stone-700">{{ config('app.name') }}</span>
            <div class="flex gap-8">
                <a href="{{ url('/') }}" class="hover:text-stone-700 transition-colors">Home</a>
                <a href="{{ route('pricing') }}" class="hover:text-stone-700 transition-colors">Pricing</a>
            </div>
        </div>
    </footer>
    @livewireScripts
</body>
</html>
