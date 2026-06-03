@props([
    'message',
    'saveAction',
    'discardAction' => 'discardUnsavedChanges',
    /** Comma-separated Livewire property paths; omit for whole-component dirty state */
    'targets' => null,
    'saveDisabled' => false,
    'saveLabel' => null,
    'discardLabel' => null,
    /**
     * Livewire bool property (e.g. pipeline_form_edits_pending) for modal / conditional forms.
     * Uses Alpine x-bind alongside wire:dirty so the bar is not hidden when only the modal is dirty.
     */
    'formPendingWire' => null,
])

@php
    $saveLabel = $saveLabel ?? __('Save');
    $discardLabel = $discardLabel ?? __('Discard');
@endphp

{{--
    Default `hidden` + wire:dirty.class keeps the bar off until a targeted field changes.
    `formPendingWire` adds dply-unsaved-bar-visible when open modal forms differ from their snapshot
    (wire:dirty alone would re-hide the bar because rollout fields are still clean).

    z-[110] sits above app modals (z-[100]) so the bar stays visible while editing in a modal.
--}}
<div
    {{ $attributes->class([
        'dply-unsaved-bar',
        'hidden',
        'pointer-events-auto fixed left-1/2 z-[110] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 rounded-2xl border border-brand-mist/80 bg-white shadow-lg shadow-brand-forest/10',
        'bottom-24 sm:bottom-28',
    ]) }}
    @if (filled($targets))
        wire:target="{{ $targets }}"
        wire:dirty.class="dply-unsaved-bar-visible"
    @endif
    @if (filled($formPendingWire))
        {{-- Alpine drives its OWN show class (…-pending) so its object binding
             never clobbers the wire:dirty.class (…-visible) that field/checkbox
             edits set — both classes independently un-hide the bar. --}}
        x-data
        x-bind:class="{ 'dply-unsaved-bar-pending': $wire.{{ $formPendingWire }} }"
    @endif
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
