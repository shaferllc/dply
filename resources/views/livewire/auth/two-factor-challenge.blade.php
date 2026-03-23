<div>
    <x-livewire-validation-errors />
    <p class="mb-5 text-sm text-stone-600">
        {{ __('Please enter the code from your authenticator app, or one of your recovery codes.') }}
    </p>
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="code" :value="__('Code')" />
            <x-text-input
                id="code"
                wire:model="code"
                class="block w-full mt-1 font-mono text-lg tracking-widest"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                autofocus
                placeholder="000000"
            />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>
        <div class="pt-1">
            <x-primary-button class="w-full sm:w-auto" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Continue') }}</span>
                <span wire:loading wire:target="submit">{{ __('Verifying…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
