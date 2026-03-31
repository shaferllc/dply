@extends('layouts.dply')

@section('title', 'Confirm password — '.config('app.name'))

@section('content')
    <x-auth-card
        title="Confirm your password"
        subtitle="This is a sensitive action. Enter your current password to continue."
    >
        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50/90 px-4 py-3 text-sm text-red-800" role="alert">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="password" class="block text-sm font-medium text-brand-forest">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    autofocus
                    autocomplete="current-password"
                    class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white/90 px-4 py-3 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-2 focus:ring-brand-gold/30 focus:outline-none"
                />
                @error('password')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-xl bg-brand-gold px-4 py-3 text-sm font-semibold text-brand-ink shadow-md shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors"
            >
                Confirm
            </button>
        </form>
    </x-auth-card>
@endsection
