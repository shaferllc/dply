@php
    $adminApiEndpoints = [
        'config' => __('Full active config JSON'),
        'reverse_proxy/upstreams' => __('Reverse-proxy upstream health'),
        'metrics' => __('Prometheus metrics'),
        'pki/ca/local' => __('Local CA details'),
        'id' => __('Caddy instance ID'),
    ];
@endphp
<div class="{{ $card }} p-6 sm:p-8 mb-6" wire:key="caddy-admin-api-links">
    <div class="flex flex-wrap items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Admin API') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Read-only Caddy admin URLs') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Caddy\'s admin API stays bound to localhost on the server. These authenticated Dply URLs proxy GET requests over SSH — nothing is exposed on the public internet. POST/PATCH/DELETE are blocked.') }}
            </p>
            <ul class="mt-4 space-y-2">
                @foreach ($adminApiEndpoints as $endpoint => $label)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <a
                            href="{{ route('servers.webserver.caddy.admin-api', ['server' => $server, 'path' => $endpoint]) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 font-medium text-brand-forest hover:text-brand-ink"
                        >
                            {{ $label }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        </a>
                        <code class="rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[11px] text-brand-moss">/{{ $endpoint }}/</code>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
