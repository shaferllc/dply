@php
    $authProvider = $authProvider ?? \App\Support\Servers\ManagedDatabaseCatalogFailure::provider();
    $authTitle = \App\Support\Providers\ProviderAuthFailure::title($authProvider);
    $authMessage = $this->managedDatabaseCatalogError()
        ?? \App\Support\Providers\ProviderAuthFailure::message($authProvider);
@endphp
<div class="mt-2 rounded-xl border border-rose-400 bg-rose-100/90 p-3 ring-2 ring-rose-300/70">
    <p class="text-sm font-semibold text-rose-950">{{ $authTitle }}</p>
    <p class="mt-1 text-xs leading-relaxed text-rose-900">{{ $authMessage }}</p>
    @if (method_exists($this, 'openProviderCredentialModal'))
        <div class="mt-2.5 flex flex-wrap gap-2">
            @if ($authProvider === 'digitalocean'
                && filled(config('services.digitalocean_oauth.client_id'))
                && filled(config('services.digitalocean_oauth.client_secret')))
                <a href="{{ route('credentials.oauth.digitalocean.redirect') }}"
                    class="inline-flex items-center gap-1 rounded-md bg-[#0080FF] px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-[#0066CC]">
                    {{ __('Reconnect DigitalOcean') }}
                </a>
            @endif
            <button type="button" wire:click="openProviderCredentialModal(@js($authProvider))"
                class="inline-flex items-center gap-1 rounded-md bg-rose-800 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-rose-900">
                <x-heroicon-o-key class="h-3.5 w-3.5" />
                {{ __('Add a new token') }}
            </button>
        </div>
    @endif
</div>
