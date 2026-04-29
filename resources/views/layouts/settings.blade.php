<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>[x-cloak]{display:none!important}</style>
    </head>
    <body class="font-sans antialiased bg-brand-cream text-brand-ink min-h-screen flex flex-col" style="font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;" x-data="toastStore()">
        <div class="flex flex-col flex-1 min-h-0">
            <x-site-header />

            @if (session('success') || session('error'))
                <div class="pointer-events-none fixed left-1/2 top-24 z-[70] flex w-full max-w-xl -translate-x-1/2 flex-col items-center gap-3 px-4">
                    @if (session('success'))
                        <div
                            x-data="{ show: true }"
                            x-init="setTimeout(() => show = false, 4500)"
                            x-show="show"
                            x-transition.opacity.duration.200ms
                            class="pointer-events-auto w-full rounded-2xl border border-emerald-700 bg-emerald-700 px-4 py-3 text-sm text-white shadow-xl"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <span class="pr-2">{{ session('success') }}</span>
                                <button type="button" @click="show = false" class="shrink-0 text-white/80 transition hover:text-white" aria-label="Dismiss">&times;</button>
                            </div>
                        </div>
                    @endif
                    @if (session('error'))
                        <div
                            x-data="{ show: true }"
                            x-init="setTimeout(() => show = false, 6000)"
                            x-show="show"
                            x-transition.opacity.duration.200ms
                            class="pointer-events-auto w-full rounded-2xl border border-red-300 bg-red-100 px-4 py-3 text-sm text-red-950 shadow-xl"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <span class="pr-2">{{ session('error') }}</span>
                                <button type="button" @click="show = false" class="shrink-0 text-red-900/70 transition hover:text-red-950" aria-label="Dismiss">&times;</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <main class="relative flex-1 w-full">
                <div class="pointer-events-none absolute inset-x-0 top-0 -z-0 h-[min(36rem,55vh)] bg-[radial-gradient(ellipse_90%_55%_at_50%_-10%,rgb(104_132_121/0.11),transparent_55%),radial-gradient(ellipse_50%_38%_at_100%_5%,rgb(205_169_66/0.09),transparent_48%),radial-gradient(ellipse_42%_36%_at_0%_25%,rgb(50_72_44/0.07),transparent_50%)]" aria-hidden="true"></div>
                <div class="relative z-0 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
                        <aside class="lg:col-span-3 mb-8 lg:mb-0 shrink-0">
                            <x-settings-nav />
                        </aside>
                        <div class="lg:col-span-9 min-w-0">
                            {{ $slot }}
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <x-marketing-footer />

        {{ $modals ?? '' }}

        <div class="fixed bottom-4 right-4 z-50 flex flex-col gap-2" aria-live="polite">
            <template x-for="toast in toasts" :key="toast.id">
                <div
                    x-show="true"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    :class="toast.type === 'error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-brand-ink text-brand-cream'"
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
