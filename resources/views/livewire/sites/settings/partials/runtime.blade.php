@php
    $resolvedDetection = $site->resolvedRuntimeAppDetection() ?? [];
    $detectedFramework = strtolower((string) ($resolvedDetection['framework'] ?? ''));
    $detectionSourceLabel = match ($resolvedDetection['source'] ?? null) {
        'docker' => __('Docker inspection'),
        'kubernetes' => __('Kubernetes inspection'),
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

    $panelBody = 'px-5 py-3 sm:px-6';
    $panelFoot = 'flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:px-4';
    $pillBase = 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] ring-1';
    $btnBase = 'dply-btn dply-btn-xs dply-btn-outline';
    $linkBase = 'text-xs font-semibold text-brand-forest hover:text-brand-sage hover:underline';
    $detectedFrameworkLabel = filled($resolvedDetection['framework'] ?? null)
        ? (string) str((string) $resolvedDetection['framework'])->replace('_', ' ')->title()
        : '';
    $detectedLanguageLabel = filled($resolvedDetection['language'] ?? null)
        ? (string) str((string) $resolvedDetection['language'])->replace('_', ' ')->title()
        : '';
    $showDetectedLanguage = $detectedLanguageLabel !== ''
        && strcasecmp($detectedLanguageLabel, $runtimeLabel) !== 0;
    $workersSchedulersNote = (in_array((string) ($site->runtime ?? ''), ['php'], true) || $site->isLaravelFrameworkDetected())
        ? __('Queue workers live on Workers. Scheduled tasks live on Cron.')
        : ($site->isRailsFrameworkDetected()
            ? __('Sidekiq lives on Workers. Optional systemd workers are on Services.')
            : __('Background processes live on Workers, Cron, or Services.'));
    $runtimeCliVersion = $runtimeVersion !== '' ? $runtimeVersion : match ($runtimeKey) {
        'php' => '8.4',
        'ruby' => '3.3',
        default => '22',
    };
    $runtimeCliCommands = match ($runtimeKey) {
        'php' => [
            ['label' => __('Set PHP version'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --runtime=php --runtime-version='.$runtimeCliVersion],
            ['label' => __('Auto-detect from repo'), 'command' => 'dply:detect-runtime '.$site->slug],
            ['label' => __('Show available runtimes'), 'command' => 'dply:list-runtimes --with-usage'],
        ],
        'ruby' => [
            ['label' => __('Set Ruby version'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --runtime=ruby --runtime-version='.$runtimeCliVersion],
            ['label' => __('Auto-detect from repo'), 'command' => 'dply:detect-runtime '.$site->slug],
            ['label' => __('Show available runtimes'), 'command' => 'dply:list-runtimes --with-usage'],
        ],
        'static' => [
            ['label' => __('Set static runtime'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --runtime=static'],
            ['label' => __('Auto-detect from repo'), 'command' => 'dply:detect-runtime '.$site->slug],
            ['label' => __('Show available runtimes'), 'command' => 'dply:list-runtimes --with-usage'],
        ],
        default => [
            ['label' => __('Set runtime + version'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --runtime='.($runtimeKey !== '' ? $runtimeKey : 'node').' --runtime-version='.$runtimeCliVersion],
            ['label' => __('Set start command + port'), 'command' => 'dply dply:site:set-runtime '.$site->slug.' --start=\'node server.js\' --port=3000'],
            ['label' => __('Auto-detect from repo'), 'command' => 'dply:detect-runtime '.$site->slug],
            ['label' => __('Show available runtimes'), 'command' => 'dply:list-runtimes --with-usage'],
            ['label' => __('Install runtime on server'), 'command' => 'dply:install-runtime '.($server->name ?? 'SERVER').' '.($runtimeKey !== '' ? $runtimeKey : 'node').' '.$runtimeCliVersion],
        ],
    };
@endphp

{{-- What it runs: language, detection, path --}}
@include('livewire.sites.settings.partials.runtime._picker')

<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        class="border-b border-brand-ink/10"
        icon="heroicon-o-cube-transparent"
        :title="__('What it runs')"
        :note="__('Language, detected framework, and the checkout path on this server.')"
    >
        <x-slot:actions>
            <span class="inline-flex shrink-0 items-center rounded-full bg-white px-2 py-0.5 font-mono text-xs font-semibold {{ $runtimeDisplay === __('Not set') ? 'text-brand-moss' : 'text-brand-ink' }} ring-1 ring-brand-ink/10">{{ $runtimeDisplay }}</span>
            @if ($resolvedDetection && ! empty($resolvedDetection['confidence']))
                <span class="{{ $pillBase }} shrink-0 bg-white text-brand-moss ring-brand-ink/10">{{ strtoupper((string) $resolvedDetection['confidence']) }}</span>
            @endif
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="{{ $panelBody }} space-y-2.5">
        <dl class="grid gap-2 sm:grid-cols-2">
            @if ($detectedFrameworkLabel !== '')
                <x-fact-row :label="__('Framework')" :value="$detectedFrameworkLabel" :mono="false" />
            @endif
            @if ($showDetectedLanguage)
                <x-fact-row :label="__('Language')" :value="$detectedLanguageLabel" :mono="false" />
            @endif
            <x-fact-row :label="__('Working directory')" :value="$site->effectiveRepositoryPath()" class="sm:col-span-2" />
            @if ($detectionSourceLabel !== '')
                <x-fact-row :label="__('Detected from')" :value="$detectionSourceLabel" :mono="false" tone="muted" class="sm:col-span-2" />
            @endif
            @if ($site->internal_port)
                <x-fact-row :label="__('Internal port')" value="127.0.0.1:{{ $site->internal_port }}" />
            @endif
            @if ($site->start_command)
                <x-fact-row :label="__('Start command')" :value="$site->start_command" class="sm:col-span-2" />
            @endif
            @if (! empty($resolvedDetection['laravel_octane']))
                <x-fact-row :label="__('Laravel Octane')" :mono="false" class="sm:col-span-2">
                    @if ($site->usesOctaneRuntime())
                        {{ __('Enabled — serving on port :port', ['port' => $site->octane_port]) }}
                    @else
                        {{ __('Package detected — set an Octane port under Laravel settings to enable.') }}
                    @endif
                </x-fact-row>
            @endif
            @if (! empty($resolvedDetection['laravel_horizon']))
                <x-fact-row :label="__('Laravel Horizon')" :value="__('Detected in composer.json')" :mono="false" />
            @endif
            @if (! empty($resolvedDetection['laravel_pulse']))
                <x-fact-row :label="__('Laravel Pulse')" :value="__('Detected in composer.json')" :mono="false" />
            @endif
            @if (! empty($resolvedDetection['laravel_reverb']))
                <x-fact-row :label="__('Laravel Reverb')" :value="__('Detected in composer.json')" :mono="false" />
            @endif
        </dl>

        @if (! $resolvedDetection)
            <p class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
                <span class="font-semibold text-brand-ink">{{ __('No repository inspection yet.') }}</span>
                {{ __('After a deploy, framework signals from your repo will show up here.') }}
            </p>
        @elseif (! empty($resolvedDetection['warnings']))
            <div class="space-y-1 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                @foreach ($resolvedDetection['warnings'] as $warning)
                    <p>{{ $warning }}</p>
                @endforeach
            </div>
        @endif

        @if ($showAppPortEditor)
            <form wire:submit="saveRuntimePreferences" class="flex flex-wrap items-end gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                <div class="min-w-0">
                    <x-input-label for="runtime_app_port_input" :value="__('App listens on (localhost)')" class="!text-xs" />
                    <x-text-input id="runtime_app_port_input" type="number" wire:model="runtime_app_port" class="mt-1 block w-[8rem] font-mono text-sm" placeholder="3000" min="1" max="65535" />
                    <x-input-error :messages="$errors->get('runtime_app_port')" class="mt-1" />
                </div>
                <x-primary-button size="sm" type="submit">{{ __('Save') }}</x-primary-button>
                <p class="w-full text-xs text-brand-moss sm:w-auto sm:flex-1 sm:text-right">{{ __('The webserver proxies to this localhost port.') }}</p>
            </form>
        @endif
    </div>
</section>

{{-- Live runtime health (deferred via wire:init): FPM pool or app-server port.
     Gated on the server actually having PHP: probing an FPM pool on a
     php_version=none box can only ever report "Couldn't read the pool", which
     reads as a transient error rather than "there is no PHP here". --}}
@if ($site->runsPhpOnItsServer() && $site->runtimeHealthProbeKind() === 'fpm')
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
        <x-workspace-panel-head
            class="border-b border-brand-ink/10"
            icon="heroicon-o-bolt"
            :title="__('PHP-FPM pool')"
            :note="__('How many workers are handling requests right now.')"
        >
            <x-slot:actions>
                @if (! $runtimeHealthLoaded)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($runtimeHealth === null)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Unavailable') }}
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
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }} space-y-2">
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Workers') }}</p>
                    @if (! $runtimeHealthLoaded)
                        <span class="flex items-center gap-1.5 text-xs text-brand-moss">
                            <x-spinner variant="forest" class="h-3 w-3 shrink-0" />
                            {{ __('Reading…') }}
                        </span>
                    @elseif ($runtimeHealth === null)
                        <span class="text-xs text-brand-moss">{{ __('Couldn’t read the pool just now.') }}</span>
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
                        <p class="mt-1.5 text-xs font-medium text-amber-700">{{ __('Near the worker ceiling (:pct%). Consider raising max children on the PHP tab.', ['pct' => $fpmPct]) }}</p>
                    @endif
                    @if (! $runtimeHealth['conf_present'])
                        <p class="mt-1.5 text-xs font-medium text-amber-700">{{ __('Pool config not found on disk yet — written on the next webserver apply.') }}</p>
                    @endif
                @endif
            </div>

            <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <x-fact-row :label="__('Process manager')" :value="$pmLabel" :mono="false" />
                <x-fact-row :label="__('Max children')" :value="$pool['max_children']" />
                <x-fact-row :label="__('Max requests')" :value="$pool['max_requests'] ?: __('Unlimited')" />
                <x-fact-row :label="__('Request timeout')" :value="$pool['request_terminate_timeout'].'s'" />
                <x-fact-row :label="__('Listen socket')" :value="$socketPath" class="sm:col-span-2 lg:col-span-4" />
            </dl>
        </div>

        <div class="{{ $panelFoot }}">
            <div class="flex flex-wrap items-center gap-1.5">
                <button type="button" wire:click="refreshRuntimeHealth" wire:loading.attr="disabled" wire:target="refreshRuntimeHealth" class="{{ $btnBase }}">
                    <x-spinner wire:loading wire:target="refreshRuntimeHealth" variant="forest" class="h-3 w-3" />
                    {{ __('Refresh') }}
                </button>
                @can('update', $site)
                    <button type="button" wire:click="reloadFpmPool" wire:loading.attr="disabled" wire:target="reloadFpmPool" class="{{ $btnBase }}">
                        <x-spinner wire:loading wire:target="reloadFpmPool" variant="forest" class="h-3 w-3" />
                        {{ __('Reload pool') }}
                    </button>
                @endcan
            </div>
            <a href="{{ $phpTabUrl }}" wire:navigate class="{{ $linkBase }}">{{ __('Tune on PHP tab') }} →</a>
        </div>
    </section>
@elseif ($site->runtimeHealthProbeKind() === 'port')
    @php $appPort = (int) $site->app_port; @endphp
    <section class="border-b border-brand-ink/10" wire:init="loadRuntimeHealth">
        <x-workspace-panel-head
            class="border-b border-brand-ink/10"
            icon="heroicon-o-signal"
            :title="__('App server port')"
            :note="__('Whether the app accepts connections on its localhost proxy target.')"
        >
            <x-slot:actions>
                @if (! $runtimeHealthLoaded)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($runtimeHealth === null)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Unavailable') }}
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
            </x-slot:actions>
        </x-workspace-panel-head>

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
                <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Check the start command and that workers are running — see Workers (Supervisor) or Services (systemd). Confirm the app binds to 127.0.0.1 on this port.') }}</p>
            @endif
        </div>

        <div class="{{ $panelFoot }}">
            <button type="button" wire:click="refreshRuntimeHealth" wire:loading.attr="disabled" wire:target="refreshRuntimeHealth" class="{{ $btnBase }}">
                <x-spinner wire:loading wire:target="refreshRuntimeHealth" variant="forest" class="h-3 w-3" />
                {{ __('Re-check') }}
            </button>
        </div>
    </section>
@endif

{{-- OPcache --}}
@if ($site->runsPhpOnItsServer() && $site->usesDedicatedPhpFpmPool())
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
        <x-workspace-panel-head
            class="border-b border-brand-ink/10"
            icon="heroicon-o-cpu-chip"
            :title="__('OPcache')"
            :note="__('Bytecode cache for this site’s FPM workers. Flush after you change files outside a deploy.')"
        >
            <x-slot:actions>
                @if (! $opcacheStatusLoaded)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <x-spinner variant="forest" class="h-3 w-3" />
                        {{ __('Checking…') }}
                    </span>
                @elseif ($oc === null)
                    <span class="{{ $pillBase }} bg-white text-brand-moss ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                        {{ __('Unavailable') }}
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
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }}">
            @if (! $opcacheStatusLoaded)
                <p class="flex items-center gap-2 text-xs text-brand-moss">
                    <x-spinner variant="forest" class="h-3 w-3 shrink-0" />
                    {{ __('Reading OPcache…') }}
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

                    <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <x-fact-row :label="__('Hit rate')" :value="$ocHitRate !== null ? $ocHitRate.'%' : '—'" />
                        <x-fact-row :label="__('Cached scripts')" :value="number_format((int) ($oc['num_cached_scripts'] ?? 0))" />
                        <x-fact-row :label="__('Wasted')" :value="$bytesToMb((int) ($oc['memory_wasted'] ?? 0))" />
                        <x-fact-row :label="__('OOM restarts')">
                            <span class="{{ $ocOom > 0 ? 'text-amber-700' : '' }}">{{ number_format($ocOom) }}</span>
                        </x-fact-row>
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

        <div class="{{ $panelFoot }}">
            <button type="button" wire:click="refreshOpcacheStatus" wire:loading.attr="disabled" wire:target="refreshOpcacheStatus" class="{{ $btnBase }}">
                <x-spinner wire:loading wire:target="refreshOpcacheStatus" variant="forest" class="h-3 w-3" />
                {{ __('Refresh') }}
            </button>
            @can('update', $site)
                <button
                    type="button"
                    wire:click="openConfirmActionModal('resetOpcache', [], @js(__('Flush OPcache')), @js(__('Flush OPcache for this site? Workers recompile from disk on the next request.')), @js(__('Flush OPcache')), true)"
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

{{-- Effective PHP limits --}}
@if ($site->runsPhpOnItsServer())
    @php
        $phpRuntime = is_array($site->meta['php_runtime'] ?? null) ? $site->meta['php_runtime'] : [];
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
        <x-workspace-panel-head
            class="border-b border-brand-ink/10"
            icon="heroicon-o-adjustments-horizontal"
            :title="__('PHP limits')"
            :note="__('Memory, time, and upload caps for this site. Values marked default are PHP’s built-ins.')"
        >
            <x-slot:actions>
                <a href="{{ $phpTabUrl }}" wire:navigate class="{{ $linkBase }}">{{ __('Edit') }} →</a>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }}">
            <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($phpLimits as $limit)
                    @php $isOverride = filled($limit['value']); @endphp
                    <x-fact-row :label="$limit['label']" :tone="$isOverride ? 'default' : 'muted'">
                        {{ $isOverride ? $limit['value'] : $limit['default'] }}
                        @unless ($isOverride)
                            <span class="ml-1 rounded bg-brand-sand/70 px-1 py-0.5 text-xxs font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('default') }}</span>
                        @endunless
                    </x-fact-row>
                @endforeach
            </dl>
        </div>
    </section>
@endif

{{-- Recent errors --}}
@if (! empty($runtimeRecentErrors) && count($runtimeRecentErrors) > 0)
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            class="border-b border-brand-ink/10"
            icon="heroicon-o-exclamation-triangle"
            :title="__('Recent errors')"
            :note="__('Latest issues for this site. Full history is on Errors.')"
        >
            <x-slot:actions>
                <a href="{{ route('sites.errors', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $linkBase }}">{{ __('View all') }} →</a>
            </x-slot:actions>
        </x-workspace-panel-head>

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($runtimeRecentErrors as $event)
                <li class="flex items-start gap-2.5 px-3 py-2 sm:px-4">
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-rose-500" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ \Illuminate\Support\Str::headline((string) $event->category) }}</span>
                            <p class="min-w-0 truncate text-xs font-medium text-brand-ink">{{ $event->title }}</p>
                        </div>
                        @if (filled($event->detail))
                            <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-brand-moss">{{ $event->detail }}</p>
                        @endif
                    </div>
                    <time class="shrink-0 whitespace-nowrap text-xs text-brand-moss" datetime="{{ optional($event->occurred_at)->toIso8601String() }}">{{ optional($event->occurred_at)->diffForHumans() }}</time>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- Workers & schedules (links only — manage under Daemons / Cron) --}}
@if ($site->type !== \App\Enums\SiteType::Static)
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6">
        <div class="min-w-0 flex-1 basis-72">
            <div class="flex items-center gap-2">
                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <p class="text-sm font-semibold text-brand-ink">{{ __('Workers & schedules') }}</p>
            </div>
            <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $workersSchedulersNote }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <a href="{{ route('sites.daemons', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $linkBase }}">{{ __('Workers') }} →</a>
            <a href="{{ route('servers.cron', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $linkBase }}">{{ __('Cron') }} →</a>
            @if (\App\Models\Site::supportsSystemdServices($site, $server))
                <a href="{{ route('sites.services', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $linkBase }}">{{ __('Services') }} →</a>
            @endif
            @if ($site->isLaravelFrameworkDetected())
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'laravel-stack']) }}" wire:navigate class="{{ $linkBase }}">{{ __('Laravel') }} →</a>
            @endif
            @if ($site->isRailsFrameworkDetected())
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'rails-stack']) }}" wire:navigate class="{{ $linkBase }}">{{ __('Rails') }} →</a>
            @endif
        </div>
    </div>
@endif

{{-- Docker --}}
@if ($site->usesDockerRuntime())
    @if ($dockerContainers->isNotEmpty() || $runtimePublication !== [])
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                class="border-b border-brand-ink/10"
                icon="heroicon-o-cube"
                :title="__('Docker discovery')"
                :note="__('Hostname, IP, and container identity from the live Docker runtime.')"
            >
                @if (! empty($dockerRuntimeDetails['collected_at']))
                    <x-slot:actions>
                        <p class="font-mono text-xs text-brand-moss">{{ __('Collected :time', ['time' => $dockerRuntimeDetails['collected_at']]) }}</p>
                    </x-slot:actions>
                @endif
            </x-workspace-panel-head>

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
                                        <th class="px-3 py-1.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Name') }}</th>
                                        <th class="px-3 py-1.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Service') }}</th>
                                        <th class="px-3 py-1.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Hostname') }}</th>
                                        <th class="px-3 py-1.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('IP') }}</th>
                                        <th class="px-3 py-1.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('State') }}</th>
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
            <x-workspace-panel-head
                class="border-b border-brand-ink/10"
                icon="heroicon-o-arrows-pointing-out"
                :title="__('Container lifecycle')"
                :note="__('Start, stop, and inspect this container. Output lives on Logs.')"
            />

            <div class="{{ $panelBody }} flex flex-wrap items-center gap-1.5">
                <button type="button" wire:click="runRuntimeAction('rebuild')" class="rounded-lg bg-brand-ink px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-ink/90">{{ __('Rebuild') }}</button>
                <button type="button" wire:click="runRuntimeAction('start')" class="{{ $btnBase }}">{{ __('Start') }}</button>
                <button type="button" wire:click="runRuntimeAction('stop')" class="{{ $btnBase }}">{{ __('Stop') }}</button>
                <button type="button" wire:click="runRuntimeAction('restart')" class="{{ $btnBase }}">{{ __('Restart') }}</button>
                <span class="mx-1 h-4 w-px bg-brand-ink/10" aria-hidden="true"></span>
                <button type="button" wire:click="runRuntimeAction('inspect')" class="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-800 hover:bg-sky-100">{{ __('Refresh Docker') }}</button>
                <button type="button" wire:click="runRuntimeAction('status')" class="{{ $btnBase }}">{{ __('Status') }}</button>
            </div>

            <div class="{{ $panelFoot }}">
                <p class="text-xs text-brand-moss">{{ __('Removes managed local containers and artifacts for this app.') }}</p>
                <button type="button" wire:click="openConfirmActionModal('runRuntimeAction', ['destroy'], @js(__('Destroy runtime')), @js(__('Destroy the managed local runtime artifacts and containers for this app?')), @js(__('Destroy runtime')), true)" class="rounded-lg border border-red-200 bg-white px-2.5 py-1 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">{{ __('Destroy') }}</button>
            </div>
        </section>
    @endif
@endif

{{-- CLI --}}
<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
    <x-cli-snippet :commands="$runtimeCliCommands" />
</div>
