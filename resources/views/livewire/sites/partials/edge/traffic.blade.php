@php
    $traffic = $edgeSiteTraffic ?? null;
    $hasManagedTraffic = $traffic !== null && ! ($traffic['byo_cloudflare'] ?? false);
    $maxRequests = max(1, collect($traffic['daily'] ?? [])->max('requests') ?? 1);
    $maxEgress = max(1, collect($traffic['daily'] ?? [])->max('bytes_egress') ?? 1);
    $trackedHostnames = is_array($traffic['tracked_hostnames'] ?? null) ? $traffic['tracked_hostnames'] : [];
    $peakDay = is_array($traffic['peak_day'] ?? null) ? $traffic['peak_day'] : null;
@endphp

<div class="space-y-6">
    @include('livewire.sites.partials.edge.observability-nav', ['activeObservabilitySection' => 'traffic'])

    @if ($traffic !== null && ($traffic['byo_cloudflare'] ?? false))
        <div class="rounded-xl border border-brand-sage/30 bg-brand-cream/30 px-6 py-5 dark:bg-brand-ink/30 sm:px-8">
            <p class="text-sm font-medium text-brand-ink">{{ __('Traffic stats live in your Cloudflare account') }}</p>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('This site uses your Cloudflare credentials for delivery. Open the Cloudflare dashboard for request, bandwidth, and security analytics on your Worker zone and custom domains.') }}
            </p>
            @if ($trackedHostnames !== [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('Hostnames: :hosts', ['hosts' => implode(', ', $trackedHostnames)]) }}</p>
            @endif
        </div>
    @elseif (! $hasManagedTraffic)
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-6 py-10 text-center dark:bg-brand-ink/20">
            <x-heroicon-o-signal class="mx-auto h-8 w-8 text-brand-moss/60" />
            <p class="mt-3 text-sm text-brand-moss">{{ __('Traffic stats are not available for this Edge site yet.') }}</p>
            <p class="mt-1 text-xs text-brand-moss/80">{{ __('Preview sites and inactive deployments do not collect visitor metrics.') }}</p>
        </div>
    @else
        <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('CDN traffic') }}</p>
                    <p class="mt-1 text-sm text-brand-ink">{{ $traffic['collection_delay_note'] ?? '' }}</p>
                </div>
                @if (($traffic['last_collected_date'] ?? null) !== null)
                    <p class="text-xs text-brand-moss">
                        {{ __('Latest snapshot: :date', ['date' => \Illuminate\Support\Carbon::parse($traffic['last_collected_date'])->format('M j, Y')]) }}
                    </p>
                @endif
            </div>
            @if ($trackedHostnames !== [])
                <p class="mt-2 text-xs text-brand-moss">{{ __('Tracked hostnames: :hosts', ['hosts' => implode(', ', $trackedHostnames)]) }}</p>
            @endif
            @if (($traffic['analytics_zones'] ?? []) !== [])
                <p class="mt-1 text-xs text-brand-moss">{{ __('Cloudflare zones queried: :zones', ['zones' => implode(', ', $traffic['analytics_zones'])]) }}</p>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Requests (MTD)') }}</p>
                <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">{{ number_format($traffic['requests'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Requests (7d)') }}</p>
                <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">{{ number_format($traffic['requests_7d'] ?? 0) }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('~:avg / day', ['avg' => number_format($traffic['avg_requests_per_day_7d'] ?? 0)]) }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Bandwidth (MTD)') }}</p>
                <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">{{ number_format(($traffic['bytes_egress'] ?? 0) / (1024 ** 3), 2) }} GB</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Peak day (30d)') }}</p>
                @if ($peakDay !== null && (int) ($peakDay['requests'] ?? 0) > 0)
                    <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">{{ number_format($peakDay['requests']) }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ $peakDay['label'] ?? $peakDay['date'] ?? '' }}</p>
                @else
                    <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">—</p>
                @endif
            </div>
        </div>

        @if (($traffic['daily'] ?? []) !== [])
            @php
                $tDaily = $traffic['daily'];
                $tLastIdx = count($tDaily) - 1;
                $tMidIdx = (int) floor($tLastIdx / 2);
                $tMaxEgressMb = ($maxEgress / (1024 ** 2));
            @endphp
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="dply-card overflow-hidden">
                    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Daily requests (30d)') }}</h3>
                        <span class="font-mono text-[11px] text-brand-moss">{{ __('max :n', ['n' => number_format((int) $maxRequests)]) }}</span>
                    </div>
                    <div class="px-6 py-5 sm:px-8">
                        <div class="flex h-28 items-end gap-0.5">
                            @foreach ($tDaily as $day)
                                <div class="group relative min-w-0 flex-1 h-full flex items-end cursor-help">
                                    <div
                                        class="w-full rounded-t bg-brand-sage/70 transition-colors group-hover:bg-brand-forest"
                                        style="height: {{ max(4, round(($day['requests'] / $maxRequests) * 100)) }}%"
                                    ></div>
                                    <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                                        <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format($day['requests'] ?? 0) }} {{ __('requests') }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-between text-[10px] text-brand-moss">
                            <span>{{ $tDaily[0]['label'] ?? '' }}</span>
                            @if ($tMidIdx > 0 && $tMidIdx < $tLastIdx)
                                <span>{{ $tDaily[$tMidIdx]['label'] ?? '' }}</span>
                            @endif
                            @if ($tLastIdx > 0)
                                <span>{{ $tDaily[$tLastIdx]['label'] ?? '' }}</span>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="dply-card overflow-hidden">
                    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Daily bandwidth (30d)') }}</h3>
                        <span class="font-mono text-[11px] text-brand-moss">{{ __('max :n MB', ['n' => number_format($tMaxEgressMb, 1)]) }}</span>
                    </div>
                    <div class="px-6 py-5 sm:px-8">
                        <div class="flex h-28 items-end gap-0.5">
                            @foreach ($tDaily as $day)
                                <div class="group relative min-w-0 flex-1 h-full flex items-end cursor-help">
                                    <div
                                        class="w-full rounded-t bg-sky-500/70 transition-colors group-hover:bg-sky-600"
                                        style="height: {{ max(4, round(($day['bytes_egress'] / $maxEgress) * 100)) }}%"
                                    ></div>
                                    <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                                        <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format(($day['bytes_egress'] ?? 0) / (1024 ** 2), 1) }} MB
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-between text-[10px] text-brand-moss">
                            <span>{{ $tDaily[0]['label'] ?? '' }}</span>
                            @if ($tMidIdx > 0 && $tMidIdx < $tLastIdx)
                                <span>{{ $tDaily[$tMidIdx]['label'] ?? '' }}</span>
                            @endif
                            @if ($tLastIdx > 0)
                                <span>{{ $tDaily[$tLastIdx]['label'] ?? '' }}</span>
                            @endif
                        </div>
                    </div>
                </section>
            </div>
        @else
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Daily traffic (30d)') }}</h3>
                </div>
                <p class="px-6 py-8 text-sm text-brand-moss sm:px-8">
                    {{ __('No traffic snapshots yet this month. Stats appear after the nightly edge usage collection runs. Same-day visits show up on the following day.') }}
                </p>
            </section>
        @endif
    @endif

    @php
        $access = $edgeSiteAccess ?? null;
        $perf = is_array($access['performance'] ?? null) ? $access['performance'] : [];
        $vitals = is_array($access['web_vitals'] ?? null) ? $access['web_vitals'] : [];
        $recentLogs = is_array($access['recent_logs'] ?? null) ? $access['recent_logs'] : [];
        $hasWorkerLogs = (bool) ($access['has_worker_logs'] ?? false);
        $hasWebVitals = (bool) ($access['has_web_vitals'] ?? false);
        $cacheHitRatio = $perf['cache_hit_ratio'] ?? null;
        $cacheHitPercent = $cacheHitRatio !== null ? (float) $cacheHitRatio * 100 : null;
        $isHybridForCache = (string) (($edgeRuntimeMode ?? null) ?? 'static') === 'hybrid';
    @endphp

    @if ($isHybridForCache)
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cube-transparent class="h-5 w-5 text-brand-moss/70" />
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Edge cache') }}</h3>
                </div>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Origin GET responses with Cache-Control s-maxage are stored in KV and served from the edge until expiry. Stale-while-revalidate keeps responses fresh without blocking the request.') }}</p>
            </div>
            <div class="grid gap-4 px-6 py-5 sm:grid-cols-3 sm:px-8">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Hit ratio (7d)') }}</p>
                    @if ($cacheHitPercent !== null)
                        <p class="mt-2 text-3xl font-bold tabular-nums text-brand-ink">{{ number_format($cacheHitPercent, 1) }}%</p>
                        <div class="mt-2 h-1.5 w-full rounded-full bg-brand-ink/10">
                            <div class="h-1.5 rounded-full bg-brand-sage" style="width: {{ min(100, max(0, $cacheHitPercent)) }}%"></div>
                        </div>
                    @else
                        <p class="mt-2 text-3xl font-bold tabular-nums text-brand-ink">—</p>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Waiting for traffic — needs Worker log ingest enabled.') }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Requests served from edge') }}</p>
                    @if ($cacheHitPercent !== null)
                        @php
                            $reqs7d = (int) ($perf['requests_7d'] ?? 0);
                            $hits = (int) round($reqs7d * ($cacheHitPercent / 100));
                        @endphp
                        <p class="mt-2 text-3xl font-bold tabular-nums text-brand-ink">{{ number_format($hits) }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('of :total worker requests last 7d', ['total' => number_format($reqs7d)]) }}</p>
                    @else
                        <p class="mt-2 text-3xl font-bold tabular-nums text-brand-ink">—</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Invalidate') }}</p>
                    <p class="mt-2 text-sm text-brand-ink">{{ __('Purge by Cache-Tag from Build settings.') }}</p>
                    <a
                        href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'edge-build']) }}"
                        wire:navigate
                        class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage"
                    >
                        {{ __('Open cache controls') }}
                        <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
                    </a>
                </div>
            </div>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-bolt class="h-5 w-5 text-brand-moss/70" />
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Performance') }}</h3>
                </div>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Worker response time is measured at the edge; Core Web Vitals are measured in visitors’ browsers.') }}</p>
            </div>
            <div class="space-y-6 px-6 py-5 sm:px-8">
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-brand-moss/80">{{ __('Edge (Worker)') }}</h4>
                    @if ($hasWorkerLogs)
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('Avg. response time (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ number_format($perf['avg_duration_ms'] ?? 0) }} ms</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('P95 response time (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ isset($perf['p95_duration_ms']) ? number_format($perf['p95_duration_ms']).' ms' : '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('Cache hit ratio (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">
                                    @if (($perf['cache_hit_ratio'] ?? null) !== null)
                                        {{ number_format(($perf['cache_hit_ratio'] ?? 0) * 100, 1) }}%
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('Worker requests (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ number_format($perf['requests_7d'] ?? 0) }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Worker metrics appear after redeploying the Edge worker with log ingest enabled and visiting the live Edge URL.') }}</p>
                    @endif
                </div>

                <div class="border-t border-brand-ink/10 pt-5">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-brand-moss/80">{{ __('Core Web Vitals (browser)') }}</h4>
                    @if ($hasWebVitals)
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('LCP p75 (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['lcp_p75_ms']) ? number_format($vitals['lcp_p75_ms']).' ms' : '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('INP p75 (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['inp_p75_ms']) ? number_format($vitals['inp_p75_ms']).' ms' : '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('CLS p75 (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['cls_p75']) ? number_format($vitals['cls_p75'], 3) : '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('FCP p75 (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['fcp_p75_ms']) ? number_format($vitals['fcp_p75_ms']).' ms' : '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 sm:col-span-2">
                                <dt class="text-brand-moss">{{ __('Browser TTFB p75 (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['ttfb_p75_ms']) ? number_format($vitals['ttfb_p75_ms']).' ms' : '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2 sm:col-span-2">
                                <dt class="text-brand-moss">{{ __('RUM samples (7d)') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ number_format($vitals['samples_7d'] ?? 0) }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="mt-2 text-sm text-brand-moss">{{ __('LCP, INP, and CLS are collected automatically from HTML pages after worker redeploy. Open the live site in a browser to generate samples.') }}</p>
                    @endif
                </div>
            </div>
        </section>

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-document-text class="h-5 w-5 text-brand-moss/70" />
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('HTTP access logs') }}</h3>
                </div>
            </div>
            @if ($recentLogs !== [])
                <div class="max-h-96 overflow-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="sticky top-0 bg-brand-sand/80 text-brand-moss dark:bg-brand-ink/60">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ __('Time') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Path') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Status') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Duration') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ __('Country') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8">
                            @foreach ($recentLogs as $log)
                                <tr wire:key="edge-access-log-{{ md5(json_encode($log)) }}">
                                    <td class="whitespace-nowrap px-4 py-2 text-brand-moss">
                                        {{ isset($log['occurred_at']) ? \Illuminate\Support\Carbon::parse($log['occurred_at'])->timezone(config('app.timezone'))->format('M j g:i A') : '—' }}
                                    </td>
                                    <td class="max-w-[14rem] truncate px-4 py-2 font-mono text-brand-ink" title="{{ $log['path'] ?? '' }}">{{ $log['path'] ?? '—' }}</td>
                                    <td class="px-4 py-2 tabular-nums text-brand-ink">{{ $log['status_code'] ?? '—' }}</td>
                                    <td class="px-4 py-2 tabular-nums text-brand-ink">{{ number_format($log['duration_ms'] ?? 0) }} ms</td>
                                    <td class="px-4 py-2 text-brand-moss">{{ $log['country'] ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="space-y-3 px-6 py-5 text-sm sm:px-8">
                    <p class="text-brand-moss">{{ __('Per-request visitor logs appear here once the Edge worker posts to log ingest. Build output lives under Build & deploy logs.') }}</p>
                    <a
                        href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'edge-logs']) }}"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage"
                    >
                        {{ __('Open build & deploy logs') }}
                        <x-heroicon-o-arrow-right class="h-4 w-4" />
                    </a>
                </div>
            @endif
        </section>
    </div>
</div>
