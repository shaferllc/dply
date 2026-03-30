<div>
    <x-livewire-validation-errors />
    <div class="mb-6 flex gap-3 rounded-xl border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-sm text-brand-forest">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-amber-600 shadow-sm ring-1 ring-amber-200/60" aria-hidden="true">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        </span>
        <p class="leading-relaxed">{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>
    </div>
    <form wire:submit="submit" class="space-y-5" autocomplete="on">
        <div class="sr-only">
            <label for="confirm_area_username">{{ __('Account email') }}</label>
            <input
                id="confirm_area_username"
                type="email"
                name="username"
                autocomplete="username"
                value="{{ auth()->user()->email }}"
                readonly
                tabindex="-1"
            />
        </div>
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" wire:model="password" class="block w-full mt-1" type="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div class="pt-2 border-t border-brand-ink/10">
            <x-primary-button class="w-full sm:w-auto min-w-[8rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Confirm') }}</span>
                <span wire:loading wire:target="submit">{{ __('Confirming…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
