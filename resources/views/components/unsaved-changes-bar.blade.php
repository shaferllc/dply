@props([
    'message',
    'saveAction',
    'discardAction' => 'discardUnsavedChanges',
    /** Comma-separated Livewire property paths; omit for whole-component dirty state */
    'targets' => null,
    'saveDisabled' => false,
    'saveLabel' => null,
    'discardLabel' => null,
])

@php
    $saveLabel = $saveLabel ?? __('Save');
    $discardLabel = $discardLabel ?? __('Discard');
@endphp

{{--
    Outer node: keep `hidden` + wire:dirty.remove.class until dirty (avoids FOUC). Do not use flex/display
    utilities here—they fight Livewire dirty CSS. Prefer leaf paths in `targets` so JSON.stringify compares
    primitives; whole nested objects vs Alpine proxies often read “dirty” on first paint.
--}}
<div
    {{ $attributes->class([
        'hidden',
        'pointer-events-auto fixed left-1/2 z-40 w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 rounded-2xl border border-brand-mist/80 bg-white shadow-lg shadow-brand-forest/10',
        'bottom-24 sm:bottom-28',
    ]) }}
    @if (filled($targets))
        wire:target="{{ $targets }}"
    @endif
    wire:dirty.remove.class="hidden"
    role="region"
    aria-label="{{ __('Unsaved changes') }}"
>
    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-5 sm:py-3.5">
        <p class="text-sm text-brand-moss">{{ $message }}</p>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:gap-2.5">
            <button
                type="button"
                wire:click="{{ $discardAction }}"
                class="inline-flex items-center justify-center rounded-xl border border-brand-ink/20 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
            >
                <span wire:loading.remove wire:target="{{ $discardAction }}">{{ $discardLabel }}</span>
                <span wire:loading wire:target="{{ $discardAction }}" class="opacity-80">{{ __('Resetting…') }}</span>
            </button>
            <button
                type="button"
                wire:click="{{ $saveAction }}"
                @disabled($saveDisabled)
                class="inline-flex items-center justify-center rounded-xl bg-brand-sage px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-sage/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/50 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="{{ $saveAction }}">{{ $saveLabel }}</span>
                <span wire:loading wire:target="{{ $saveAction }}" class="inline-flex items-center gap-1.5">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>
</div>
