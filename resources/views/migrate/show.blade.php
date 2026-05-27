<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Migrate from {{ $source['name'] }} – {{ config('app.name') }}</title>
    <meta name="description" content="{{ $source['meta'] }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="font-sans antialiased bg-brand-cream text-brand-ink" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-site-header />

    <main>
        {{-- Hero --}}
        <section class="pt-16 pb-12 sm:pt-20 sm:pb-16 px-4 sm:px-6 lg:px-8 border-b border-brand-ink/10">
            <div class="max-w-4xl mx-auto">
                <nav class="text-xs font-semibold uppercase tracking-wider text-brand-moss/80" aria-label="Breadcrumb">
                    <a href="{{ route('migrate.index') }}" class="hover:text-brand-ink">Migrate</a>
                    <span class="mx-2 text-brand-moss/40">/</span>
                    <span class="text-brand-sage">{{ $source['name'] }}</span>
                </nav>
                <h1 class="mt-6 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">{{ $source['headline'] }}</h1>
                <p class="mt-5 text-lg text-brand-moss leading-relaxed max-w-3xl">
                    {{ $source['hero'] }}
                </p>

                <div class="mt-10 flex flex-col sm:flex-row gap-3 sm:items-center">
                    <a href="{{ $source['cta_href'] }}" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest transition-colors shadow-md">
                        <x-heroicon-o-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                        {{ $source['cta_label'] }}
                    </a>
                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl border-2 border-brand-ink/15 bg-white text-brand-ink text-sm font-semibold hover:border-brand-sage/40 transition-colors">
                        <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                        Start trial first
                    </a>
                </div>
                <p class="mt-3 text-xs text-brand-moss/80">Already signed in? You'll go straight to the import wizard. Otherwise, log in and we'll bring you back here.</p>
            </div>
        </section>

        {{-- What we move / what stays --}}
        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl grid gap-8 lg:grid-cols-2">
                <div class="rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm">
                    <h2 class="text-xl font-semibold text-brand-ink flex items-center gap-2">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-brand-sage" aria-hidden="true" />
                        What the wizard brings across
                    </h2>
                    <ul class="mt-6 space-y-3 text-sm text-brand-moss">
                        @foreach ($source['moves'] as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                <span>{!! $item !!}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-8">
                    <h2 class="text-xl font-semibold text-brand-ink flex items-center gap-2">
                        <x-heroicon-o-information-circle class="h-5 w-5 text-brand-forest" aria-hidden="true" />
                        What you keep doing yourself
                    </h2>
                    <ul class="mt-6 space-y-3 text-sm text-brand-moss">
                        @foreach ($source['stays'] as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-forest" aria-hidden="true"></span>
                                <span>{!! $item !!}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        {{-- Three-step flow --}}
        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/60">
            <div class="mx-auto max-w-6xl">
                <h2 class="text-3xl font-bold tracking-tight text-brand-ink sm:text-4xl text-center">The afternoon plan</h2>
                <p class="mt-4 text-center text-brand-moss max-w-2xl mx-auto">Three steps. The wizard does the heavy lifting; you confirm and cut over when the parity view is green.</p>

                <ol class="mt-12 grid gap-6 lg:grid-cols-3">
                    @foreach ($source['steps'] as $i => $step)
                        <li class="rounded-2xl border border-brand-ink/10 bg-white/90 p-6 shadow-sm">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand-ink text-brand-cream text-sm font-bold">{{ $i + 1 }}</span>
                            <h3 class="mt-4 text-lg font-semibold text-brand-ink">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $step['body'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Parity hook --}}
        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10">
            <div class="mx-auto max-w-4xl rounded-3xl border border-brand-ink/10 bg-brand-ink text-brand-cream px-8 py-12 sm:px-12">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-gold">Not a one-shot import</p>
                <h2 class="mt-3 text-2xl sm:text-3xl font-bold tracking-tight">{{ $source['parity_title'] }}</h2>
                <p class="mt-4 text-brand-sand/90 leading-relaxed max-w-2xl">
                    {{ $source['parity_body'] }}
                </p>
            </div>
        </section>

        {{-- CTA footer --}}
        <section class="py-20 px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-2xl font-bold tracking-tight text-brand-ink sm:text-3xl">Ready when you are</h2>
                <p class="mt-3 text-brand-moss">No card to start. Run the wizard against a single staging server first if you want to see the shape.</p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="{{ $source['cta_href'] }}" class="inline-flex items-center px-6 py-3 rounded-xl bg-brand-gold text-brand-ink text-sm font-semibold shadow-lg shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors">{{ $source['cta_label'] }}</a>
                    <a href="{{ route('migrate.index') }}" class="inline-flex items-center px-6 py-3 rounded-xl border-2 border-brand-ink/15 bg-white text-brand-ink text-sm font-semibold hover:border-brand-sage/40 transition-colors">Other platforms</a>
                </div>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
