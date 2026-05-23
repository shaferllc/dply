@php
    $traffic = $edgeSiteTraffic ?? null;
    $showTraffic = ! ($edgeIsPreviewChild ?? false);
@endphp

@if ($showTraffic)
    <section class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Traffic & analytics') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">
                    @if ($traffic !== null && ($traffic['byo_cloudflare'] ?? false))
                        {{ __('View CDN analytics in your Cloudflare dashboard.') }}
                    @elseif ($traffic !== null && (int) ($traffic['requests'] ?? 0) > 0)
                        {{ __(':requests requests MTD · :bandwidth GB bandwidth', [
                            'requests' => number_format($traffic['requests'] ?? 0),
                            'bandwidth' => number_format(($traffic['bytes_egress'] ?? 0) / (1024 ** 3), 2),
                        ]) }}
                    @else
                        {{ __('Daily CDN request and bandwidth stats — not HTTP access logs.') }}
                    @endif
                </p>
            </div>
            <a
                href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'edge-traffic']) }}"
                wire:navigate
                class="inline-flex items-center gap-1 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage"
            >
                {{ __('View analytics') }}
                <x-heroicon-o-arrow-right class="h-4 w-4" />
            </a>
        </div>
    </section>
@endif
