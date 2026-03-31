@extends('layouts.dply')

@section('title', 'Account — '.config('app.name'))

@section('content')
    <div class="flex-1">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20">
            <div class="rounded-2xl border border-brand-ink/10 bg-white/80 backdrop-blur-md p-8 sm:p-10 shadow-lg shadow-brand-ink/[0.04]">
                <h1 class="text-2xl font-semibold tracking-tight text-brand-forest">Signed in</h1>
                <p class="mt-2 text-brand-moss">
                    You are logged in as <strong class="text-brand-forest">{{ auth()->user()->name }}</strong>
                    <span class="text-brand-mist">({{ auth()->user()->email }})</span>.
                </p>
                <p class="mt-6 text-sm text-brand-moss leading-relaxed">
                    This is the dply Auth home route. Product apps redirect here for login; use your provider or app UI to return to the workspace you were using.
                </p>
                <form method="POST" action="{{ route('logout') }}" class="mt-8">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-semibold text-brand-forest shadow-sm hover:bg-brand-ink/5 transition-colors"
                    >
                        Log out
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
