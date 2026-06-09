@extends('errors.layout')

@section('title', __('Envoy admin unavailable'))

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-amber-100 mb-6">
        <x-heroicon-o-arrows-right-left class="w-10 h-10 text-brand-forest" />
    </div>

    <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-brand-ink mb-4">
        {{ __('Envoy admin unavailable') }}
    </h1>

    <div class="text-base text-brand-moss mb-8 max-w-lg mx-auto leading-relaxed text-left whitespace-pre-line rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-5 py-4">
        {{ $message }}
    </div>
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        <p class="text-sm font-medium text-brand-ink mb-4">{{ __('What you can do') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            @if (isset($server))
                <a
                    href="{{ route('servers.edge-proxy', $server).'?tab=envoy' }}"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-forest text-brand-cream text-sm font-semibold shadow-sm hover:bg-brand-ink transition-colors"
                >
                    {{ __('Open Envoy workspace') }}
                </a>
            @endif
            <button type="button" onclick="window.location.reload()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors cursor-pointer">
                <x-heroicon-o-arrow-path class="w-4 h-4" />
                {{ __('Try again') }}
            </button>
        </div>
    </div>
@endsection
