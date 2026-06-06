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
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="translate-y-full opacity-0"
                        x-transition:enter-end="translate-y-0 opacity-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="translate-y-0 opacity-100"
                        x-transition:leave-end="translate-y-full opacity-0"
                        class="fixed inset-x-3 bottom-0 z-40 overflow-hidden rounded-t-2xl border border-brand-ink/10 bg-white shadow-2xl shadow-brand-ink/15 sm:inset-x-auto sm:right-6 sm:left-auto sm:w-[min(100%,42rem)]"
                        style="height: min(58vh, 540px);"
                    >
                        <div class="flex h-full min-h-0 flex-col">
                            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/60 px-4 py-2.5">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                        <x-heroicon-o-command-line class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-brand-ink">{{ __('Console') }}</p>
                                        <p class="truncate text-[11px] text-brand-moss">{{ __('SSH shell — backtick (`) toggles') }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="close()"
                                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white p-1.5 text-brand-moss shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
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
            @if (workspace_console_preview_active() && ! $hideDrawer)
                <div
                    x-data="{ open: false }"
                    x-on:keydown.escape.window="open = false"
                >
                    <button
                        type="button"
                        x-on:click="open = true"
                        class="fixed bottom-4 right-4 z-40 inline-flex items-center gap-1.5 rounded-full border border-brand-ink/15 bg-white/95 px-3.5 py-2 text-xs font-semibold text-brand-ink shadow-lg shadow-brand-ink/10 backdrop-blur hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        title="{{ __('Browser console — coming soon') }}"
                    >
                        <x-heroicon-o-command-line class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                        {{ __('Console') }}
                        <span class="rounded-full bg-brand-sand px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Soon') }}</span>
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        class="fixed inset-0 z-[100] overflow-y-auto"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="console-preview-modal-title"
                    >
                        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="open = false"></div>
                        <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                            <div class="relative w-full max-w-xl">
                                <button
                                    type="button"
                                    x-on:click="open = false"
                                    class="absolute -top-3 end-0 z-10 inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-semibold text-brand-moss shadow-sm hover:bg-brand-sand/40 hover:text-brand-ink"
                                    aria-label="{{ __('Close') }}"
                                >
                                    <x-heroicon-o-x-mark class="h-4 w-4" />
                                    {{ __('Close') }}
                                </button>
                                <x-console-preview-panel compact :server="$routeServer" />
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Global documentation sidebar — opens from page headers and x-docs-link. --}}
            <div
                x-data="{
                    open: false,
                    init() {
                        window.addEventListener('dply-docs-open', (event) => {
                            this.open = true;
                            const detail = event.detail ?? {};
                            this.$nextTick(() => {
                                Livewire.dispatch('docs-sidebar-open', {
                                    slug: detail.slug ?? null,
                                    docRoute: detail.docRoute ?? null,
                                    docSlug: detail.docSlug ?? null,
                                });
                            });
                        });
                        window.addEventListener('dply-docs-close', () => this.close());
                        document.addEventListener('keydown', (event) => {
                            if (event.key === 'Escape' && this.open) {
                                this.close();
                            }
                        });
                    },
                    close() {
                        this.open = false;
                        Livewire.dispatch('docs-sidebar-close');
                    },
                }"
                x-on:dply-docs-close.window="close()"
            >
                <div
                    x-show="open"
                    x-cloak
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 z-50 bg-brand-ink/40"
                    x-on:click="close()"
                    aria-hidden="true"
                ></div>

                <div
                    x-show="open"
                    x-cloak
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                    class="fixed inset-y-0 right-0 z-50 w-full border-l border-brand-ink/10 shadow-2xl sm:w-[420px] dark:border-brand-mist/20"
                    role="dialog"
                    aria-modal="true"
                    aria-label="{{ __('Documentation') }}"
                >
                    <livewire:docs.sidebar :key="'docs-sidebar-'.request()->path()" />
                </div>
            </div>
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
