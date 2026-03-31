@extends('layouts.dply')

@section('title', config('app.name').' — Central sign-in')
@section('meta_description', 'dply Auth is the shared identity service for Edge, Cloud, Serverless, WordPress, and BYO — including OAuth for connected applications.')

@section('content')
    <div class="flex-1">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 lg:py-28">
            <div class="grid gap-12 lg:grid-cols-2 lg:gap-16 lg:items-center">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-brand-moss">dply Auth</p>
                    <h1 class="mt-3 text-4xl sm:text-5xl font-semibold tracking-tight text-brand-forest leading-[1.1]">
                        One identity for every dply product
                    </h1>
                    <p class="mt-6 text-lg text-brand-moss leading-relaxed max-w-xl">
                        Sign in once to access Edge, Cloud, Serverless, WordPress, and BYO. OAuth tokens issued here let connected apps act on your behalf — with your consent.
                    </p>
                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        @guest
                            @if (Route::has('login'))
                                <a
                                    href="{{ route('login') }}"
                                    class="inline-flex items-center justify-center rounded-xl bg-brand-gold px-6 py-3 text-sm font-semibold text-brand-ink shadow-md shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors"
                                >
                                    Log in
                                </a>
                            @endif
                            @if (Route::has('register'))
                                <a
                                    href="{{ route('register') }}"
                                    class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white/70 px-6 py-3 text-sm font-semibold text-brand-forest shadow-sm hover:bg-white transition-colors"
                                >
                                    Create account
                                </a>
                            @endif
                        @else
                            <a
                                href="{{ url('/home') }}"
                                class="inline-flex items-center justify-center rounded-xl bg-brand-gold px-6 py-3 text-sm font-semibold text-brand-ink shadow-md shadow-brand-gold/25 hover:bg-[#d4b24d] transition-colors"
                            >
                                Go to account
                            </a>
                        @endguest
                    </div>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white/70 backdrop-blur-md p-8 sm:p-10 shadow-lg shadow-brand-ink/[0.06]">
                    <h2 class="text-lg font-semibold text-brand-forest">What you can do here</h2>
                    <ul class="mt-6 space-y-5 text-sm text-brand-moss">
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-forest/10 text-brand-forest font-semibold text-xs">1</span>
                            <span><strong class="text-brand-forest">Sign in</strong> with email and password, plus optional two-factor authentication for your account.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-forest/10 text-brand-forest font-semibold text-xs">2</span>
                            <span><strong class="text-brand-forest">Authorize apps</strong> when a dply product requests OAuth access — review scopes before you approve.</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-forest/10 text-brand-forest font-semibold text-xs">3</span>
                            <span><strong class="text-brand-forest">Stay consistent</strong> — the same profile and security settings apply across the dply ecosystem.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
