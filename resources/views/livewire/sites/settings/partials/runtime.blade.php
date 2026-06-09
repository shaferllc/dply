@php
    $resolvedDetection = $site->resolvedRuntimeAppDetection();
    $detectedFramework = strtolower((string) ($resolvedDetection['framework'] ?? ''));
    $detectionSourceLabel = match ($resolvedDetection['source'] ?? null) {
        'docker' => __('Docker inspection'),
        'kubernetes' => __('Kubernetes inspection'),
        'serverless' => __('Serverless target'),
        'vm' => __('VM deploy (composer.json)'),
        default => '',
    };
    $showAppPortEditor = ! $functionsHost && (
        $site->type === \App\Enums\SiteType::Node
        || in_array($detectedFramework, [
            'rails',
            'nextjs',
            'nuxt',
            'node_generic',
            'vite_static',
            'django',
            'flask',
            'fastapi',
            'python_generic',
        ], true)
        || $site->usesDockerRuntime()
        || $site->usesKubernetesRuntime()
    );
    $runtimeKey = (string) ($site->runtimeKey() ?? '');
    $runtimeVersion = (string) ($site->runtimeVersion() ?? '');
    $runtimeLabel = match ($runtimeKey) {
        'php' => 'PHP',
        'node' => 'Node.js',
        'python' => 'Python',
        'ruby' => 'Ruby',
        'go' => 'Go',
        'static' => 'Static',
        default => $runtimeKey !== '' ? ucfirst($runtimeKey) : '',
    };
    $runtimeDisplay = $runtimeLabel !== ''
        ? trim($runtimeLabel.' '.$runtimeVersion)
        : __('Not set');
@endphp

{{-- 1. Runtime card --}}
<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $runtimeDisplay !== __('Not set') ? $runtimeDisplay : __('Language & version') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('What this site runs and how. Language and version live here; per-language tuning is on the PHP, Ruby, or Static tab when applicable.') }}</p>
            </div>
        </div>
    </div>

    <div class="space-y-6 px-6 py-6 sm:px-7">

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 sm:col-span-2">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Runtime') }}</dt>
            <dd class="mt-2 text-base font-semibold text-brand-ink">{{ $runtimeDisplay }}</dd>
        </div>
        @if ($site->internal_port)
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Internal port') }}</dt>
                <dd class="mt-2 font-mono text-sm text-brand-ink">127.0.0.1:{{ $site->internal_port }}</dd>
            </div>
        @endif
        @if ($site->start_command)
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 sm:col-span-2 lg:col-span-2">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Start command') }}</dt>
                <dd class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $site->start_command }}</dd>
            </div>
        @endif
    </dl>

    @if ($showAppPortEditor)
        <form wire:submit="saveRuntimePreferences" class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:flex sm:items-end sm:gap-4">
            <div class="flex-1">
                <x-input-label for="runtime_app_port_input" :value="__('App listens on (localhost)')" />
                <x-text-input id="runtime_app_port_input" type="number" wire:model="runtime_app_port" class="mt-1 block w-full max-w-[10rem] font-mono text-sm" placeholder="3000" min="1" max="65535" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Reverse proxy target: Node, Rails/Puma, Python, or container app port on the host.') }}</p>
                <x-input-error :messages="$errors->get('runtime_app_port')" class="mt-1" />
            </div>
            <div class="mt-3 sm:mt-0">
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    @endif
    </div>
</section>

{{-- 1b. Live runtime health (deferred via wire:init): FPM pool or app-server port --}}
@if ($site->runtimeHealthProbeKind() === 'fpm')
    @php
        $pool = $site->phpFpmPoolSettings();
        $socketPath = $site->phpFpmListenSocketPath();
        $pmLabel = ['dynamic' => __('Dynamic'), 'static' => __('Static'), 'ondemand' => __('On demand')][$pool['pm']] ?? $pool['pm'];
        $fpmMax = $runtimeHealth['max_children'] ?? $pool['max_children'];
        $fpmWorkers = $runtimeHealth['workers'] ?? null;
        $fpmPct = ($fpmMax > 0 && $fpmWorkers !== null) ? (int) min(100, round($fpmWorkers / $fpmMax * 100)) : 0;
        $fpmSaturated = $fpmPct >= 85;
        $phpTabUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime', 'tab' => 'php']);
    @endphp
    <section class="mt-6 dply-card overflow-hidden" wire:init="loadRuntimeHealth">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Process pool') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('PHP-FPM pool') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('This site’s dedicated FPM pool — live status and the request-handling limits behind it. Tune the numbers on the PHP tab.') }}</p>
                </div>
            </div>

            {{-- Live status pill --}}
            <div class="shrink-0">
                @if (! $runtimeHealthLoaded)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($runtimeHealth === null)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Status unavailable') }}
                    </span>
                @elseif ($runtimeHealth['running'])
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700 ring-1 ring-emerald-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        {{ __('Running') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-red-700 ring-1 ring-red-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true"></span>
                        {{ __('Not running') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="space-y-6 px-6 py-6 sm:px-7">
            {{-- Live worker utilisation --}}
            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Workers') }}</dt>
                    @if ($runtimeHealthLoaded && $runtimeHealth !== null)
                        <span class="text-xs text-brand-moss">{{ __(':n of :max spawned', ['n' => $fpmWorkers, 'max' => $fpmMax]) }}</span>
                    @endif
                </div>

                @if (! $runtimeHealthLoaded)
                    <p class="mt-2 flex items-center gap-2 text-sm text-brand-moss">
                        <x-spinner variant="forest" class="h-3.5 w-3.5 shrink-0" />
                        {{ __('Reading live worker count from the server…') }}
                    </p>
                @elseif ($runtimeHealth === null)
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Couldn’t read the pool from the server just now.') }}</p>
                @else
                    <div class="mt-2 flex items-end gap-2">
                        <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $fpmWorkers }}</span>
                        <span class="pb-1 text-sm text-brand-moss">/ {{ $fpmMax }} {{ __('max') }}</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-brand-ink/10">
                        <div class="h-full rounded-full {{ $fpmSaturated ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $fpmPct) }}%"></div>
                    </div>
                    @if ($fpmSaturated)
                        <p class="mt-2 text-xs font-medium text-amber-700">{{ __('Near the worker ceiling (:pct%). Consider raising max children on the PHP tab.', ['pct' => $fpmPct]) }}</p>
                    @endif
                    @if (! $runtimeHealth['conf_present'])
                        <p class="mt-2 text-xs font-medium text-amber-700">{{ __('Pool config not found on disk yet — it’s written on the next webserver apply.') }}</p>
                    @endif
                @endif
            </div>

            {{-- Configured limits (from saved settings — no SSH) --}}
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Process manager') }}</dt>
                    <dd class="mt-2 text-sm font-semibold text-brand-ink">{{ $pmLabel }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Max children') }}</dt>
                    <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $pool['max_children'] }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Max requests') }}</dt>
                    <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $pool['max_requests'] ?: __('Unlimited') }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Request timeout') }}</dt>
                    <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $pool['request_terminate_timeout'] }}s</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 sm:col-span-2 lg:col-span-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Listen socket') }}</dt>
                    <dd class="mt-2 break-all font-mono text-xs text-brand-ink">{{ $socketPath }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="refreshRuntimeHealth"
                    wire:loading.attr="disabled"
                    wire:target="refreshRuntimeHealth"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="refreshRuntimeHealth" variant="forest" class="h-3 w-3" />
                    {{ __('Refresh') }}
                </button>
                @can('update', $site)
                    <button
                        type="button"
                        wire:click="reloadFpmPool"
                        wire:loading.attr="disabled"
                        wire:target="reloadFpmPool"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50 disabled:opacity-50"
                    >
                        <x-spinner wire:loading wire:target="reloadFpmPool" variant="forest" class="h-3 w-3" />
                        {{ __('Reload pool') }}
                    </button>
                @endcan
            </div>
            <a href="{{ $phpTabUrl }}" wire:navigate class="text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">{{ __('Tune pool on PHP tab') }} →</a>
        </div>
    </section>
@elseif ($site->runtimeHealthProbeKind() === 'port')
    @php $appPort = (int) $site->app_port; @endphp
    <section class="mt-6 dply-card overflow-hidden" wire:init="loadRuntimeHealth">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-signal class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('App server') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Listening on port') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Whether your app process is accepting connections on its localhost port — the reverse-proxy target the webserver forwards to.') }}</p>
                </div>
            </div>

            <div class="shrink-0">
                @if (! $runtimeHealthLoaded)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($runtimeHealth === null)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Status unavailable') }}
                    </span>
                @elseif (! empty($runtimeHealth['listening']))
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700 ring-1 ring-emerald-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        {{ __('Listening') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-red-700 ring-1 ring-red-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true"></span>
                        {{ __('Not listening') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Probe target') }}</dt>
                    <dd class="mt-2 font-mono text-sm text-brand-ink">127.0.0.1:{{ $appPort }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Result') }}</dt>
                    <dd class="mt-2 text-sm text-brand-ink">
                        @if (! $runtimeHealthLoaded)
                            <span class="flex items-center gap-2 text-brand-moss"><x-spinner variant="forest" class="h-3.5 w-3.5 shrink-0" />{{ __('Connecting…') }}</span>
                        @elseif ($runtimeHealth === null)
                            <span class="text-brand-moss">{{ __('Couldn’t reach the server just now.') }}</span>
                        @elseif (! empty($runtimeHealth['listening']))
                            <span class="font-medium text-emerald-700">{{ __('Accepting connections') }}</span>
                        @else
                            <span class="font-medium text-red-700">{{ __('Nothing is listening — is the process running?') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($runtimeHealthLoaded && $runtimeHealth !== null && empty($runtimeHealth['listening']))
                <p class="mt-3 text-xs leading-relaxed text-brand-moss">{{ __('Check the start command and that workers are running — see Workers (Supervisor) or Services (systemd) below. Confirm the app binds to 127.0.0.1 on this port.') }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <button
                type="button"
                wire:click="refreshRuntimeHealth"
                wire:loading.attr="disabled"
                wire:target="refreshRuntimeHealth"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50 disabled:opacity-50"
            >
                <x-spinner wire:loading wire:target="refreshRuntimeHealth" variant="forest" class="h-3 w-3" />
                {{ __('Re-check') }}
            </button>
        </div>
    </section>
@endif

{{-- 1b-ii. OPcache (live, read from the FPM worker via wire:init) --}}
@if ($site->usesDedicatedPhpFpmPool())
    @php
        $oc = is_array($opcacheStatus) ? $opcacheStatus : null;
        $ocEnabled = $oc !== null && ! empty($oc['enabled']);
        $bytesToMb = fn ($b) => number_format(((int) $b) / 1048576, 1).' MB';
        if ($ocEnabled) {
            $ocUsed = (int) ($oc['memory_used'] ?? 0);
            $ocTotal = $ocUsed + (int) ($oc['memory_free'] ?? 0) + (int) ($oc['memory_wasted'] ?? 0);
            $ocMemPct = $ocTotal > 0 ? (int) min(100, round($ocUsed / $ocTotal * 100)) : 0;
            $ocKeys = (int) ($oc['num_cached_keys'] ?? 0);
            $ocMaxKeys = (int) ($oc['max_cached_keys'] ?? 0);
            $ocKeysPct = $ocMaxKeys > 0 ? (int) min(100, round($ocKeys / $ocMaxKeys * 100)) : 0;
            $ocOom = (int) ($oc['oom_restarts'] ?? 0);
            $ocFull = ! empty($oc['full']);
            $ocHitRate = $oc['hit_rate'] ?? null;
            $ocPressure = $ocFull || $ocOom > 0 || $ocMemPct >= 90 || $ocKeysPct >= 90;
        }
    @endphp
    <section class="mt-6 dply-card overflow-hidden" wire:init="loadOpcacheStatus">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Bytecode cache') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('OPcache') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Live OPcache for the FPM workers serving this site. Flush it to force a recompile after an out-of-band code change.') }}</p>
                </div>
            </div>

            <div class="shrink-0">
                @if (! $opcacheStatusLoaded)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($oc === null)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Status unavailable') }}
                    </span>
                @elseif ($ocEnabled)
                    <span class="inline-flex items-center gap-1.5 rounded-full {{ $ocPressure ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200' }} px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ring-1">
                        <span class="h-1.5 w-1.5 rounded-full {{ $ocPressure ? 'bg-amber-500' : 'bg-emerald-500' }}" aria-hidden="true"></span>
                        {{ $ocPressure ? __('Under pressure') : __('Enabled') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss ring-1 ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Disabled') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            @if (! $opcacheStatusLoaded)
                <p class="flex items-center gap-2 text-sm text-brand-moss">
                    <x-spinner variant="forest" class="h-3.5 w-3.5 shrink-0" />
                    {{ __('Reading OPcache from the FPM worker…') }}
                </p>
            @elseif ($oc === null)
                <p class="text-sm text-brand-moss">{{ __('Couldn’t read OPcache from the server just now.') }}</p>
            @elseif (! $ocEnabled)
                <p class="text-sm text-brand-moss">{{ __('OPcache is not enabled for this PHP version. Enable and size it from the server’s PHP workspace.') }}</p>
            @else
                <div class="space-y-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Memory') }}</dt>
                                <span class="text-xs text-brand-moss">{{ $bytesToMb($ocUsed) }} / {{ $bytesToMb($ocTotal) }}</span>
                            </div>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-brand-ink/10">
                                <div class="h-full rounded-full {{ $ocMemPct >= 90 ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $ocMemPct) }}%"></div>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Cached keys') }}</dt>
                                <span class="text-xs text-brand-moss">{{ number_format($ocKeys) }} / {{ number_format($ocMaxKeys) }}</span>
                            </div>
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-brand-ink/10">
                                <div class="h-full rounded-full {{ $ocKeysPct >= 90 ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $ocKeysPct) }}%"></div>
                            </div>
                        </div>
                    </div>

                    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Hit rate') }}</dt>
                            <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $ocHitRate !== null ? $ocHitRate.'%' : '—' }}</dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Cached scripts') }}</dt>
                            <dd class="mt-2 font-mono text-sm text-brand-ink">{{ number_format((int) ($oc['num_cached_scripts'] ?? 0)) }}</dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Wasted') }}</dt>
                            <dd class="mt-2 font-mono text-sm text-brand-ink">{{ $bytesToMb((int) ($oc['memory_wasted'] ?? 0)) }}</dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('OOM restarts') }}</dt>
                            <dd class="mt-2 font-mono text-sm {{ $ocOom > 0 ? 'text-amber-700' : 'text-brand-ink' }}">{{ number_format($ocOom) }}</dd>
                        </div>
                    </dl>

                    @if ($ocPressure)
                        <p class="text-xs font-medium text-amber-700">
                            @if ($ocOom > 0)
                                {{ __('OPcache has hit out-of-memory restarts (:n) — raise opcache.memory_consumption from the server PHP workspace.', ['n' => $ocOom]) }}
                            @else
                                {{ __('OPcache is nearly full — raise opcache.memory_consumption / opcache.max_accelerated_files from the server PHP workspace.') }}
                            @endif
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <button
                type="button"
                wire:click="refreshOpcacheStatus"
                wire:loading.attr="disabled"
                wire:target="refreshOpcacheStatus"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50 disabled:opacity-50"
            >
                <x-spinner wire:loading wire:target="refreshOpcacheStatus" variant="forest" class="h-3 w-3" />
                {{ __('Refresh') }}
            </button>
            @can('update', $site)
                <button
                    type="button"
                    wire:click="resetOpcache"
                    wire:confirm="{{ __('Flush OPcache for this site? Workers recompile from disk on the next request.') }}"
                    wire:loading.attr="disabled"
                    wire:target="resetOpcache"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="resetOpcache" variant="forest" class="h-3 w-3" />
                    {{ __('Flush OPcache') }}
                </button>
            @endcan
        </div>
    </section>
@endif

{{-- 1c. Effective PHP limits digest (all PHP sites — pure DB, no SSH) --}}
@if ($site->type === \App\Enums\SiteType::Php)
    @php
        $phpRuntime = is_array($site->meta['php_runtime'] ?? null) ? $site->meta['php_runtime'] : [];
        // PHP's stock web-SAPI defaults — what's in effect when nothing is
        // overridden for this site. Shown muted + tagged so an un-tuned limit
        // still reports a real number instead of the word "Default".
        $phpExec = isset($phpRuntime['max_execution_time']) && $phpRuntime['max_execution_time'] !== '' ? $phpRuntime['max_execution_time'].'s' : null;
        $phpLimits = [
            ['label' => __('Memory limit'), 'value' => $phpRuntime['memory_limit'] ?? null, 'default' => '128M'],
            ['label' => __('Max execution time'), 'value' => $phpExec, 'default' => '30s'],
            ['label' => __('Upload max filesize'), 'value' => $phpRuntime['upload_max_filesize'] ?? null, 'default' => '2M'],
            ['label' => __('Post max size'), 'value' => $phpRuntime['post_max_size'] ?? null, 'default' => '8M'],
            ['label' => __('Max input vars'), 'value' => $phpRuntime['max_input_vars'] ?? null, 'default' => '1000'],
            ['label' => __('Timezone'), 'value' => $phpRuntime['timezone'] ?? null, 'default' => 'UTC'],
        ];
        $phpTabUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime', 'tab' => 'php']);
    @endphp
    <section class="mt-6 dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('PHP limits') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Effective PHP limits') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('The per-site PHP directives Dply applies for this site. A value tagged “default” is PHP’s built-in setting (nothing overridden here); plain values are your overrides. Edit on the PHP tab.') }}</p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($phpLimits as $limit)
                    @php $isOverride = filled($limit['value']); @endphp
                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ $limit['label'] }}</dt>
                        <dd class="mt-2 flex items-center gap-2">
                            <span class="font-mono text-sm {{ $isOverride ? 'text-brand-ink' : 'text-brand-moss' }}">{{ $isOverride ? $limit['value'] : $limit['default'] }}</span>
                            @unless ($isOverride)
                                <span class="rounded bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('default') }}</span>
                            @endunless
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <a href="{{ $phpTabUrl }}" wire:navigate class="text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">{{ __('Edit PHP limits') }} →</a>
        </div>
    </section>
@endif

{{-- 1d. Recent errors tail (cheap DB read; full stream on the Errors tab) --}}
@if (! empty($runtimeRecentErrors) && count($runtimeRecentErrors) > 0)
    <section class="mt-6 dply-card overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Errors') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent errors') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('The latest issues captured for this site. The full history and dismissals live on the Errors tab.') }}</p>
                </div>
            </div>
            <a href="{{ route('sites.errors', ['server' => $server, 'site' => $site]) }}" wire:navigate class="shrink-0 text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">{{ __('View all') }} →</a>
        </div>

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($runtimeRecentErrors as $event)
                <li class="flex items-start gap-3 px-6 py-4 sm:px-7">
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-rose-500" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ \Illuminate\Support\Str::headline((string) $event->category) }}</span>
                            <p class="min-w-0 truncate text-sm font-medium text-brand-ink">{{ $event->title }}</p>
                        </div>
                        @if (filled($event->detail))
                            <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-brand-moss">{{ $event->detail }}</p>
                        @endif
                    </div>
                    <time class="shrink-0 whitespace-nowrap text-[11px] text-brand-moss" datetime="{{ optional($event->occurred_at)->toIso8601String() }}">{{ optional($event->occurred_at)->diffForHumans() }}</time>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- 2. Detection panel --}}
<section class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-magnifying-glass-circle class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Detection') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repository detection') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('What Dply inferred from your repository. Detection runs on deploy and container inspect.') }}</p>
        </div>
    </div>

    <div class="space-y-4 px-6 py-6 sm:px-7">

    @if ($resolvedDetection)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/40 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    @if ($detectionSourceLabel !== '')
                        <p class="text-xs text-brand-moss">{{ __('Source') }}: {{ $detectionSourceLabel }}</p>
                    @endif
                </div>
                @if (! empty($resolvedDetection['confidence']))
                    <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss ring-1 ring-brand-ink/10">
                        {{ strtoupper((string) $resolvedDetection['confidence']) }}
                    </span>
                @endif
            </div>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Framework') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($resolvedDetection['framework'] ?? '—'))->replace('_', ' ')->title() }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Language') }}</dt>
                    <dd class="mt-1 text-sm font-medium text-brand-ink">{{ str((string) ($resolvedDetection['language'] ?? '—'))->replace('_', ' ')->title() }}</dd>
                </div>
                @if (! empty($resolvedDetection['laravel_octane']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Octane') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/octane` in composer.json') }}</dd>
                    </div>
                @endif
                @if (! empty($resolvedDetection['laravel_horizon']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Horizon') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/horizon` in composer.json') }}</dd>
                    </div>
                @endif
                @if (! empty($resolvedDetection['laravel_pulse']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Pulse') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/pulse` in composer.json') }}</dd>
                    </div>
                @endif
                @if (! empty($resolvedDetection['laravel_reverb']))
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Laravel Reverb') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-brand-ink">{{ __('Yes — `laravel/reverb` in composer.json') }}</dd>
                    </div>
                @endif
            </dl>
            @if (! empty($resolvedDetection['warnings']))
                <div class="mt-4 space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    @foreach ($resolvedDetection['warnings'] as $warning)
                        <p>{{ $warning }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('No repository inspection yet') }}</p>
            <p class="mt-1">{{ __('After a deploy or container inspect, framework and language signals from your repo will appear here.') }}</p>
        </div>
    @endif
    </div>
</section>

{{-- Background processes callout --}}
@if ($site->type !== \App\Enums\SiteType::Static)
<section class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Background') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Workers & schedulers') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                @if (in_array((string) ($site->runtime ?? ''), ['php'], true) || $site->isLaravelFrameworkDetected())
                    {{ __('Queue workers and Horizon run under Workers (Supervisor). Scheduled tasks use Cron or the Laravel tab.') }}
                @elseif ($site->isRailsFrameworkDetected())
                    {{ __('Sidekiq and Solid Queue run under Workers (Supervisor). Optional systemd workers are on the Services page.') }}
                @else
                    {{ __('App servers: set start command and port above. Workers can use systemd (Services) or Supervisor (Workers).') }}
                @endif
            </p>
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm font-semibold">
                <a href="{{ route('sites.daemons', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Workers') }} →</a>
                <a href="{{ route('servers.cron', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Cron jobs') }} →</a>
                @if (\App\Models\Site::supportsSystemdServices($site, $server))
                    <a href="{{ route('sites.services', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Services (systemd)') }} →</a>
                @endif
                @if ($site->isLaravelFrameworkDetected())
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'laravel-stack']) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Laravel') }} →</a>
                @endif
                @if ($site->isRailsFrameworkDetected())
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'rails-stack']) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">{{ __('Rails') }} →</a>
                @endif
            </div>
        </div>
    </div>
</section>
@endif

{{-- 4. Container lifecycle (Docker only) --}}
@if ($site->usesDockerRuntime())
    @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
        <section class="mt-6 dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Container') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Docker discovery') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Saved from the live Docker runtime so hostname, IP, and container identity stay referenceable later.') }}</p>
                    </div>
                </div>
                @if (! empty($dockerRuntimeDetails['collected_at']))
                    <p class="shrink-0 font-mono text-[11px] text-brand-moss">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                @endif
            </div>

            <div class="space-y-4 px-6 py-6 sm:px-7">

            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Hostname') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['hostname'] ?? '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Container IP') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_ip'] ?? '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Container name') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['container_name'] ?? '—' }}</dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Service') }}</dt>
                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $runtimePublication['docker_service'] ?? '—' }}</dd>
                </div>
            </dl>

            @if ($dockerContainers->isNotEmpty())
                <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                    <div class="border-b border-brand-ink/10 px-4 py-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-moss">{{ __('Containers') }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left">
                            <thead class="bg-brand-sand/40">
                                <tr>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Name') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Service') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Hostname') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('IP') }}</th>
                                    <th class="px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('State') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                @foreach ($dockerContainers as $container)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['name'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['service'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['orb_hostname'] ?? $container['hostname'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['ipv4'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-sm text-brand-ink">{{ $container['state'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            </div>
        </section>
    @endif

    @if ($site->usesLocalDockerHostRuntime())
        <section class="mt-6 dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-arrows-pointing-out class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Lifecycle') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Container lifecycle') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Lifecycle and inspection for the local container runtime behind this app. Output and historical operations live on the Logs tab.') }}</p>
                </div>
            </div>

            <div class="space-y-5 px-6 py-6 sm:px-7">

            <div>
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Lifecycle') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-xl bg-brand-ink px-4 py-2 text-sm font-medium text-white hover:bg-brand-ink/90">{{ __('Rebuild') }}</button>
                    <button type="button" wire:click="runRuntimeAction('start')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Start') }}</button>
                    <button type="button" wire:click="runRuntimeAction('stop')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Stop') }}</button>
                    <button type="button" wire:click="runRuntimeAction('restart')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Restart') }}</button>
                </div>
            </div>

            <div>
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss">{{ __('Inspection') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="runRuntimeAction('inspect')" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100">{{ __('Refresh Docker details') }}</button>
                    <button type="button" wire:click="runRuntimeAction('status')" class="rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">{{ __('Status') }}</button>
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Logs and recent runtime errors are on the Logs tab.') }}</p>
            </div>

            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
                <p class="text-xs text-brand-moss">{{ __('Removes managed local containers and artifacts for this app.') }}</p>
                <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this app?')), @js(__('Destroy runtime')), true)" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">{{ __('Destroy') }}</button>
            </div>
        </section>
    @endif
@endif

{{-- 5. Working directory footer --}}
<div class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Path') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Working directory') }}</h3>
            <p class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $site->effectiveRepositoryPath() }}</p>
        </div>
    </div>
</div>

{{-- 6. CLI snippets --}}
<x-cli-snippet :commands="[
    ['label' => __('Set runtime + version'), 'command' => 'dply sites:runtime:set '.$site->slug.' --runtime=node --runtime-version=22'],
    ['label' => __('Set start command + port'), 'command' => 'dply sites:runtime:set '.$site->slug.' --start=\'node server.js\' --port=3000'],
    ['label' => __('Auto-detect from repo'), 'command' => 'dply:detect-runtime '.$site->slug],
    ['label' => __('Show available runtimes'), 'command' => 'dply:list-runtimes --with-usage'],
    ['label' => __('Install runtime on server'), 'command' => 'dply:install-runtime '.($server->name ?? 'SERVER').' node 22'],
]" />
