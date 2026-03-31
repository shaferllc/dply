@extends('layouts.dply')

@section('title', 'Log in — '.config('app.name'))

@section('content')
    <x-auth-card
        title="Welcome back"
        subtitle="Sign in to your dply account. OAuth consent and product sign-in use this identity."
    >
        <x-slot:footer>
            @if (Route::has('register'))
                <span>New here?</span>
                <a href="{{ route('register') }}" class="font-medium text-brand-forest hover:text-brand-moss underline underline-offset-2">Create an account</a>
            @endif
        </x-slot:footer>

        <x-auth.session-status class="mb-4" />

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50/90 px-4 py-3 text-sm text-red-800" role="alert">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium text-brand-forest">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="username"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white/90 px-4 py-3 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-2 focus:ring-brand-gold/30 focus:outline-none"
                />
                @error('email')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <div class="flex items-center justify-between gap-2">
                    <label for="password" class="block text-sm font-medium text-brand-forest">Password</label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-xs font-medium text-brand-moss hover:text-brand-forest">Forgot password?</a>
                    @endif
                </div>
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white/90 px-4 py-3 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-2 focus:ring-brand-gold/30 focus:outline-none"
                />
                @error('password')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-center gap-2">
                <input
                    id="remember"
                    type="checkbox"
                    name="remember"
                    class="size-4 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-gold/40"
                />
                <label for="remember" class="text-sm text-brand-moss">Remember this device</label>
            </div>
            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-xl bg-brand-gold px-4 py-3 text-sm font-semibold text-brand-ink shadow-md shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors"
            >
                Log in
            </button>
        </form>
    </x-auth-card>
@endsection
