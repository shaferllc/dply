<div>
    <x-livewire-validation-errors />
    <p class="mb-5 text-sm text-stone-600">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link.') }}
    </p>
    <x-auth-session-status class="mb-4" :status="session('status')" />
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" wire:model="email" class="block w-full mt-1" type="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-end gap-3 pt-1">
            <a href="{{ route('login') }}" class="text-sm text-stone-600 hover:text-stone-900 text-center">Back to log in</a>
            <x-primary-button class="w-full sm:w-auto" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Email Password Reset Link') }}</span>
                <span wire:loading wire:target="submit">{{ __('Sending…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
