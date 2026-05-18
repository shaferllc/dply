@extends('errors.layout')

@section('title', __('Page not found'))

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-sand/50 mb-6">
        <x-heroicon-o-map class="w-10 h-10 text-brand-moss" />
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        404
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ __('Page not found') }}
    </p>

    <p class="text-base text-brand-moss mb-4 max-w-md mx-auto leading-relaxed">
        {{ __('The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.') }}
    </p>

    {{-- Context-specific hints --}}
    @php
        $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    @endphp

    @if(!empty($errorContext['server']) && empty($errorContext['site']))
        <p class="text-sm text-brand-moss/80 mb-8 max-w-md mx-auto">
            {{ __('The server ":server" exists, but the specific page you requested could not be found.', ['server' => $errorContext['server']->name]) }}
        </p>
    @elseif(!empty($errorContext['site']))
        <p class="text-sm text-brand-moss/80 mb-8 max-w-md mx-auto">
            {{ __('The site ":site" exists on server ":server", but the specific page was not found.', ['site' => $errorContext['site']->domain, 'server' => $errorContext['server']->name ?? '']) }}
        </p>
    @else
        <div class="mb-8"></div>
    @endif
@endsection

@section('extra-actions')
    {{-- Additional 404-specific quick links --}}
    @auth
        @if(empty($errorContext['server']) && empty($errorContext['site']))
            <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm">
                <span class="text-brand-moss">{{ __('Quick links:') }}</span>
                <a href="{{ route('servers.index') }}" class="text-brand-ink hover:text-brand-forest underline decoration-brand-ink/30 underline-offset-2">
                    {{ __('Servers') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('sites.index') }}" class="text-brand-ink hover:text-brand-forest underline decoration-brand-ink/30 underline-offset-2">
                    {{ __('Sites') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('fleet.health') }}" class="text-brand-ink hover:text-brand-forest underline decoration-brand-ink/30 underline-offset-2">
                    {{ __('Fleet') }}
                </a>
            </div>
        @endif
    @endauth
@endsection
