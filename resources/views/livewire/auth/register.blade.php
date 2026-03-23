<div>
    <x-livewire-validation-errors />
    @if (!empty($oauthProviders))
        <div class="mb-6">
            <p class="mb-3 text-sm font-medium text-stone-500">Or continue with</p>
            <div class="flex flex-col gap-2">
                @foreach ($oauthProviders as $p)
                    <a href="{{ route('oauth.redirect', ['provider' => $p['id']]) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium text-stone-700 shadow-sm hover:bg-stone-50 transition-colors">
                        @if ($p['id'] === 'github')
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/></svg>
                        @elseif ($p['id'] === 'bitbucket')
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M2.65 3A.65.65 0 002 3.65v16.7c0 .36.29.65.65.65h18.7a.65.65 0 00.65-.65V3.65A.65.65 0 0021.35 3H2.65zm4.34 5.36c0 .07.05.13.12.15l1.6.37 1.46 6.93c.02.1.1.17.2.17h2.1c.1 0 .18-.07.2-.17l1.46-6.93 1.6-.37a.16.16 0 00.12-.15v-1.2a.16.16 0 00-.12-.15l-5.24-1.2a.16.16 0 00-.2.15v1.21z"/></svg>
                        @elseif ($p['id'] === 'gitlab')
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.955 13.587l-1.342-4.135-2.664-8.189a.455.455 0 00-.867 0L16.418 9.45H7.582L4.919 1.263C4.783.84 4.262.647 3.84.784L3.045 1.01l-2.664 8.189-1.342 4.135a.924.924 0 00.331 1.023L12 23.054l11.624-8.444a.92.92 0 00.331-1.023"/></svg>
                        @endif
                        {{ $p['name'] }}
                    </a>
                @endforeach
            </div>
        </div>
        <p class="mb-5 text-center text-sm text-stone-500">Or register with email</p>
    @endif

    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" wire:model="name" class="block w-full mt-1" type="text" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" wire:model="email" class="block w-full mt-1" type="email" required autocomplete="username" />
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
        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3 pt-1">
            <a href="{{ route('login') }}" class="text-sm text-stone-600 hover:text-stone-900 text-center sm:text-left">{{ __('Already registered?') }}</a>
            <x-primary-button class="w-full sm:w-auto" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Register') }}</span>
                <span wire:loading wire:target="submit">{{ __('Creating account…') }}</span>
            </x-primary-button>
        </div>
    </form>
</div>
