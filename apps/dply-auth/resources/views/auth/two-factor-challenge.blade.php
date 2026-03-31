@extends('layouts.dply')

@section('title', 'Two-factor authentication — '.config('app.name'))

@section('content')
    <x-auth-card
        title="Two-factor authentication"
        subtitle="Enter the code from your authenticator app, or use a recovery code if you cannot access your device."
    >
        <x-slot:footer>
            @if (Route::has('login'))
                <a href="{{ route('login') }}" class="font-medium text-brand-forest hover:text-brand-moss underline underline-offset-2">Cancel and return to log in</a>
            @endif
        </x-slot:footer>

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50/90 px-4 py-3 text-sm text-red-800" role="alert">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="code" class="block text-sm font-medium text-brand-forest">Authentication code</label>
                <input
                    id="code"
                    type="text"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    autofocus
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white/90 px-4 py-3 text-sm text-brand-ink tracking-widest font-mono shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-2 focus:ring-brand-gold/30 focus:outline-none"
                    placeholder="000000"
                />
                @error('code')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div class="relative">
                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                    <div class="w-full border-t border-brand-ink/10"></div>
                </div>
                <div class="relative flex justify-center text-xs uppercase tracking-wide">
                    <span class="bg-white/85 px-2 text-brand-moss">or</span>
                </div>
            </div>
            <div>
                <label for="recovery_code" class="block text-sm font-medium text-brand-forest">Recovery code</label>
                <input
                    id="recovery_code"
                    type="text"
                    name="recovery_code"
                    autocomplete="off"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white/90 px-4 py-3 text-sm text-brand-ink font-mono shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-2 focus:ring-brand-gold/30 focus:outline-none"
                    placeholder="XXXX-XXXX"
                />
                @error('recovery_code')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-xl bg-brand-gold px-4 py-3 text-sm font-semibold text-brand-ink shadow-md shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors"
            >
                Continue
            </button>
        </form>
    </x-auth-card>
@endsection
