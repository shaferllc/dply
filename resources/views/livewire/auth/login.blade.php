<div>
    @vite(['resources/js/dply-passkeys-lazy.js'])

    <x-livewire-validation-errors />
    <x-auth-session-status class="mb-4 rounded-lg border border-emerald-200/80 bg-emerald-50/90 px-4 py-3 dark:border-emerald-900/50 dark:bg-emerald-950/40" :status="session('status')" />
    @if (session('error'))
        <div class="mb-4 flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300" role="alert">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-900/50 dark:text-red-400" aria-hidden="true">
                <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
            </span>
            <span>{{ session('error') }}</span>
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
                <input id="remember_me" type="checkbox" wire:model="remember" class="rounded border-brand-ink/20 text-brand-forest shadow-sm focus:ring-brand-sage dark:border-brand-mist/30">
                <span class="text-sm text-brand-moss">{{ __('Remember me') }}</span>
            </label>
            <a class="text-sm font-medium text-brand-sage hover:text-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-sage/40 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-zinc-900 rounded" href="{{ route('password.request') }}">
                {{ __('Forgot your password?') }}
            </a>
        </div>
        <x-primary-button class="w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="submit">{{ __('Log in') }}</span>
            <span wire:loading wire:target="submit" class="inline-flex items-center justify-center gap-2">
                <x-spinner variant="cream" />
                {{ __('Logging in…') }}
            </span>
        </x-primary-button>
    </form>

    @if ($showQuickLoginButton)
        <div class="mt-5">
            <button
                type="button"
                wire:click="quickLogin"
                wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-gold/40 bg-brand-gold/10 px-4 py-2.5 text-sm font-semibold text-brand-forest shadow-sm transition-colors hover:bg-brand-gold/20 disabled:opacity-60 dark:border-brand-gold/30 dark:bg-brand-gold/15 dark:text-brand-cream dark:hover:bg-brand-gold/25"
            >
                <x-heroicon-o-finger-print class="h-5 w-5 shrink-0 text-brand-gold dark:text-brand-gold/90" aria-hidden="true" />
                <span wire:loading.remove wire:target="quickLogin">{{ __('Quick login as TJ') }} <span class="font-medium text-brand-forest/70 dark:text-brand-cream/80">({{ __('local dev') }})</span></span>
                <span wire:loading wire:target="quickLogin">{{ __('Logging in as TJ...') }}</span>
            </button>
        </div>
    @endif

    <div class="mt-8 mb-6 flex items-center gap-3" role="separator" aria-label="{{ __('Or continue with') }}">
        <div class="h-px min-h-px flex-1 border-t border-brand-ink/10 dark:border-brand-mist/20" aria-hidden="true"></div>
        <span class="shrink-0 px-1 text-xs font-medium uppercase tracking-wide text-brand-mist">
            {{ __('Or continue with') }}
        </span>
        <div class="h-px min-h-px flex-1 border-t border-brand-ink/10 dark:border-brand-mist/20" aria-hidden="true"></div>
    </div>

    <div class="space-y-2">
        @foreach ($oauthProviders as $p)
            <a
                href="{{ route('oauth.redirect', ['provider' => $p['id']]) }}"
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-ink/12 bg-transparent px-4 py-2.5 text-sm font-medium text-brand-ink transition-colors hover:border-brand-sage/40 hover:bg-brand-sand/20 dark:border-brand-mist/25 dark:hover:bg-zinc-800/60"
            >
                <x-oauth-provider-icon :provider="$p['id']" />
                {{ $p['name'] }}
            </a>
        @endforeach

        <button
            type="button"
            id="dply-passkey-login-btn"
            class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-ink/12 bg-transparent px-4 py-2.5 text-sm font-medium text-brand-ink transition-colors hover:border-brand-sage/40 hover:bg-brand-sand/20 disabled:opacity-60 dark:border-brand-mist/25 dark:hover:bg-zinc-800/60"
        >
            <x-heroicon-o-key class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Sign in with a passkey') }}
        </button>
        <p id="dply-passkey-error" class="hidden text-center text-sm text-red-700 dark:text-red-400" role="alert"></p>
    </div>

    <p class="mt-8 border-t border-brand-ink/10 pt-6 text-center text-sm text-brand-moss dark:border-brand-mist/20">
        {{ __('New to :app?', ['app' => config('app.name')]) }}
        <a href="{{ route('register') }}" class="font-semibold text-brand-sage hover:text-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-sage/40 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-zinc-900 rounded">
            {{ __('Create an account') }}
        </a>
    </p>
</div>
