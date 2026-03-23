<div>
    <x-livewire-validation-errors />
    <p class="mb-5 text-sm text-stone-600">
        {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
    </p>
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" wire:model="password" class="block w-full mt-1" type="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div class="pt-1">
            <x-primary-button class="w-full sm:w-auto" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Confirm') }}</span>
                <span wire:loading wire:target="submit">{{ __('Confirming…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
