@props([
    'target' => 'setWorkspaceTab',
])

<div
    class="relative"
    wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150"
    wire:target="{{ $target }}"
>
    <div
        class="pointer-events-none absolute inset-x-0 top-0 z-10 hidden items-center justify-center pt-12"
        wire:loading.delay.shortest.flex
        wire:target="{{ $target }}"
        aria-live="polite"
    >
        <div class="dply-card flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-brand-ink shadow-lg">
            <x-spinner variant="forest" />
            <span>{{ __('Loading…') }}</span>
        </div>
    </div>

    {{ $slot }}
</div>
