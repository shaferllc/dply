<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('partials.theme-head')

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>[x-cloak]{display:none!important}</style>
        @php
            $toastPosition = \App\Support\NotificationToastPosition::resolvedFor(auth()->user());
            $settingsNavLayout = auth()->user()->mergedUiPreferences()['navigation_layout']
                ?? config('user_preferences.defaults.navigation_layout', 'sidebar');
            $settingsNavLayout = in_array($settingsNavLayout, ['sidebar', 'top'], true) ? $settingsNavLayout : 'sidebar';
        @endphp
    </head>
    <body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;" x-data="toastStore({ position: @js($toastPosition) })">
        <div class="flex flex-col flex-1 min-h-0">
            <x-site-header />

            <main class="flex-1 w-full pb-28 sm:pb-32">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    @if ($settingsNavLayout === 'top')
                        {{-- overflow-visible so settings nav dropdown panels are not clipped --}}
                        <div class="space-y-8 overflow-visible">
                            <x-settings-nav variant="top" />
                            <div class="min-w-0">
                                {{ $slot }}
                            </div>
                        </div>
                    @else
                        <div class="lg:grid lg:grid-cols-12 lg:gap-10">
                            <aside class="lg:col-span-3 mb-8 lg:mb-0 shrink-0">
                                <x-settings-nav variant="sidebar" />
                            </aside>
                            <div class="lg:col-span-9 min-w-0">
                                {{ $slot }}
                            </div>
                        </div>
                    @endif
                </div>
            </main>
        </div>

        <x-marketing-footer />

        {{ $modals ?? '' }}

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
    </body>
</html>
