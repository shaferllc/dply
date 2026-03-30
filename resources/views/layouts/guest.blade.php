<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title . ' – ' : '' }}{{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header />

    @include('partials.auth-aside-variant')

    <main class="flex-1 w-full px-4 sm:px-6 py-10 sm:py-14 lg:py-16">
        <div class="max-w-6xl mx-auto">
            <div class="grid lg:grid-cols-12 gap-10 lg:gap-14 items-start">
                <div class="lg:col-span-5 order-2 lg:order-1">
                    <x-auth-aside :variant="$authAsideVariant" class="lg:sticky lg:top-28" />
                </div>
                <div class="lg:col-span-7 order-1 lg:order-2">
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/90 backdrop-blur-sm shadow-xl shadow-brand-forest/10 overflow-hidden ring-1 ring-brand-ink/5">
                        <div class="h-1 bg-gradient-to-r from-brand-gold via-brand-sage/70 to-brand-forest/50" aria-hidden="true"></div>
                        <div class="px-6 py-8 sm:p-9">
                            @if (isset($title))
                                <div class="flex gap-4 mb-8">
                                    @include('partials.auth-title-icon', ['variant' => $authAsideVariant])
                                    <div class="min-w-0 flex-1">
                                        <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ $title }}</h1>
                                        @include('partials.auth-subtitle', ['variant' => $authAsideVariant])
                                    </div>
                                </div>
                            @endif
                            <div class="auth-form">
                                {{ $slot }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
