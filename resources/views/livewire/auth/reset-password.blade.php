<div>
    <x-livewire-validation-errors />
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <div class="relative mt-1">
                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </span>
                <x-text-input id="email" wire:model="email" class="block w-full ps-10" type="email" required autofocus autocomplete="username" />
            </div>
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" wire:model="password" class="block w-full mt-1" type="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" wire:model="password_confirmation" class="block w-full mt-1" type="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>
        <div class="pt-2 border-t border-brand-ink/10">
            <x-primary-button class="w-full sm:w-auto min-w-[10rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Reset Password') }}</span>
                <span wire:loading wire:target="submit">{{ __('Resetting…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
