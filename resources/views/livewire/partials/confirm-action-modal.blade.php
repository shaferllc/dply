@if ($showConfirmActionModal ?? false)
    @teleport('body')
    <div
        class="fixed inset-0 isolate z-[100] overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-action-modal-title"
        x-data="{
            focusables() {
                let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
                return [...$el.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled'))
            },
            firstFocusable() { return this.focusables()[0] },
            lastFocusable() { return this.focusables().slice(-1)[0] },
            nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
            prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
            nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
            prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1 },
            close() {
                document.body.classList.remove('overflow-y-hidden')
                $wire.closeConfirmActionModal()
            },
        }"
        x-init="document.body.classList.add('overflow-y-hidden'); setTimeout(() => firstFocusable()?.focus(), 100)"
        x-on:keydown.escape.window="close()"
        x-on:keydown.tab.prevent="!$event.shiftKey && nextFocusable().focus()"
        x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    >
        <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
        <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <x-dialog-shell :title="$confirmActionModalTitle" title-id="confirm-action-modal-title" max-width="md">
                <div>
                    <p class="text-sm leading-relaxed text-brand-moss">{{ $confirmActionModalMessage }}</p>
                </div>

                <x-slot name="footer">
                    <x-secondary-button type="button" x-on:click="close()">
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
    @endteleport
@endif
