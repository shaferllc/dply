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

    // Shared density classes — every panel on this tab uses the same compact
    // header / body / footer rhythm so the overview stays scannable in one or
    // two screens instead of five.
    $panelHead = 'flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6';
    $panelBody = 'px-5 py-4 sm:px-6';
    $panelFoot = 'flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6';
    $panelIcon = 'h-4 w-4 shrink-0 text-brand-sage';
    $panelTitle = 'text-sm font-semibold text-brand-ink';
    $panelNote = 'mt-1 text-xs leading-relaxed text-brand-moss';
    $pillBase = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1';
    $btnBase = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50 disabled:opacity-50';
    $linkBase = 'text-xs font-semibold text-brand-forest hover:text-brand-sage hover:underline';
@endphp

{{-- 1. Runtime card — the page hero already explains this tab, so the panel
     carries the facts (language, port, start command) and not a restatement. --}}
<section class="border-b border-brand-ink/10">
    <div class="{{ $panelHead }}">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <x-heroicon-o-cube-transparent class="{{ $panelIcon }}" aria-hidden="true" />
                <h2 class="{{ $panelTitle }}">{{ __('Language & version') }}</h2>
            </div>
            <p class="{{ $panelNote }}">{{ __('Per-language tuning lives on the PHP, Ruby, or Static tab when applicable.') }}</p>
        </div>
        <span class="inline-flex shrink-0 items-center rounded-full bg-white px-2.5 py-1 font-mono text-xs font-semibold {{ $runtimeDisplay === __('Not set') ? 'text-brand-moss' : 'text-brand-ink' }} ring-1 ring-brand-ink/10">{{ $runtimeDisplay }}</span>
    </div>

    @if ($site->internal_port || $site->start_command || $showAppPortEditor)
        <div class="{{ $panelBody }} space-y-3">
            @if ($site->internal_port || $site->start_command)
                <dl class="grid gap-2 sm:grid-cols-2">
                    @if ($site->internal_port)
                        <x-fact-row :label="__('Internal port')" value="127.0.0.1:{{ $site->internal_port }}" />
                    @endif
                    @if ($site->start_command)
                        <x-fact-row :label="__('Start command')" :value="$site->start_command" class="sm:col-span-2" />
                    @endif
                </dl>
            @endif

            @if ($showAppPortEditor)
                <form wire:submit="saveRuntimePreferences" class="flex flex-wrap items-end gap-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5">
                    <div class="min-w-0">
                        <x-input-label for="runtime_app_port_input" :value="__('App listens on (localhost)')" class="!text-[11px]" />
                        <x-text-input id="runtime_app_port_input" type="number" wire:model="runtime_app_port" class="mt-1 block w-[8rem] font-mono text-sm" placeholder="3000" min="1" max="65535" />
                        <x-input-error :messages="$errors->get('runtime_app_port')" class="mt-1" />
                    </div>
                    <x-primary-button size="sm" type="submit">{{ __('Save') }}</x-primary-button>
                    <p class="w-full text-xs text-brand-moss sm:w-auto sm:flex-1 sm:text-right">{{ __('Reverse proxy target: Node, Rails/Puma, Python, or container app port on the host.') }}</p>
                </form>
            @endif
        </div>
    @endif
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
    <section class="border-b border-brand-ink/10" wire:init="loadRuntimeHealth">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-bolt class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('PHP-FPM pool') }}</h2>
                </div>
                <p class="{{ $panelNote }}">{{ __('This site’s dedicated FPM pool — live status and the request-handling limits behind it. Tune the numbers on the PHP tab.') }}</p>
            </div>

            {{-- Live status pill --}}
            <div class="shrink-0">
                @if (! $runtimeHealthLoaded)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($runtimeHealth === null)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Status unavailable') }}
                    </span>
                @elseif ($runtimeHealth['running'])
                    <span class="{{ $pillBase }} bg-emerald-50 text-emerald-700 ring-emerald-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        {{ __('Running') }}
                    </span>
                @else
                    <span class="{{ $pillBase }} bg-red-50 text-red-700 ring-red-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true"></span>
                        {{ __('Not running') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="{{ $panelBody }} space-y-2">
            {{-- Live worker utilisation --}}
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2.5">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Workers') }}</p>
                    @if (! $runtimeHealthLoaded)
                        <span class="flex items-center gap-1.5 text-xs text-brand-moss">
                            <x-spinner variant="forest" class="h-3 w-3 shrink-0" />
                            {{ __('Reading live worker count from the server…') }}
                        </span>
                    @elseif ($runtimeHealth === null)
                        <span class="text-xs text-brand-moss">{{ __('Couldn’t read the pool from the server just now.') }}</span>
                    @else
                        <span class="text-xs text-brand-moss">
                            <span class="font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $fpmWorkers }}</span>
                            / {{ $fpmMax }} {{ __('spawned') }}
                        </span>
                    @endif
                </div>

                @if ($runtimeHealthLoaded && $runtimeHealth !== null)
                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10">
                        <div class="h-full rounded-full {{ $fpmSaturated ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $fpmPct) }}%"></div>
                    </div>
                    @if ($fpmSaturated)
                        <p class="mt-1.5 text-[11px] font-medium text-amber-700">{{ __('Near the worker ceiling (:pct%). Consider raising max children on the PHP tab.', ['pct' => $fpmPct]) }}</p>
                    @endif
                    @if (! $runtimeHealth['conf_present'])
                        <p class="mt-1.5 text-[11px] font-medium text-amber-700">{{ __('Pool config not found on disk yet — it’s written on the next webserver apply.') }}</p>
                    @endif
                @endif
            </div>

            {{-- Configured limits (from saved settings — no SSH) --}}
            <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <x-fact-row :label="__('Process manager')" :value="$pmLabel" :mono="false" />
                <x-fact-row :label="__('Max children')" :value="$pool['max_children']" />
                <x-fact-row :label="__('Max requests')" :value="$pool['max_requests'] ?: __('Unlimited')" />
                <x-fact-row :label="__('Request timeout')" :value="$pool['request_terminate_timeout'].'s'" />
                <x-fact-row :label="__('Listen socket')" :value="$socketPath" class="sm:col-span-2 lg:col-span-4" />
            </dl>
        </div>

        <div class="{{ $panelFoot }}">
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="refreshRuntimeHealth"
                    wire:loading.attr="disabled"
                    wire:target="refreshRuntimeHealth"
                    class="{{ $btnBase }}"
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
                        class="{{ $btnBase }}"
                    >
                        <x-spinner wire:loading wire:target="reloadFpmPool" variant="forest" class="h-3 w-3" />
                        {{ __('Reload pool') }}
                    </button>
                @endcan
            </div>
            <a href="{{ $phpTabUrl }}" wire:navigate class="{{ $linkBase }}">{{ __('Tune pool on PHP tab') }} →</a>
        </div>
    </section>
@elseif ($site->runtimeHealthProbeKind() === 'port')
    @php $appPort = (int) $site->app_port; @endphp
    <section class="border-b border-brand-ink/10" wire:init="loadRuntimeHealth">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-signal class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('App server — listening on port') }}</h2>
                </div>
                <p class="{{ $panelNote }}">{{ __('Whether your app process is accepting connections on its localhost port — the reverse-proxy target the webserver forwards to.') }}</p>
            </div>

            <div class="shrink-0">
                @if (! $runtimeHealthLoaded)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($runtimeHealth === null)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Status unavailable') }}
                    </span>
                @elseif (! empty($runtimeHealth['listening']))
                    <span class="{{ $pillBase }} bg-emerald-50 text-emerald-700 ring-emerald-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        {{ __('Listening') }}
                    </span>
                @else
                    <span class="{{ $pillBase }} bg-red-50 text-red-700 ring-red-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true"></span>
                        {{ __('Not listening') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="{{ $panelBody }}">
            <dl class="grid gap-2 sm:grid-cols-2">
                <x-fact-row :label="__('Probe target')" value="127.0.0.1:{{ $appPort }}" />
                <x-fact-row :label="__('Result')" :mono="false" :tone="$runtimeHealthLoaded && $runtimeHealth !== null && ! empty($runtimeHealth['listening']) ? 'default' : 'muted'">
                    @if (! $runtimeHealthLoaded)
                        <span class="flex items-center gap-1.5"><x-spinner variant="forest" class="h-3 w-3 shrink-0" />{{ __('Connecting…') }}</span>
                    @elseif ($runtimeHealth === null)
                        {{ __('Couldn’t reach the server just now.') }}
                    @elseif (! empty($runtimeHealth['listening']))
                        <span class="text-emerald-700">{{ __('Accepting connections') }}</span>
                    @else
                        <span class="text-red-700">{{ __('Nothing is listening — is the process running?') }}</span>
                    @endif
                </x-fact-row>
            </dl>

            @if ($runtimeHealthLoaded && $runtimeHealth !== null && empty($runtimeHealth['listening']))
                <p class="mt-2 text-[11px] leading-relaxed text-brand-moss">{{ __('Check the start command and that workers are running — see Workers (Supervisor) or Services (systemd) below. Confirm the app binds to 127.0.0.1 on this port.') }}</p>
            @endif
        </div>

        <div class="{{ $panelFoot }}">
            <button
                type="button"
                wire:click="refreshRuntimeHealth"
                wire:loading.attr="disabled"
                wire:target="refreshRuntimeHealth"
                class="{{ $btnBase }}"
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
    <section class="border-b border-brand-ink/10" wire:init="loadOpcacheStatus">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cpu-chip class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('OPcache') }}</h2>
                </div>
                <p class="{{ $panelNote }}">{{ __('Live bytecode cache for the FPM workers serving this site. Flush it to force a recompile after an out-of-band code change.') }}</p>
            </div>

            <div class="shrink-0">
                @if (! $opcacheStatusLoaded)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($oc === null)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Status unavailable') }}
                    </span>
                @elseif ($ocEnabled)
                    <span class="{{ $pillBase }} {{ $ocPressure ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $ocPressure ? 'bg-amber-500' : 'bg-emerald-500' }}" aria-hidden="true"></span>
                        {{ $ocPressure ? __('Under pressure') : __('Enabled') }}
                    </span>
                @else
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Disabled') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="{{ $panelBody }}">
            @if (! $opcacheStatusLoaded)
                <p class="flex items-center gap-2 text-xs text-brand-moss">
                    <x-spinner variant="forest" class="h-3 w-3 shrink-0" />
                    {{ __('Reading OPcache from the FPM worker…') }}
                </p>
            @elseif ($oc === null)
                <p class="text-xs text-brand-moss">{{ __('Couldn’t read OPcache from the server just now.') }}</p>
            @elseif (! $ocEnabled)
                <p class="text-xs text-brand-moss">{{ __('OPcache is not enabled for this PHP version. Enable and size it from the server’s PHP workspace.') }}</p>
            @else
                <div class="space-y-2">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Memory') }}</p>
                                <span class="font-mono text-xs text-brand-ink">{{ $bytesToMb($ocUsed) }} / {{ $bytesToMb($ocTotal) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10">
                                <div class="h-full rounded-full {{ $ocMemPct >= 90 ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $ocMemPct) }}%"></div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Cached keys') }}</p>
                                <span class="font-mono text-xs text-brand-ink">{{ number_format($ocKeys) }} / {{ number_format($ocMaxKeys) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10">
                                <div class="h-full rounded-full {{ $ocKeysPct >= 90 ? 'bg-amber-500' : 'bg-brand-forest' }}" style="width: {{ max(2, $ocKeysPct) }}%"></div>
                            </div>
                        </div>
                    </div>

                    <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <x-fact-row :label="__('Hit rate')" :value="$ocHitRate !== null ? $ocHitRate.'%' : '—'" />
                        <x-fact-row :label="__('Cached scripts')" :value="number_format((int) ($oc['num_cached_scripts'] ?? 0))" />
                        <x-fact-row :label="__('Wasted')" :value="$bytesToMb((int) ($oc['memory_wasted'] ?? 0))" />
                        <x-fact-row :label="__('OOM restarts')">
                            <span class="{{ $ocOom > 0 ? 'text-amber-700' : '' }}">{{ number_format($ocOom) }}</span>
                        </x-fact-row>
                    </dl>

                    @if ($ocPressure)
                        <p class="text-[11px] font-medium text-amber-700">
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

        <div class="{{ $panelFoot }}">
            <button
                type="button"
                wire:click="refreshOpcacheStatus"
                wire:loading.attr="disabled"
                wire:target="refreshOpcacheStatus"
                class="{{ $btnBase }}"
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
                    class="{{ $btnBase }}"
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
    <section class="border-b border-brand-ink/10">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-adjustments-horizontal class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('Effective PHP limits') }}</h2>
                </div>
                <p class="{{ $panelNote }}">{{ __('A value tagged “default” is PHP’s built-in setting (nothing overridden here); plain values are your overrides.') }}</p>
            </div>
            <a href="{{ $phpTabUrl }}" wire:navigate class="shrink-0 {{ $linkBase }}">{{ __('Edit PHP limits') }} →</a>
        </div>

        <div class="{{ $panelBody }}">
            <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($phpLimits as $limit)
                    @php $isOverride = filled($limit['value']); @endphp
                    <x-fact-row :label="$limit['label']" :tone="$isOverride ? 'default' : 'muted'">
                        {{ $isOverride ? $limit['value'] : $limit['default'] }}
                        @unless ($isOverride)
                            <span class="ml-1 rounded bg-brand-sand/70 px-1 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('default') }}</span>
                        @endunless
                    </x-fact-row>
                @endforeach
            </dl>
        </div>
    </section>
@endif

{{-- 1d. Recent errors tail (cheap DB read; full stream on the Errors tab) --}}
@if (! empty($runtimeRecentErrors) && count($runtimeRecentErrors) > 0)
    <section class="border-b border-brand-ink/10">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-exclamation-triangle class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('Recent errors') }}</h2>
                </div>
                <p class="{{ $panelNote }}">{{ __('The latest issues captured for this site. The full history and dismissals live on the Errors tab.') }}</p>
            </div>
            <a href="{{ route('sites.errors', ['server' => $server, 'site' => $site]) }}" wire:navigate class="shrink-0 {{ $linkBase }}">{{ __('View all') }} →</a>
        </div>

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($runtimeRecentErrors as $event)
                <li class="flex items-start gap-2.5 px-5 py-2.5 sm:px-6">
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-rose-500" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ \Illuminate\Support\Str::headline((string) $event->category) }}</span>
                            <p class="min-w-0 truncate text-xs font-medium text-brand-ink">{{ $event->title }}</p>
                        </div>
                        @if (filled($event->detail))
                            <p class="mt-0.5 line-clamp-2 text-[11px] leading-relaxed text-brand-moss">{{ $event->detail }}</p>
                        @endif
                    </div>
                    <time class="shrink-0 whitespace-nowrap text-[11px] text-brand-moss" datetime="{{ optional($event->occurred_at)->toIso8601String() }}">{{ optional($event->occurred_at)->diffForHumans() }}</time>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- 2. Detection panel --}}
<section class="border-b border-brand-ink/10">
    <div class="{{ $panelHead }}">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <x-heroicon-o-magnifying-glass-circle class="{{ $panelIcon }}" aria-hidden="true" />
                <h2 class="{{ $panelTitle }}">{{ __('Repository detection') }}</h2>
            </div>
            <p class="{{ $panelNote }}">{{ __('What Dply inferred from your repository. Detection runs on deploy and container inspect.') }}</p>
        </div>
        @if ($resolvedDetection && ! empty($resolvedDetection['confidence']))
            <span class="{{ $pillBase }} shrink-0 bg-white text-brand-moss ring-brand-ink/10">{{ strtoupper((string) $resolvedDetection['confidence']) }}</span>
        @endif
    </div>

    <div class="{{ $panelBody }}">
        @if ($resolvedDetection)
            <dl class="grid gap-2 sm:grid-cols-2">
                <x-fact-row :label="__('Framework')" :value="str((string) ($resolvedDetection['framework'] ?? '—'))->replace('_', ' ')->title()" :mono="false" />
                <x-fact-row :label="__('Language')" :value="str((string) ($resolvedDetection['language'] ?? '—'))->replace('_', ' ')->title()" :mono="false" />
                @if ($detectionSourceLabel !== '')
                    <x-fact-row :label="__('Source')" :value="$detectionSourceLabel" :mono="false" tone="muted" class="sm:col-span-2" />
                @endif
                @if (! empty($resolvedDetection['laravel_octane']))
                    <x-fact-row :label="__('Laravel Octane')" :mono="false" class="sm:col-span-2">
                        @if ($site->usesOctaneRuntime())
                            {{ __('Enabled — serving on port :port', ['port' => $site->octane_port]) }}
                        @else
                            {{ __('Package detected (`laravel/octane` in composer.json) — set an Octane port under Laravel settings to enable') }}
                        @endif
                    </x-fact-row>
                @endif
                @if (! empty($resolvedDetection['laravel_horizon']))
                    <x-fact-row :label="__('Laravel Horizon')" :value="__('Yes — `laravel/horizon` in composer.json')" :mono="false" class="sm:col-span-2" />
                @endif
                @if (! empty($resolvedDetection['laravel_pulse']))
                    <x-fact-row :label="__('Laravel Pulse')" :value="__('Yes — `laravel/pulse` in composer.json')" :mono="false" class="sm:col-span-2" />
                @endif
                @if (! empty($resolvedDetection['laravel_reverb']))
                    <x-fact-row :label="__('Laravel Reverb')" :value="__('Yes — `laravel/reverb` in composer.json')" :mono="false" class="sm:col-span-2" />
                @endif
            </dl>
            @if (! empty($resolvedDetection['warnings']))
                <div class="mt-2 space-y-1 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    @foreach ($resolvedDetection['warnings'] as $warning)
                        <p>{{ $warning }}</p>
                    @endforeach
                </div>
            @endif
        @else
            <p class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
                <span class="font-semibold text-brand-ink">{{ __('No repository inspection yet.') }}</span>
                {{ __('After a deploy or container inspect, framework and language signals from your repo will appear here.') }}
            </p>
        @endif
    </div>
</section>

{{-- Background processes callout --}}
@if ($site->type !== \App\Enums\SiteType::Static)
<section class="border-b border-brand-ink/10">
    <div class="{{ $panelHead }}">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <x-heroicon-o-arrow-path class="{{ $panelIcon }}" aria-hidden="true" />
                <h2 class="{{ $panelTitle }}">{{ __('Workers & schedulers') }}</h2>
            </div>
            <p class="{{ $panelNote }}">
                @if (in_array((string) ($site->runtime ?? ''), ['php'], true) || $site->isLaravelFrameworkDetected())
                    {{ __('Queue workers and Horizon run under Workers (Supervisor). Scheduled tasks use Cron or the Laravel tab.') }}
                @elseif ($site->isRailsFrameworkDetected())
                    {{ __('Sidekiq and Solid Queue run under Workers (Supervisor). Optional systemd workers are on the Services page.') }}
                @else
                    {{ __('App servers: set start command and port above. Workers can use systemd (Services) or Supervisor (Workers).') }}
                @endif
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1">
            <a href="{{ route('sites.daemons', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $linkBase }}">{{ __('Workers') }} →</a>
            <a href="{{ route('servers.cron', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $linkBase }}">{{ __('Cron jobs') }} →</a>
            @if (\App\Models\Site::supportsSystemdServices($site, $server))
                <a href="{{ route('sites.services', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $linkBase }}">{{ __('Services (systemd)') }} →</a>
            @endif
            @if ($site->isLaravelFrameworkDetected())
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'laravel-stack']) }}" wire:navigate class="{{ $linkBase }}">{{ __('Laravel') }} →</a>
            @endif
            @if ($site->isRailsFrameworkDetected())
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'rails-stack']) }}" wire:navigate class="{{ $linkBase }}">{{ __('Rails') }} →</a>
            @endif
        </div>
    </div>
</section>
@endif

{{-- 4. Container lifecycle (Docker only) --}}
@if ($site->usesDockerRuntime())
    @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
        <section class="border-b border-brand-ink/10">
            <div class="{{ $panelHead }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cube class="{{ $panelIcon }}" aria-hidden="true" />
                        <h2 class="{{ $panelTitle }}">{{ __('Docker discovery') }}</h2>
                    </div>
                    <p class="{{ $panelNote }}">{{ __('Saved from the live Docker runtime so hostname, IP, and container identity stay referenceable later.') }}</p>
                </div>
                @if (! empty($dockerRuntimeDetails['collected_at']))
                    <p class="shrink-0 font-mono text-[11px] text-brand-moss">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                @endif
            </div>

            <div class="{{ $panelBody }} space-y-2">
                <dl class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <x-fact-row :label="__('Hostname')" :value="$runtimePublication['hostname'] ?? '—'" />
                    <x-fact-row :label="__('Container IP')" :value="$runtimePublication['container_ip'] ?? '—'" />
                    <x-fact-row :label="__('Container name')" :value="$runtimePublication['container_name'] ?? '—'" />
                    <x-fact-row :label="__('Service')" :value="$runtimePublication['docker_service'] ?? '—'" />
                </dl>

                @if ($dockerContainers->isNotEmpty())
                    <div class="overflow-hidden rounded-lg border border-brand-ink/10 bg-white">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-left">
                                <thead class="bg-brand-sand/40">
                                    <tr>
                                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Name') }}</th>
                                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Service') }}</th>
                                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Hostname') }}</th>
                                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('IP') }}</th>
                                        <th class="px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('State') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10 bg-white">
                                    @foreach ($dockerContainers as $container)
                                        <tr>
                                            <td class="px-3 py-1.5 font-mono text-xs text-brand-ink">{{ $container['name'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 font-mono text-xs text-brand-ink">{{ $container['service'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 font-mono text-xs text-brand-ink">{{ $container['orb_hostname'] ?? $container['hostname'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 font-mono text-xs text-brand-ink">{{ $container['ipv4'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 font-mono text-xs text-brand-ink">{{ $container['state'] ?? '—' }}</td>
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
        <section class="border-b border-brand-ink/10">
            <div class="{{ $panelHead }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-arrows-pointing-out class="{{ $panelIcon }}" aria-hidden="true" />
                        <h2 class="{{ $panelTitle }}">{{ __('Container lifecycle') }}</h2>
                    </div>
                    <p class="{{ $panelNote }}">{{ __('Lifecycle and inspection for the local container runtime behind this app. Output, logs, and historical operations live on the Logs tab.') }}</p>
                </div>
            </div>

            <div class="{{ $panelBody }} flex flex-wrap items-center gap-2">
                <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-lg bg-brand-ink px-3 py-1 text-xs font-semibold text-white hover:bg-brand-ink/90">{{ __('Rebuild') }}</button>
                <button type="button" wire:click="runRuntimeAction('start')" class="{{ $btnBase }}">{{ __('Start') }}</button>
                <button type="button" wire:click="runRuntimeAction('stop')" class="{{ $btnBase }}">{{ __('Stop') }}</button>
                <button type="button" wire:click="runRuntimeAction('restart')" class="{{ $btnBase }}">{{ __('Restart') }}</button>
                <span class="mx-1 h-4 w-px bg-brand-ink/10" aria-hidden="true"></span>
                <button type="button" wire:click="runRuntimeAction('inspect')" class="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-800 hover:bg-sky-100">{{ __('Refresh Docker details') }}</button>
                <button type="button" wire:click="runRuntimeAction('status')" class="{{ $btnBase }}">{{ __('Status') }}</button>
            </div>

            <div class="{{ $panelFoot }}">
                <p class="text-[11px] text-brand-moss">{{ __('Removes managed local containers and artifacts for this app.') }}</p>
                <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this app?')), @js(__('Destroy runtime')), true)" class="rounded-lg border border-red-200 bg-white px-2.5 py-1 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">{{ __('Destroy') }}</button>
            </div>
        </section>
    @endif
@endif

{{-- 5. Working directory — one line, not a panel --}}
<div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-2.5 sm:px-6">
    <div class="flex shrink-0 items-center gap-2">
        <x-heroicon-o-folder class="{{ $panelIcon }}" aria-hidden="true" />
        <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Working directory') }}</span>
    </div>
    <code class="min-w-0 break-all font-mono text-xs text-brand-ink">{{ $site->effectiveRepositoryPath() }}</code>
</div>

{{-- 6. CLI snippets --}}
<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
    <x-cli-snippet :commands="[
        ['label' => __('Set runtime + version'), 'command' => 'dply sites:runtime:set '.$site->slug.' --runtime=node --runtime-version=22'],
        ['label' => __('Set start command + port'), 'command' => 'dply sites:runtime:set '.$site->slug.' --start=\'node server.js\' --port=3000'],
        ['label' => __('Auto-detect from repo'), 'command' => 'dply:detect-runtime '.$site->slug],
        ['label' => __('Show available runtimes'), 'command' => 'dply:list-runtimes --with-usage'],
        ['label' => __('Install runtime on server'), 'command' => 'dply:install-runtime '.($server->name ?? 'SERVER').' node 22'],
    ]" />
</div>
