@extends('errors.layout')

@section('title', __('Access forbidden'))

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-amber-50 mb-6">
        <x-heroicon-o-shield-exclamation class="w-10 h-10 text-amber-500" />
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        403
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ __('Access forbidden') }}
    </p>

    <p class="text-base text-brand-moss mb-4 max-w-md mx-auto leading-relaxed">
        {{ __('You do not have permission to access this resource. If you believe this is an error, please contact your administrator.') }}
    </p>

    {{-- Context-specific hints --}}
    @php
        $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    @endphp

    @if(!empty($errorContext['server']))
        <p class="text-sm text-amber-600/80 mb-8 max-w-md mx-auto">
            {{ __('You do not have access to the server ":server".', ['server' => $errorContext['server']->name]) }}
        </p>
    @elseif(!empty($errorContext['site']))
        <p class="text-sm text-amber-600/80 mb-8 max-w-md mx-auto">
            {{ __('You do not have access to the site ":site".', ['site' => $errorContext['site']->domain]) }}
        </p>
    @elseif(!empty($errorContext['organization']))
        <p class="text-sm text-amber-600/80 mb-8 max-w-md mx-auto">
            {{ __('You are not a member of ":org".', ['org' => $errorContext['organization']->name]) }}
        </p>
    @else
        <div class="mb-8"></div>
    @endif
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        <p class="text-sm font-medium text-brand-ink mb-4">{{ __('Where to next?') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            @auth
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors">
                    <x-heroicon-o-squares-2x2 class="w-4 h-4" />
                    {{ __('Dashboard') }}
                </a>
                <a href="{{ route('servers.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                    <x-heroicon-o-server class="w-4 h-4" />
                    {{ __('My servers') }}
                </a>
            @else
                <a href="{{ route('login') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors">
                    <x-heroicon-o-arrow-right-end-on-rectangle class="w-4 h-4" />
                    {{ __('Log in') }}
                </a>
            @endauth
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                <x-heroicon-o-home class="w-4 h-4" />
                {{ __('Home') }}
            </a>
        </div>

        @include('errors.partials.search')
    </div>
@endsection
