<div>
    <x-livewire-validation-errors />
    <x-auth-session-status class="mb-4 rounded-lg border border-emerald-200/80 bg-emerald-50/90 px-4 py-3" :status="session('status')" />
    @if (session('error'))
        <div class="mb-4 flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600" aria-hidden="true">
                <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
            </span>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if (!empty($oauthProviders))
        <div class="mb-6">
            <p class="mb-3 flex items-center gap-2 text-sm font-medium text-brand-moss">
                <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                {{ __('Or continue with') }}
            </p>
            <div class="flex flex-col gap-2">
                @foreach ($oauthProviders as $p)
                    <a href="{{ route('oauth.redirect', ['provider' => $p['id']]) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/12 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:border-brand-sage/40 hover:bg-brand-sand/20 transition-colors">
                        <x-oauth-provider-icon :provider="$p['id']" />
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
                <x-heroicon-o-finger-print class="h-5 w-5 shrink-0 text-brand-gold" aria-hidden="true" />
                <span wire:loading.remove wire:target="quickLogin">{{ __('Quick login as TJ') }}</span>
                <span wire:loading wire:target="quickLogin">{{ __('Logging in as TJ...') }}</span>
            </button>
        </div>
    @endif

    <div class="mb-6">
        <button
            type="button"
            id="dply-passkey-login-btn"
            data-options-url="{{ $webauthnLoginOptionsUrl }}"
            data-login-url="{{ $webauthnLoginUrl }}"
            class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-brand-cream/80 px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 transition-colors disabled:opacity-60"
        >
            <x-heroicon-o-key class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Sign in with a passkey') }}
        </button>
        <p id="dply-passkey-error" class="mt-2 hidden text-sm text-red-700" role="alert"></p>
    </div>

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
                <x-heroicon-o-user-plus class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
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
