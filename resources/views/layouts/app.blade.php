<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('partials.theme-head')

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        @php
            // Driver-aware: resolves whichever broadcast connection is active —
            // reverb (local dev) or pusher → the dply realtime Worker (prod).
            $echoClient = \App\Support\EchoClientConfig::forBrowser();
        @endphp
        @if ($echoClient)
            {{-- Echo reads this at runtime (bypasses stale Vite env in public/build). Meta is fallback if window is cleared. --}}
            <meta name="dply-reverb-config" content="{{ e(json_encode($echoClient)) }}">
            <script>
                window.__DPLY_REVERB__ = @json($echoClient);
            </script>
        @endif

        <!-- Fonts (Shipwell-inspired: Instrument Sans) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @if ($echoClient)
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
        <x-impersonation-banner />
        <div class="flex flex-col flex-1 min-h-0">
            <x-site-header />

            @auth
                <div
                    id="dply-broadcast-context"
                    class="hidden"
                    aria-hidden="true"
                    data-organization-id="{{ auth()->user()->currentOrganization()?->id }}"
                    data-user-id="{{ auth()->id() }}"
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
            {{-- The global command palette (⌘K) is now mounted inside
                 <x-site-header> (rendered above) so the shortcut + search also
                 work on guest marketing pages (changelog / features / pricing)
                 when signed in — not just inside this app layout. --}}

            {{-- Shared Git provider connect modal (OAuth + PAT). Mounted here — not
                 inside page Livewire components — so teleported modal actions stay
                 bound to this component instead of the parent page. --}}
            <livewire:settings.connect-provider-modal :key="'global-connect-provider-modal'" />

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
                $hideDrawer = request()->routeIs('servers.console', 'servers.console-preview');
            @endphp
            @feature('workspace.console')
            @unless ($hideDrawer)
                <div x-data="dplyConsoleDrawer">
                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="translate-y-full opacity-0"
                        x-transition:enter-end="translate-y-0 opacity-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="translate-y-0 opacity-100"
                        x-transition:leave-end="translate-y-full opacity-0"
                        class="fixed inset-x-3 bottom-0 z-50 overflow-hidden rounded-t-2xl border border-white/10 bg-[#0b1020] shadow-2xl shadow-black/40 ring-1 ring-black/20 sm:inset-x-auto sm:right-6 sm:left-auto sm:w-[min(100%,42rem)]"
                        style="height: min(58vh, 540px);"
                    >
                        <div class="flex h-full min-h-0 flex-col">
                            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 bg-white/[0.03] px-4 py-2.5">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <div class="flex items-center gap-1.5" aria-hidden="true">
                                        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#ff5f57]"></span>
                                        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#febc2e]"></span>
                                        <span class="inline-flex h-2.5 w-2.5 rounded-full bg-[#28c840]"></span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-mono text-[11px] font-medium text-slate-200">{{ __('Console') }}</p>
                                        <p class="truncate text-[10px] text-slate-500">{{ __('SSH shell · ` toggles') }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="close()"
                                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/5 p-1.5 text-slate-400 transition hover:bg-white/10 hover:text-slate-100"
                                    title="{{ __('Close (Esc or backtick)') }}"
                                >
                                    <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                                </button>
                            </div>
                            <div class="min-h-0 flex-1">
                                <livewire:servers.console-drawer
                                    :server="$routeServer"
                                    :key="'console-drawer-'.($routeServer?->id ?? 'global').'-'.request()->path()"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            @endunless
            @endfeature

            @unless ($hideDrawer)
                @include('partials.floating-app-dock', ['routeServer' => $routeServer, 'hideDrawer' => $hideDrawer])
            @endunless

            @include('partials.docs-sidebar')

            {{-- Global deploy-status sidebar. Launcher is the floating dock "Deploys" chip;
                 fleet Deploy/Sync kickoffs focus it via deploy-console-focus. --}}
            <livewire:deploy-console-sidebar :key="'global-deploy-console-sidebar'" />

            {{-- Global feedback / bug-report sidebar. Launcher is in the floating dock. --}}
            <livewire:feedback.sidebar :key="'global-feedback-sidebar'" />
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
