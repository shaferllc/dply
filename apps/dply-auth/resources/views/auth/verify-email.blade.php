@extends('layouts.dply')

@section('title', 'Verify email — '.config('app.name'))

@section('content')
    <x-auth-card
        title="Verify your email"
        subtitle="Before continuing, confirm your address using the link we emailed you. If you did not receive it, we can send another."
    >
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

        <div class="space-y-4 text-sm text-brand-moss leading-relaxed">
            <p>Thanks for signing up. Please check your inbox and click the verification link.</p>
        </div>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
            @csrf
            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-xl border border-brand-ink/15 bg-white px-4 py-3 text-sm font-semibold text-brand-forest shadow-sm hover:bg-brand-ink/5 transition-colors"
            >
                Resend verification email
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit" class="w-full text-center text-sm text-brand-moss hover:text-brand-forest underline underline-offset-2">
                Log out
            </button>
        </form>
    </x-auth-card>
@endsection
