@extends('layouts.dply')

@section('title', __('Authorize application').' — '.config('app.name'))

@section('content')
    <x-auth-card title="{{ __('Authorize application') }}">
        <p class="mb-6 text-sm text-brand-moss leading-relaxed">
            <strong class="text-brand-forest">{{ $client->name }}</strong>
            {{ __('is requesting access to your dply account.') }}
        </p>

        @if (count($scopes) > 0)
            <div class="mb-6">
                <p class="text-sm font-medium text-brand-forest">{{ __('Scopes') }}</p>
                <ul class="mt-2 space-y-2 text-sm text-brand-moss">
                    @foreach ($scopes as $scope)
                        <li class="rounded-lg border border-brand-ink/10 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink">
                            {{ $scope->id }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-col-reverse sm:flex-row gap-3 sm:justify-end">
            <form method="post" action="{{ route('passport.authorizations.deny') }}" class="inline">
                @csrf
                @method('DELETE')
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button
                    type="submit"
                    class="w-full sm:w-auto inline-flex justify-center items-center rounded-xl border border-brand-ink/15 bg-white px-5 py-3 text-sm font-semibold text-brand-forest shadow-sm hover:bg-brand-ink/5 transition-colors"
                >
                    {{ __('Deny') }}
                </button>
            </form>
            <form method="post" action="{{ route('passport.authorizations.approve') }}" class="inline">
                @csrf
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button
                    type="submit"
                    class="w-full sm:w-auto inline-flex justify-center items-center rounded-xl bg-brand-gold px-5 py-3 text-sm font-semibold text-brand-ink shadow-md shadow-brand-gold/20 hover:bg-[#d4b24d] transition-colors"
                >
                    {{ __('Authorize') }}
                </button>
            </form>
        </div>
    </x-auth-card>
@endsection
