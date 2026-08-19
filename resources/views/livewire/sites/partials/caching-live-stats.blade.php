@php
    $bytesToMb = static fn (int $b): string => number_format($b / 1048576, 1).' MB';
    $oc = is_array($cacheStats['opcache'] ?? null) ? $cacheStats['opcache'] : null;
    $ngx = is_array($cacheStats['nginx_http'] ?? null) ? $cacheStats['nginx_http'] : null;
    $vn = is_array($cacheStats['varnish'] ?? null) ? $cacheStats['varnish'] : null;
    $showOpcache = in_array('opcache', $activeStatMethods, true);
    $showNginx = in_array('nginx_http', $activeStatMethods, true);
    $showVarnish = in_array('varnish', $activeStatMethods, true);
@endphp

<div
    class="border-b border-brand-ink/10"
    wire:key="cache-stats-{{ implode('-', $activeStatMethods) }}"
    wire:init="loadCacheStats"
>
    <x-workspace-panel-head
        class="border-b border-brand-ink/10"
        icon="heroicon-o-chart-bar"
        :title="__('Live stats')"
        :note="__('Hit rate and size for the layers you have on. Refreshes from the server — not first paint.')"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="refreshCacheStats"
                wire:loading.attr="disabled"
                wire:target="loadCacheStats, refreshCacheStats"
                class="dply-btn dply-btn-xs dply-btn-outline"
            >
                <x-spinner wire:loading wire:target="loadCacheStats, refreshCacheStats" variant="forest" class="h-3 w-3" />
                <span wire:loading.remove wire:target="loadCacheStats, refreshCacheStats">{{ __('Refresh') }}</span>
                <span wire:loading wire:target="loadCacheStats, refreshCacheStats">{{ __('Reading…') }}</span>
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="space-y-4 px-5 py-4 sm:px-6">
        @if (! $cacheStatsLoaded)
            <p class="flex items-center gap-2 text-sm text-brand-moss">
                <x-spinner variant="forest" class="h-3.5 w-3.5 shrink-0" />
                {{ __('Reading cache stats…') }}
            </p>
        @elseif ($cacheStats === null)
            <p class="text-sm text-brand-moss">{{ __('Couldn’t read cache stats from the server just now.') }}</p>
        @else
            @if ($showOpcache)
                @php
                    $ocEnabled = $oc !== null && ! empty($oc['enabled']);
                    if ($ocEnabled) {
                        $ocUsed = (int) ($oc['memory_used'] ?? 0);
                        $ocTotal = $ocUsed + (int) ($oc['memory_free'] ?? 0) + (int) ($oc['memory_wasted'] ?? 0);
                        $ocMemPct = $ocTotal > 0 ? (int) min(100, round($ocUsed / $ocTotal * 100)) : 0;
                        $ocKeys = (int) ($oc['num_cached_keys'] ?? 0);
                        $ocMaxKeys = (int) ($oc['max_cached_keys'] ?? 0);
                        $ocKeysPct = $ocMaxKeys > 0 ? (int) min(100, round($ocKeys / $ocMaxKeys * 100)) : 0;
                        $ocHitRate = $oc['hit_rate'] ?? null;
                        $ocOom = (int) ($oc['oom_restarts'] ?? 0);
                    }
                @endphp
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('PHP OPcache') }}</p>
                    @if ($oc === null)
                        <p class="mt-1.5 text-sm text-brand-moss">{{ __('Couldn’t read OPcache. Dedicated PHP-FPM (nginx/Caddy PHP sites) is required.') }}</p>
                    @elseif (! $ocEnabled)
                        <p class="mt-1.5 text-sm text-brand-moss">{{ __('OPcache is not enabled for this PHP version. Turn it on from the server PHP workspace.') }}</p>
                    @else
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Memory') }}</p>
                                    <span class="font-mono text-xs text-brand-ink">{{ $bytesToMb($ocUsed) }} / {{ $bytesToMb($ocTotal) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10">
                                    <div class="h-full rounded-full {{ $ocMemPct >= 90 ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $ocMemPct) }}%"></div>
                                </div>
                            </div>
                            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Cached keys') }}</p>
                                    <span class="font-mono text-xs text-brand-ink">{{ number_format($ocKeys) }} / {{ number_format($ocMaxKeys) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10">
                                    <div class="h-full rounded-full {{ $ocKeysPct >= 90 ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $ocKeysPct) }}%"></div>
                                </div>
                            </div>
                        </div>
                        <dl class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <x-fact-row :label="__('Hit rate')" :value="$ocHitRate !== null ? $ocHitRate.'%' : '—'" />
                            <x-fact-row :label="__('Cached scripts')" :value="number_format((int) ($oc['num_cached_scripts'] ?? 0))" />
                            <x-fact-row :label="__('Hits')" :value="number_format((int) ($oc['hits'] ?? 0))" />
                            <x-fact-row :label="__('OOM restarts')">
                                <span class="{{ $ocOom > 0 ? 'text-amber-700' : '' }}">{{ number_format($ocOom) }}</span>
                            </x-fact-row>
                        </dl>
                    @endif
                </div>
            @endif

            @if ($showNginx)
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Nginx HTTP cache') }}</p>
                    @if ($ngx === null)
                        <p class="mt-1.5 text-sm text-brand-moss">{{ __('Couldn’t read the FastCGI / proxy cache directories.') }}</p>
                    @else
                        <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach (['fcgi' => __('FastCGI zone'), 'proxy' => __('Proxy zone')] as $zone => $label)
                                @php $z = is_array($ngx[$zone] ?? null) ? $ngx[$zone] : []; @endphp
                                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $label }}</p>
                                    @if (empty($z['present']))
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('Directory not on disk yet — apply config after enabling.') }}</p>
                                    @else
                                        @php
                                            $used = (int) ($z['bytes'] ?? 0);
                                            $max = (int) ($z['max_bytes'] ?? 0);
                                            $pct = $max > 0 ? (int) min(100, round($used / $max * 100)) : 0;
                                        @endphp
                                        <p class="mt-1 font-mono text-xs text-brand-ink">{{ $bytesToMb($used) }} / {{ $bytesToMb($max) }} · {{ number_format((int) ($z['files'] ?? 0)) }} {{ __('files') }}</p>
                                        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10">
                                            <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $pct) }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('On-disk size for the shared nginx cache zones this site uses. Hit/miss per request is not exposed by nginx without extra modules.') }}</p>
                    @endif
                </div>
            @endif

            @if ($showVarnish)
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Varnish') }}</p>
                    @if ($vn === null)
                        <p class="mt-1.5 text-sm text-brand-moss">{{ __('Couldn’t read varnishstat. Is the Varnish daemon installed on this server?') }}</p>
                    @else
                        <dl class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <x-fact-row :label="__('Hit rate')" :value="isset($vn['hit_rate']) ? $vn['hit_rate'].'%' : '—'" />
                            <x-fact-row :label="__('Hits')" :value="number_format((int) ($vn['hits'] ?? 0))" />
                            <x-fact-row :label="__('Misses')" :value="number_format((int) ($vn['misses'] ?? 0))" />
                            <x-fact-row :label="__('Objects')" :value="number_format((int) ($vn['objects'] ?? 0))" />
                        </dl>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Server-wide Varnish counters — one daemon, one VCL. Nuked objects: :n.', ['n' => number_format((int) ($vn['nuked'] ?? 0))]) }}</p>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
