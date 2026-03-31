@extends('layouts.dply')

@section('title', 'Set new password — '.config('app.name'))

@section('content')
    <x-auth-card
        title="Choose a new password"
        subtitle="Enter a strong password you have not used elsewhere."
    >
        <x-slot:footer>
            @if (Route::has('login'))
                <a href="{{ route('login') }}" class="font-medium text-brand-forest hover:text-brand-moss underline underline-offset-2">Back to log in</a>
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

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div>
                <label for="email" class="block text-sm font-medium text-brand-forest">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email', $request->email) }}"
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
                <label for="password" class="block text-sm font-medium text-brand-forest">New password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white/90 px-4 py-3 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-2 focus:ring-brand-gold/30 focus:outline-none"
                />
                @error('password')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-brand-forest">Confirm new password</label>
                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white/90 px-4 py-3 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-2 focus:ring-brand-gold/30 focus:outline-none"
                />
            </div>
            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-xl bg-brand-gold px-4 py-3 text-sm font-semibold text-brand-ink shadow-md shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors"
            >
                Update password
            </button>
        </form>
    </x-auth-card>
@endsection
