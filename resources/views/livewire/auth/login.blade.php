<div>
    <x-livewire-validation-errors />
    <x-auth-session-status class="mb-4 rounded-lg border border-emerald-200/80 bg-emerald-50/90 px-4 py-3" :status="session('status')" />
    @if (session('error'))
        <div class="mb-4 flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if (!empty($oauthProviders))
        <div class="mb-6">
            <p class="mb-3 flex items-center gap-2 text-sm font-medium text-brand-moss">
                <svg class="h-4 w-4 text-brand-sage shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                {{ __('Or continue with') }}
            </p>
            <div class="flex flex-col gap-2">
                @foreach ($oauthProviders as $p)
                    <a href="{{ route('oauth.redirect', ['provider' => $p['id']]) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/12 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:border-brand-sage/40 hover:bg-brand-sand/20 transition-colors">
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
    @endif

    @if (!empty($oauthProviders))
        <p class="mb-5 flex items-center justify-center gap-2 text-center text-sm text-brand-moss">
            <span class="h-px flex-1 max-w-[4rem] bg-brand-ink/10" aria-hidden="true"></span>
            {{ __('Or sign in with email') }}
            <span class="h-px flex-1 max-w-[4rem] bg-brand-ink/10" aria-hidden="true"></span>
        </p>
    @endif

    @if ($showQuickLoginButton)
        <div class="mb-5">
            <button
                type="button"
                wire:click="quickLogin"
                wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-gold/40 bg-brand-gold/10 px-4 py-2.5 text-sm font-semibold text-brand-forest shadow-sm hover:bg-brand-gold/20 transition-colors disabled:opacity-60"
            >
                <svg class="h-5 w-5 text-brand-gold shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A3 3 0 017 12.5h1m6 0h1a3 3 0 110 6h-1m-8-6a7 7 0 0112.95-2.121M8 12.5a7 7 0 0112.95-2.121M12 12v.01"/></svg>
                <span wire:loading.remove wire:target="quickLogin">{{ __('Quick login as TJ') }}</span>
                <span wire:loading wire:target="quickLogin">{{ __('Logging in as TJ...') }}</span>
            </button>
        </div>
    @endif

    <form wire:submit="submit" class="space-y-5">
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" wire:model="email" class="block w-full mt-1" type="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" wire:model="password" class="block w-full mt-1" type="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer">
                <input id="remember_me" type="checkbox" wire:model="remember" class="rounded border-brand-ink/20 text-brand-forest shadow-sm focus:ring-brand-sage">
                <span class="text-sm text-brand-moss">{{ __('Remember me') }}</span>
            </label>
            <a class="text-sm font-medium text-brand-sage hover:text-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-sage/40 focus:ring-offset-2 rounded" href="{{ route('password.request') }}">
                {{ __('Forgot your password?') }}
            </a>
        </div>
        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-3 pt-2 border-t border-brand-ink/10">
            <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 text-sm font-medium text-brand-moss hover:text-brand-ink text-center sm:text-left">
                <svg class="h-4 w-4 text-brand-sage shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                {{ __('Create an account') }}
            </a>
            <x-primary-button class="w-full sm:w-auto min-w-[8rem]" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">{{ __('Log in') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Logging in…') }}
                </span>
            </x-primary-button>
        </div>
    </form>
</div>
