@if ($showProductionWriteConfirm)
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/50 p-4"
        x-data
        x-init="
            document.body.classList.add('overflow-y-hidden');
            $el.querySelector('input')?.focus();
            return () => document.body.classList.remove('overflow-y-hidden');
        "
        @keydown.escape.window="$wire.closeProductionWriteConfirm()"
    >
        <div class="w-full max-w-md rounded-2xl border border-amber-600/30 bg-white p-6 shadow-xl dark:bg-zinc-900" role="dialog" aria-modal="true">
            <h2 class="text-lg font-semibold text-brand-ink">{{ $productionWriteConfirmTitle }}</h2>
            <p class="mt-2 text-sm text-brand-moss">{{ $productionWriteConfirmMessage }}</p>
            <div class="mt-4">
                <label for="production_write_confirm" class="block text-sm font-medium text-brand-ink">{{ __('Type PRODUCTION') }}</label>
                <x-text-input
                    id="production_write_confirm"
                    type="text"
                    wire:model="productionWriteConfirmInput"
                    class="mt-1 w-full font-mono uppercase"
                    autocomplete="off"
                    wire:keydown.enter="confirmProductionWrite"
                />
                @error('productionWriteConfirmInput')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <x-secondary-button type="button" wire:click="closeProductionWriteConfirm">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-danger-button type="button" wire:click="confirmProductionWrite" wire:loading.attr="disabled">
                    {{ __('Continue') }}
                </x-danger-button>
            </div>
        </div>
    </div>
@endif
