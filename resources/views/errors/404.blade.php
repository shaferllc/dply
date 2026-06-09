@extends('errors.layout')

@section('card-width', 'max-w-3xl')

@section('title', __('Page not found'))

@section('content')
    @include('errors.partials.404-experience')
@endsection

@section('extra-actions')
    @php
        $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    @endphp

    @auth
        @if (empty($errorContext['server']) && empty($errorContext['site']))
            <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm">
                <span class="text-brand-moss">{{ __('Quick links:') }}</span>
                <a href="{{ route('servers.index') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Servers') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('sites.index') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Sites') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('fleet.health') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Fleet') }}
                </a>
            </div>
        @endif
    @endauth
@endsection
