<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Migrate to {{ config('app.name') }} – in an afternoon</title>
    <meta name="description" content="Move from Laravel Forge, Ploi, or Vercel to dply in an afternoon. Import wizards bring servers, sites, env, and deploy hooks across — then you get a continuous parity view, not a one-shot handoff.">
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
        <section class="pt-16 pb-12 sm:pt-20 sm:pb-16 px-4 sm:px-6 lg:px-8 border-b border-brand-ink/10">
            <div class="max-w-3xl mx-auto text-center">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">Migration</p>
                <h1 class="mt-4 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl">Move to {{ config('app.name') }} in an afternoon</h1>
                <p class="mt-5 text-lg text-brand-moss leading-relaxed">
                    Import wizards for the platforms most teams are leaving. Bring your servers, sites, environment variables, and deploy hooks across — then keep a <strong class="text-brand-ink font-semibold">continuous parity view</strong> against the source, not a one-shot handoff that forgets where you came from.
                </p>
            </div>
        </section>

        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl grid gap-8 md:grid-cols-3">
                @foreach ($sources as $slug => $source)
                    <a href="{{ route('migrate.show', $slug) }}" class="group flex flex-col rounded-2xl border border-brand-ink/10 bg-white/85 p-8 shadow-sm hover:border-brand-sage/40 hover:shadow-md transition">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">{{ $source['kicker'] }}</p>
                        <h2 class="mt-3 text-2xl font-semibold text-brand-ink">From {{ $source['name'] }}</h2>
                        <p class="mt-3 text-sm text-brand-moss leading-relaxed flex-1">{{ $source['tagline'] }}</p>
                        <span class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-forest group-hover:text-brand-ink">
                            See the migration plan
                            <x-heroicon-o-arrow-right class="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="py-16 sm:py-20 px-4 sm:px-6 lg:px-8 border-t border-brand-ink/10 bg-white/60">
            <div class="mx-auto max-w-3xl text-center">
                <h2 class="text-2xl font-bold text-brand-ink sm:text-3xl">Why {{ config('app.name') }} after the import</h2>
                <p class="mt-4 text-brand-moss leading-relaxed">
                    Forge and Ploi own one lane (BYO PHP VMs); Vercel owns another (edge / SSR). {{ config('app.name') }} runs <strong class="text-brand-ink font-semibold">BYO + Cloud + Edge + Serverless in one org</strong> with one vault, one billing relationship, and one audit trail. So an afternoon of migration earns you the rest of the year of not stitching three panels together.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="{{ route('register') }}" class="inline-flex items-center px-6 py-3 rounded-xl bg-brand-ink text-brand-cream text-sm font-semibold hover:bg-brand-forest transition-colors shadow-md">Start 14-day trial</a>
                    <a href="{{ route('features') }}" class="inline-flex items-center px-6 py-3 rounded-xl border-2 border-brand-ink/15 bg-white text-brand-ink text-sm font-semibold hover:border-brand-sage/40 transition-colors">See all features</a>
                </div>
            </div>
        </section>
    </main>

    <x-marketing-footer />
    @livewireScripts
</body>
</html>
