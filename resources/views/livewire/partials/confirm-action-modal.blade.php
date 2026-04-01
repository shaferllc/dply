@if ($showConfirmActionModal ?? false)
    @php
        $confirmButtonClass = ($confirmActionModalDestructive ?? false)
            ? 'inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:opacity-50'
            : 'inline-flex items-center justify-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-50';
    @endphp
    <div
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-action-modal-title"
        x-data
        x-on:keydown.escape.window="$wire.closeConfirmActionModal()"
    >
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeConfirmActionModal"></div>
        <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <div
                class="relative w-full max-w-md rounded-2xl border border-brand-ink/10 bg-white shadow-xl"
                wire:click.stop
            >
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                    <h2 id="confirm-action-modal-title" class="text-lg font-semibold text-brand-ink">{{ $confirmActionModalTitle }}</h2>
                </div>
                <div class="px-6 py-5 sm:px-7">
                    <p class="text-sm leading-relaxed text-brand-moss">{{ $confirmActionModalMessage }}</p>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                    <button
                        type="button"
                        wire:click="closeConfirmActionModal"
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmActionModal"
                        wire:loading.attr="disabled"
                        class="{{ $confirmButtonClass }}"
                    >
                        <span wire:loading.remove wire:target="confirmActionModal">{{ $confirmActionModalConfirmLabel }}</span>
                        <span wire:loading wire:target="confirmActionModal">{{ __('Working…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
