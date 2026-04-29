@php
    $card = 'dply-card overflow-hidden';
@endphp

<div class="pointer-events-none fixed left-1/2 top-24 z-[70] flex w-full max-w-xl -translate-x-1/2 flex-col items-center gap-3 px-4">
    @if (session('success') || $flash_success)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 4500)"
            x-show="show"
            x-transition.opacity.duration.200ms
            class="pointer-events-auto w-full rounded-2xl border border-emerald-700 bg-emerald-700 px-4 py-3 text-sm text-white shadow-xl"
        >
            <div class="flex items-start justify-between gap-3">
                <p class="pr-2">{{ $flash_success ?? session('success') }}</p>
                <button type="button" @click="show = false" class="shrink-0 text-white/80 transition hover:text-white" aria-label="{{ __('Dismiss message') }}">
                    <x-heroicon-o-x-mark class="h-4 w-4" />
                </button>
            </div>
        </div>
    @endif
    @if (session('error') || $flash_error)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 6000)"
            x-show="show"
            x-transition.opacity.duration.200ms
            class="pointer-events-auto w-full rounded-2xl border border-amber-300 bg-amber-100 px-4 py-3 text-sm text-amber-950 shadow-xl"
        >
            <div class="flex items-start justify-between gap-3">
                <p class="pr-2">{{ $flash_error ?? session('error') }}</p>
                <button type="button" @click="show = false" class="shrink-0 text-amber-900/70 transition hover:text-amber-950" aria-label="{{ __('Dismiss message') }}">
                    <x-heroicon-o-x-mark class="h-4 w-4" />
                </button>
            </div>
        </div>
    @endif
    @if (isset($command_error) && $command_error)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 6000)"
            x-show="show"
            x-transition.opacity.duration.200ms
            class="pointer-events-auto w-full rounded-2xl border border-red-300 bg-red-100 px-4 py-3 text-sm text-red-950 shadow-xl"
        >
            <div class="flex items-start justify-between gap-3">
                <p class="pr-2">{{ $command_error }}</p>
                <button type="button" @click="show = false" class="shrink-0 text-red-900/70 transition hover:text-red-950" aria-label="{{ __('Dismiss message') }}">
                    <x-heroicon-o-x-mark class="h-4 w-4" />
                </button>
            </div>
        </div>
    @endif
</div>

@if (isset($command_output) && $command_output)
    <div class="{{ $card }}">
        <div class="border-b border-brand-ink/10 px-5 py-3 text-sm font-medium text-brand-ink">{{ __('Command output') }}</div>
        <pre class="max-h-96 overflow-x-auto bg-brand-ink p-4 text-sm text-emerald-400/95">{{ $command_output }}</pre>
    </div>
@endif
