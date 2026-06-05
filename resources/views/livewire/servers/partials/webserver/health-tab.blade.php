        {{-- Cross-engine TLS dashboard — shared with the server cert-inventory
             page via _live-server-certs; the SSH sweep runs async in
             ScanServerLiveCertsJob and this card polls for the result. --}}
        @include('livewire.servers.partials._live-server-certs', [
            'liveCertsTitle' => __('TLS certificates on this server'),
            'liveCertsDescription' => __('Server-cert inventory across Let\'s Encrypt + Caddy local CA + every per-engine ssl directory, sorted by expiry. CA bundles and OS trust-store certs are filtered out.'),
            'liveCertsWrapperClass' => $card.' overflow-hidden',
        ])
        {{-- =================================================================
             SITE SMOKE TEST. Operator-triggered loopback curl through the
             active webserver for every Site on this server. Surfaces
             routing problems (404 / 502), TLS gaps (HTTPS down while HTTP
             redirects), or full-on outages (both schemes errored).
             ================================================================= --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-8">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Site smoke test') }}</h3>
                    <p class="mt-0.5 text-[12px] text-brand-moss">
                        {{ __('Curls every Site\'s primary hostname through 127.0.0.1 (HTTP + HTTPS with --resolve so SNI matches). Sorted worst-first.') }}
                        @if ($smoke_scanned_at_iso)
                            <span class="ml-1 text-brand-mist">·
                                {{ __('Ran :time', ['time' => \Illuminate\Support\Carbon::parse($smoke_scanned_at_iso)->diffForHumans()]) }}
                            </span>
                        @endif
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="runSmokeTest"
                    wire:loading.attr="disabled"
                    wire:target="runSmokeTest"
                    @disabled($isDeployer || ! $opsReady || $actionInFlight)
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="runSmokeTest" class="inline-flex">
                        <x-heroicon-o-bolt class="h-3.5 w-3.5" />
                    </span>
                    <span wire:loading wire:target="runSmokeTest" class="inline-flex">
                        <x-spinner variant="cream" class="h-3.5 w-3.5" />
                    </span>
                    {{ __('Run smoke test') }}
                </button>
            </div>

            @if ($smoke_error)
                <div class="border-b border-rose-200 bg-rose-50/70 px-6 py-3 text-sm text-rose-900 sm:px-8">
                    {{ $smoke_error }}
                </div>
            @endif

            @if (! $smoke_loaded)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <span wire:loading wire:target="runSmokeTest" class="inline-flex items-center gap-2">
                        <x-spinner class="h-3.5 w-3.5" /> {{ __('Probing sites…') }}
                    </span>
                    <span wire:loading.remove wire:target="runSmokeTest">
                        <x-heroicon-o-bolt class="mx-auto h-6 w-6 text-brand-mist" />
                        <p class="mt-2">{{ __('Click "Run smoke test" to probe every site.') }}</p>
                    </span>
                </div>
            @elseif ($smoke_total_sites === 0)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <x-heroicon-o-folder-open class="mx-auto h-6 w-6 text-brand-mist" />
                    <p class="mt-2">{{ __('No sites on this server yet.') }}</p>
                </div>
            @else
                @php
                    $smokeCounts = ['down' => 0, 'error' => 0, 'warn' => 0, 'ok' => 0, 'unknown' => 0];
                    foreach ($smoke_results as $r) {
                        $u = (string) ($r['urgency'] ?? 'unknown');
                        $smokeCounts[$u] = ($smokeCounts[$u] ?? 0) + 1;
                    }
                @endphp
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-6 py-3 text-[11px] sm:px-8">
                    <span class="text-brand-moss">{{ __(':probed of :total probed', ['probed' => $smoke_probed, 'total' => $smoke_total_sites]) }}@if ($smoke_truncated) <span class="text-amber-700">{{ __('(truncated)') }}</span>@endif</span>
                    @if ($smokeCounts['down'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-800">
                            <x-heroicon-o-x-circle class="h-3 w-3" /> {{ $smokeCounts['down'] }} {{ __('down') }}
                        </span>
                    @endif
                    @if ($smokeCounts['error'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700">
                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ $smokeCounts['error'] }} {{ __('5xx') }}
                        </span>
                    @endif
                    @if ($smokeCounts['warn'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">
                            <x-heroicon-o-question-mark-circle class="h-3 w-3" /> {{ $smokeCounts['warn'] }} {{ __('warn') }}
                        </span>
                    @endif
                    @if ($smokeCounts['ok'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                            <x-heroicon-o-check-circle class="h-3 w-3" /> {{ $smokeCounts['ok'] }} {{ __('ok') }}
                        </span>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-brand-sand/20 text-[11px] uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-6 py-2 font-medium sm:px-8">{{ __('Site') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Hostname') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('HTTP') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('HTTPS') }}</th>
                                <th class="px-4 py-2 font-medium text-right">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($smoke_results as $r)
                                @php
                                    $urgency = (string) ($r['urgency'] ?? 'unknown');
                                    $httpStatus = $r['http_status'] ?? null;
                                    $httpsStatus = $r['https_status'] ?? null;
                                    $httpClass = $httpStatus === null
                                        ? 'text-rose-700'
                                        : ($httpStatus >= 500 ? 'text-rose-700' : ($httpStatus >= 400 ? 'text-amber-700' : ($httpStatus >= 300 ? 'text-brand-moss' : 'text-emerald-700')));
                                    $httpsClass = $httpsStatus === null
                                        ? 'text-rose-700'
                                        : ($httpsStatus >= 500 ? 'text-rose-700' : ($httpsStatus >= 400 ? 'text-amber-700' : ($httpsStatus >= 300 ? 'text-brand-moss' : 'text-emerald-700')));
                                @endphp
                                <tr>
                                    <td class="px-6 py-2 sm:px-8">
                                        <a
                                            href="{{ route('sites.show', ['server' => $server, 'site' => $r['site_id']]) }}"
                                            class="font-medium text-brand-ink hover:underline"
                                        >{{ $r['site_name'] }}</a>
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $r['hostname'] }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        <span class="font-mono {{ $httpClass }}">{{ $httpStatus ?? '—' }}</span>
                                        @if (isset($r['http_time_ms']))
                                            <span class="ml-1 text-[10px] text-brand-mist tabular-nums">{{ $r['http_time_ms'] }}ms</span>
                                        @endif
                                        @if (! empty($r['http_error']))
                                            <p class="mt-0.5 text-[10px] text-rose-700">{{ $r['http_error'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-xs">
                                        <span class="font-mono {{ $httpsClass }}">{{ $httpsStatus ?? '—' }}</span>
                                        @if (isset($r['https_time_ms']))
                                            <span class="ml-1 text-[10px] text-brand-mist tabular-nums">{{ $r['https_time_ms'] }}ms</span>
                                        @endif
                                        @if (! empty($r['https_status']) && empty($r['https_tls_ok']))
                                            <span class="ml-1 inline-flex items-center gap-0.5 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800" title="TLS verification failed (-k accepted the cert anyway)">
                                                <x-heroicon-o-shield-exclamation class="h-3 w-3" /> tls
                                            </span>
                                        @endif
                                        @if (! empty($r['https_error']))
                                            <p class="mt-0.5 text-[10px] text-rose-700">{{ $r['https_error'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1',
                                            'bg-rose-100 text-rose-900 ring-rose-200' => $urgency === 'down',
                                            'bg-rose-50 text-rose-700 ring-rose-200' => $urgency === 'error',
                                            'bg-amber-50 text-amber-800 ring-amber-200' => $urgency === 'warn',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $urgency === 'ok',
                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $urgency === 'unknown',
                                        ])>{{ $urgency }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        {{-- =================================================================
             CONFIG DRIFT DETECTOR. Compares each per-site config on disk
             against what dply's per-site provisioner would emit right now.
             Drift here means edits that'll get clobbered on the next Site
             Apply / webserver switch.
             ================================================================= --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-8">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Config drift') }}</h3>
                    <p class="mt-0.5 text-[12px] text-brand-moss">
                        {{ __('Compares each Site\'s on-disk webserver config against the canonical content dply\'s provisioner would emit. Drifted entries are what the next Site Apply would rewrite.') }}
                        @if ($drift_scanned_at_iso)
                            <span class="ml-1 text-brand-mist">·
                                {{ __('Checked :time', ['time' => \Illuminate\Support\Carbon::parse($drift_scanned_at_iso)->diffForHumans()]) }}
                            </span>
                        @endif
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="refreshDriftDetector"
                    wire:loading.attr="disabled"
                    wire:target="refreshDriftDetector,loadDriftDetector"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="refreshDriftDetector,loadDriftDetector" class="inline-flex">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                    </span>
                    <span wire:loading wire:target="refreshDriftDetector,loadDriftDetector" class="inline-flex">
                        <x-spinner class="h-3.5 w-3.5" />
                    </span>
                    {{ __('Recheck') }}
                </button>
            </div>

            @if ($drift_error)
                <div class="border-b border-rose-200 bg-rose-50/70 px-6 py-3 text-sm text-rose-900 sm:px-8">
                    {{ $drift_error }}
                </div>
            @endif

            @if (! $drift_loaded)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <span wire:loading wire:target="loadDriftDetector,refreshDriftDetector" class="inline-flex items-center gap-2">
                        <x-spinner class="h-3.5 w-3.5" /> {{ __('Comparing on-disk vs provisioner output…') }}
                    </span>
                    <span wire:loading.remove wire:target="loadDriftDetector,refreshDriftDetector">
                        {{ __('Click "Recheck" to run the comparison.') }}
                    </span>
                </div>
            @elseif ($drift_unsupported)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <x-heroicon-o-information-circle class="mx-auto h-6 w-6 text-brand-mist" />
                    <p class="mt-2">{{ __('Drift detection is only supported for nginx / Caddy / Apache / OpenLiteSpeed. The active engine (:engine) has no per-site builder dply can diff against.', ['engine' => $drift_engine ?? 'none']) }}</p>
                </div>
            @elseif ($drift_total_sites === 0)
                <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
                    <x-heroicon-o-folder-open class="mx-auto h-6 w-6 text-brand-mist" />
                    <p class="mt-2">{{ __('No sites on this server yet — no configs to compare.') }}</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-6 py-3 text-[11px] sm:px-8">
                    <span class="text-brand-moss">{{ __(':total sites compared (:engine)', ['total' => count($drift_results), 'engine' => $drift_engine]) }}@if ($drift_truncated) <span class="text-amber-700">{{ __('(truncated to first 60)') }}</span>@endif</span>
                    @if ($drift_count > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">
                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ $drift_count }} {{ __('drifted') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                            <x-heroicon-o-check-circle class="h-3 w-3" /> {{ __('all in sync') }}
                        </span>
                    @endif
                </div>

                <div class="divide-y divide-brand-ink/5">
                    @foreach ($drift_results as $row)
                        @php
                            $hasError = ! empty($row['error']);
                            $drifted = ! empty($row['drifted']);
                        @endphp
                        <div
                            class="px-6 py-3 sm:px-8"
                            x-data="{ open: false }"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a
                                            href="{{ route('sites.show', ['server' => $server, 'site' => $row['site_id']]) }}"
                                            class="font-medium text-brand-ink hover:underline"
                                        >{{ $row['site_name'] }}</a>
                                        @if ($hasError)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700">
                                                <x-heroicon-o-x-circle class="h-3 w-3" /> {{ $row['error'] }}
                                            </span>
                                        @elseif ($drifted)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                                <x-heroicon-o-exclamation-triangle class="h-3 w-3" /> {{ __('drifted') }}
                                            </span>
                                            <span class="text-[11px] tabular-nums text-emerald-700">+{{ $row['added'] }}</span>
                                            <span class="text-[11px] tabular-nums text-rose-700">-{{ $row['removed'] }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                                <x-heroicon-o-check-circle class="h-3 w-3" /> {{ __('in sync') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 break-all font-mono text-[11px] text-brand-mist">{{ $row['path'] }}</p>
                                </div>
                                @if ($drifted && ! $hasError)
                                    <button
                                        type="button"
                                        x-on:click="open = !open"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        <span x-text="open ? @js(__('Hide diff')) : @js(__('Show diff'))"></span>
                                        <x-heroicon-o-chevron-down class="h-3 w-3 transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                                    </button>
                                @endif
                            </div>

                            @if ($drifted && ! $hasError)
                                <pre
                                    x-show="open" x-cloak
                                    class="mt-3 max-h-96 overflow-auto rounded-lg bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100"
                                >{{ $row['diff'] }}</pre>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
