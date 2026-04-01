@if ($showConfirmActionModal ?? false)
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
            <x-dialog-shell :title="$confirmActionModalTitle" max-width="md" id="confirm-action-modal-title">
                <div id="confirm-action-modal-title">
                    <p class="text-sm leading-relaxed text-brand-moss">{{ $confirmActionModalMessage }}</p>
                </div>

                <x-slot name="footer">
                    <x-secondary-button type="button" wire:click="closeConfirmActionModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>

                    @if ($confirmActionModalDestructive ?? false)
                        <x-danger-button type="button" wire:click="confirmActionModal" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="confirmActionModal">{{ $confirmActionModalConfirmLabel }}</span>
                            <span wire:loading wire:target="confirmActionModal">{{ __('Working…') }}</span>
                        </x-danger-button>
                    @else
                        <x-primary-button type="button" wire:click="confirmActionModal" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="confirmActionModal">{{ $confirmActionModalConfirmLabel }}</span>
                            <span wire:loading wire:target="confirmActionModal">{{ __('Working…') }}</span>
                        </x-primary-button>
                    @endif
                </x-slot>
            </x-dialog-shell>
        </div>
    </div>
@endif
