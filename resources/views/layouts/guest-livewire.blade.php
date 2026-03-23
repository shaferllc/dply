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
<body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-marketing-nav />

    <main class="flex-1 flex flex-col sm:justify-center items-center px-4 sm:px-6 py-12 sm:py-16">
        <div class="w-full sm:max-w-md">
            <div class="rounded-2xl border border-brand-ink/10 bg-white/85 backdrop-blur-sm shadow-xl shadow-brand-forest/10 overflow-hidden sm:rounded-2xl">
                <div class="px-6 py-8 sm:p-8">
                    @if (isset($title) && $title)
                        <h1 class="text-xl font-bold tracking-tight text-brand-ink mb-6">{{ $title }}</h1>
                    @endif
                    {{ $slot }}
                </div>
            </div>
        </div>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
