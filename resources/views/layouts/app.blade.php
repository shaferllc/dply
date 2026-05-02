<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('partials.theme-head')

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        @if (filled(config('broadcasting.connections.reverb.key')))
            {{-- Echo reads this at runtime (bypasses stale Vite env in public/build). Meta is fallback if window is cleared. --}}
            @php
                $reverbOpts = config('broadcasting.connections.reverb.options', []);
                $reverbScheme = $reverbOpts['scheme'] ?? 'http';
                $reverbClient = [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => filled($reverbOpts['host'] ?? null) ? $reverbOpts['host'] : null,
                    'port' => (int) ($reverbOpts['port'] ?? ($reverbScheme === 'https' ? 443 : 8080)),
                    'scheme' => $reverbScheme,
                    'enabled' => (bool) config('broadcasting.echo_client_enabled', true),
                    'bypass_local_guard' => (bool) config('broadcasting.reverb_bypass_local_guard', false),
                ];
            @endphp
            <meta name="dply-reverb-config" content="{{ e(json_encode($reverbClient)) }}">
            <script>
                window.__DPLY_REVERB__ = @json($reverbClient);
            </script>
        @endif

        <!-- Fonts (Shipwell-inspired: Instrument Sans) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @if (filled(config('broadcasting.connections.reverb.key')))
            {{-- After Vite so a stale app-*.js that still bundled Echo cannot overwrite this. --}}
            @include('partials.reverb-echo-module')
        @endif
        @livewireStyles
        <style>[x-cloak]{display:none!important}</style>
        @php
            $toastPosition = \App\Support\NotificationToastPosition::resolvedFor(auth()->user());
        @endphp
    </head>
    <body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;" x-data="toastStore({ position: @js($toastPosition) })">
        <div class="flex flex-col flex-1 min-h-0">
            <x-site-header />

            @auth
                <div
                    id="dply-broadcast-context"
                    class="hidden"
                    aria-hidden="true"
                    data-organization-id="{{ auth()->user()->currentOrganization()?->id }}"
                ></div>
            @endauth

            <!-- Page Heading -->
            @isset($header)
                <header class="border-b border-brand-ink/10 bg-brand-cream/90 backdrop-blur-sm">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="flex-1 w-full pb-28 sm:pb-32">
                {{ $slot }}
            </main>
        </div>

        <x-marketing-footer />

        {{ $modals ?? '' }}

        {{-- Toasts (from Livewire dispatch('notify')) --}}
        <div x-bind:class="regionClass" aria-live="polite">
            <template x-for="toast in toasts" :key="toast.id">
                <div
                    x-show="true"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    :class="toast.type === 'error'
                        ? 'bg-red-50 border-red-200 text-red-800'
                        : toast.type === 'warning'
                            ? 'bg-amber-50 border-amber-200 text-amber-950'
                            : 'bg-brand-ink text-brand-cream'"
                    class="rounded-lg border px-4 py-3 shadow-lg text-sm flex items-center gap-3 min-w-[200px]"
                >
                    <span x-text="toast.message"></span>
                    <button type="button" @click="remove(toast.id)" class="shrink-0 opacity-70 hover:opacity-100" aria-label="Dismiss">&times;</button>
                </div>
            </template>
        </div>

        @include('partials.session-flash-toasts')
        @livewireScripts
        @include('partials.livewire-toast-events')
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('provision-journey-complete', (e) => {
                    const payload = Array.isArray(e) ? e[0] : e;
                    const url = payload?.url ?? payload?.detail?.url;

                    if (url) {
                        window.location.assign(url);
                    }
                });
            });
        </script>
    </body>
</html>
