<div>
    <x-livewire-validation-errors />
    <div class="mb-6 flex gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm text-brand-moss">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-brand-sage shadow-sm ring-1 ring-brand-ink/5" aria-hidden="true">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </span>
        <p class="leading-relaxed">{{ __('Forgot your password? No problem. Enter the email for your account and we will send a password reset link.') }}</p>
    </div>
    <x-auth-session-status class="mb-4 rounded-lg border border-emerald-200/80 bg-emerald-50/90 px-4 py-3" :status="session('status')" />
    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" wire:model="email" class="block w-full mt-1" type="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3 pt-2 border-t border-brand-ink/10">
            <a href="{{ route('login') }}" class="inline-flex items-center justify-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink text-center sm:text-left">
                <svg class="h-4 w-4 text-brand-sage shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('Back to log in') }}
            </a>
            <x-primary-button class="w-full sm:w-auto min-w-[8rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Email Password Reset Link') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Sending…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
