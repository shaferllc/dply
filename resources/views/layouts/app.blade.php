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

        @auth
            {{-- Global SSH console drawer.

                 Renders on every authenticated page. When the current route
                 has a bound Server (e.g. /servers/{id}/…), the drawer opens
                 directly into that server's console. Otherwise it shows a
                 picker of the org's ready servers. The operator's last pick
                 persists in session so non-server pages feel continuous.

                 Hidden on the Console page itself (the page IS the console).
                 Toggle via the floating button or backtick (`) when not
                 focused in an input. Esc closes. --}}
            @php
                $routeServer = request()->route('server');
                if (! $routeServer instanceof \App\Models\Server) {
                    $routeServer = null;
                }
                $hideDrawer = request()->routeIs('servers.console');
            @endphp
            @unless ($hideDrawer)
                <div
                    x-data="{
                        open: false,
                        init() {
                            this.open = localStorage.getItem('dply.consoleDrawer.open') === '1';
                            document.addEventListener('keydown', (e) => {
                                const tag = (e.target.tagName || '').toLowerCase();
                                const inInput = ['input', 'textarea', 'select'].includes(tag) || e.target.isContentEditable;
                                if (e.key === '`' && !inInput && !e.metaKey && !e.ctrlKey && !e.altKey) {
                                    e.preventDefault();
                                    this.toggle();
                                } else if (e.key === 'Escape' && this.open) {
                                    this.close();
                                }
                            });
                        },
                        toggle() {
                            this.open = !this.open;
                            localStorage.setItem('dply.consoleDrawer.open', this.open ? '1' : '0');
                            if (this.open) {
                                this.$nextTick(() => window.dispatchEvent(new CustomEvent('dply-console-drawer-opened')));
                            }
                        },
                        close() {
                            this.open = false;
                            localStorage.setItem('dply.consoleDrawer.open', '0');
                        },
                    }"
                >
                    <button
                        type="button"
                        x-on:click="toggle()"
                        x-show="!open"
                        class="fixed bottom-4 right-4 z-40 inline-flex items-center gap-1.5 rounded-full bg-brand-ink px-3.5 py-2 text-xs font-semibold text-white shadow-lg shadow-brand-ink/30 hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        title="{{ __('Open SSH console — backtick (`) toggles') }}"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/>
                            <path d="M5.5 7.5l2.5 2.5-2.5 2.5"/>
                            <path d="M9.5 13h3"/>
                        </svg>
                        {{ __('Console') }}
                        <kbd class="ml-1 hidden sm:inline-flex items-center rounded bg-white/15 px-1 py-0.5 text-[10px]">`</kbd>
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="translate-y-full opacity-0"
                        x-transition:enter-end="translate-y-0 opacity-100"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="translate-y-0 opacity-100"
                        x-transition:leave-end="translate-y-full opacity-0"
                        class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-700 bg-[#0b1020] shadow-2xl"
                        style="height: min(55vh, 520px);"
                    >
                        <div class="flex h-full flex-col">
                            <div class="flex items-center justify-between gap-2 border-b border-slate-800 bg-slate-900/60 px-3 py-1.5">
                                <div class="flex items-center gap-2 text-xs font-semibold text-slate-100">
                                    <svg class="h-3.5 w-3.5 text-emerald-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/>
                                        <path d="M5.5 7.5l2.5 2.5-2.5 2.5"/>
                                        <path d="M9.5 13h3"/>
                                    </svg>
                                    <span>{{ __('Console') }}</span>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="close()"
                                    class="rounded p-1 text-slate-400 hover:bg-white/10 hover:text-slate-100"
                                    title="{{ __('Close (Esc or backtick)') }}"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M5 5l10 10"/><path d="M15 5L5 15"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="flex-1 min-h-0">
                                <livewire:servers.console-drawer
                                    :server="$routeServer"
                                    :key="'console-drawer-'.($routeServer?->id ?? 'global').'-'.request()->path()"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            @endunless
        @endauth

        @include('partials.session-flash-toasts')
        @livewireScripts
        @include('partials.livewire-toast-events')
        @stack('scripts')
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
