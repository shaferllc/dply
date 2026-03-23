<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        <!-- Fonts (Shipwell-inspired: Instrument Sans) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>[x-cloak]{display:none!important}</style>
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-900" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;" x-data="toastStore()">
        <div class="min-h-screen">
            @livewire('layout.navigation')

            {{-- Global flash messages --}}
            @if (session('success'))
                <div class="mx-4 mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 flex items-center justify-between" x-data="{ show: true }" x-show="show" x-transition>
                    <span>{{ session('success') }}</span>
                    <button type="button" @click="show = false" class="text-green-600 hover:text-green-800" aria-label="Dismiss">&times;</button>
                </div>
            @endif
            @if (session('error'))
                <div class="mx-4 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 flex items-center justify-between" x-data="{ show: true }" x-show="show" x-transition>
                    <span>{{ session('error') }}</span>
                    <button type="button" @click="show = false" class="text-red-600 hover:text-red-800" aria-label="Dismiss">&times;</button>
                </div>
            @endif

            <!-- Page Heading -->
            @isset($header)
                <header class="border-b border-slate-200 bg-white">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        {{-- Toasts (from Livewire dispatch('notify')) --}}
        <div class="fixed bottom-4 right-4 z-50 flex flex-col gap-2" aria-live="polite">
            <template x-for="toast in toasts" :key="toast.id">
                <div
                    x-show="true"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    :class="toast.type === 'error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-slate-800 text-white'"
                    class="rounded-lg border px-4 py-3 shadow-lg text-sm flex items-center gap-3 min-w-[200px]"
                >
                    <span x-text="toast.message"></span>
                    <button type="button" @click="remove(toast.id)" class="shrink-0 opacity-70 hover:opacity-100" aria-label="Dismiss">&times;</button>
                </div>
            </template>
        </div>

        @livewireScripts
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('notify', (e) => {
                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: { message: e.message ?? e.detail?.message ?? 'Done', type: e.type ?? e.detail?.type ?? 'success' }
                    }));
                });
            });
        </script>
    </body>
</html>
