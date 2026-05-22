@php
    // Enabled Git OAuth providers (GitHub / GitLab / Bitbucket) — the same
    // list the Settings → Source Control page links. Surfacing it here lets
    // an operator connect a missing provider without leaving a repo picker.
    $providers = \App\Http\Controllers\Auth\OAuthController::getEnabledProviders();
@endphp

<x-modal name="connect-provider" :show="false" maxWidth="md" focusable>
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Source control') }}</p>
        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Connect a repository provider') }}</h2>
        <p class="mt-2 text-sm leading-6 text-brand-moss">
            {{ __('Link GitHub, GitLab, or Bitbucket to browse and deploy private repositories. You\'ll authorize on the provider, then return to dply.') }}
        </p>
    </div>

    <div class="space-y-2 px-6 py-6">
        @forelse ($providers as $provider)
            {{-- The plain href is the no-JS fallback; the click handler appends
                 the current page as return_to (captured live, so it's right
                 even after a Livewire re-render) so OAuth lands back here. --}}
            <a href="{{ route('oauth.redirect', ['provider' => $provider['id']]) }}"
               x-on:click.prevent="window.location.href = @js(route('oauth.redirect', ['provider' => $provider['id']])) + '?return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
               class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 transition hover:border-brand-sage/40 hover:bg-brand-sand/30">
                <x-oauth-provider-icon :provider="$provider['id']" size="h-6 w-6" />
                <span class="text-sm font-semibold text-brand-ink">{{ __('Connect :name', ['name' => $provider['name']]) }}</span>
                <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 text-brand-moss" />
            </a>
        @empty
            <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">
                {{ __('No Git OAuth providers are enabled for this application. Ask an administrator to configure GitHub, GitLab, or Bitbucket OAuth.') }}
            </div>
        @endforelse

        <p class="pt-1 text-xs leading-5 text-brand-moss/70">
            {{ __('Already connected accounts are managed under Settings → Source control. Connecting from here returns you there once authorized.') }}
        </p>
    </div>

    <div class="flex justify-end border-t border-brand-ink/10 px-6 py-4">
        <button type="button" x-on:click="$dispatch('close-modal', 'connect-provider')"
                class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
            {{ __('Close') }}
        </button>
    </div>
</x-modal>
