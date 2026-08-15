{{-- Expects $server available; same copy across server workspace areas when SSH/provision is not ready. --}}
@php
    // A production-mirror row is a real, live host that this control plane holds
    // no SSH key for — serverOpsReady() is false and always will be. Rendering
    // the plain provisioning wall for it reads as "your server isn't set up
    // yet", which is wrong twice over: the host is provisioned, and nothing in
    // connection settings can fix it. Same derivation WorkspaceMonitor uses.
    $isProductionMirror = data_get($server->meta ?? [], 'production_data_mirror') === true;
    $productionMirrorConnected = $isProductionMirror && production_data_mirror_connected();
    $productionMirrorBaseUrl = $isProductionMirror
        ? rtrim((string) data_get($server->meta ?? [], 'production_base_url'), '/')
        : '';
    $productionMirrorHost = $productionMirrorBaseUrl !== ''
        ? (parse_url($productionMirrorBaseUrl, PHP_URL_HOST) ?: $productionMirrorBaseUrl)
        : '';
@endphp

@if ($isProductionMirror)
    <div class="rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900">
        <p class="flex items-center gap-1.5 font-semibold">
            <x-heroicon-o-cloud class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ __('Production mirror') }}
        </p>
        <p class="mt-1 text-amber-900/90">
            {{ __('This host is mirrored from production and is already provisioned — this local control plane just holds no SSH key for it, so this section cannot run remote actions from here.') }}
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-4">
            @if ($productionMirrorBaseUrl !== '')
                <a
                    href="{{ $productionMirrorBaseUrl }}/servers/{{ $server->id }}"
                    target="_blank"
                    rel="noopener"
                    class="font-medium underline decoration-amber-500/60 underline-offset-4"
                >
                    {{ $productionMirrorHost !== ''
                        ? __('Open on :host', ['host' => $productionMirrorHost])
                        : __('Open on production') }}
                </a>
            @endif
            @unless ($productionMirrorConnected)
                <a
                    href="{{ route('live.connect') }}"
                    wire:navigate
                    class="font-medium underline decoration-amber-500/60 underline-offset-4"
                >
                    {{ __('Reconnect production data') }}
                </a>
            @endunless
        </div>
    </div>
@else
    <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
        <p>{{ __('Provisioning and SSH must be ready before you can use this section.') }}</p>
        <div class="mt-3">
            <a
                href="{{ route('servers.settings', $server) }}"
                wire:navigate
                class="font-medium text-brand-olive underline decoration-brand-gold/60 underline-offset-4"
            >
                {{ __('Open connection settings') }}
            </a>
        </div>
    </div>
@endif
