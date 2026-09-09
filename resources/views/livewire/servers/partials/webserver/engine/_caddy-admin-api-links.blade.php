@php
    $adminApiEndpoints = [
        'config' => __('Full active config JSON'),
        'reverse_proxy/upstreams' => __('Reverse-proxy upstream health'),
        'metrics' => __('Prometheus metrics'),
        'pki/ca/local' => __('Local CA details'),
        'id' => __('Caddy instance ID'),
    ];
@endphp
<div class="{{ $card }} mb-6 overflow-hidden" wire:key="caddy-admin-api-links">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-link"
        :title="__('Read-only Caddy admin URLs')"
        :note="__('Caddy\'s admin API stays bound to localhost on the server. These authenticated Dply URLs proxy GET requests over SSH — nothing is exposed on the public internet. POST/PATCH/DELETE are blocked.')"
        class="border-b border-brand-ink/10"
    />

    <ul class="divide-y divide-brand-ink/5">
        @foreach ($adminApiEndpoints as $endpoint => $label)
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-xs sm:px-5">
                <a
                    href="{{ route('servers.webserver.caddy.admin-api', ['server' => $server, 'path' => $endpoint]) }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 font-semibold text-brand-forest hover:text-brand-ink"
                >
                    {{ $label }}
                    <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
                <code class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-xs text-brand-moss">/{{ $endpoint }}/</code>
            </li>
        @endforeach
    </ul>
</div>
