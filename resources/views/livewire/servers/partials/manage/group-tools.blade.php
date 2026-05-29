@php
    $report = $toolsReport ?? null;
    $meta = $server->meta ?? [];
    $manageMiseRuntimes = is_array($meta['manage_mise_runtimes'] ?? null) ? $meta['manage_mise_runtimes'] : [];
    $manageSystemRuntimes = is_array($meta['manage_system_runtimes'] ?? null) ? $meta['manage_system_runtimes'] : [];
    $checkedAt = $report['checked_at'] ?? null;
    $miseRuntimesProbed = (bool) ($report['mise_runtimes_probed'] ?? false);

    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
    ];

    $summary = $report['summary'] ?? [];
    $catalogRows = $report['catalog_rows'] ?? [];
    $genericTools = $report['generic_tools'] ?? [];
    $heroTool = $report['hero_tool'] ?? null;

    $overall = $report['overall'] ?? 'ready';
    $overallTone = match ($overall) {
        'stale' => $tonePalette['amber'],
        'blocked' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $statusTone = static function (string $tone) use ($tonePalette): string {
        return $tonePalette[$tone] ?? $tonePalette['mist'];
    };

    $statusBadgeDot = static function (string $tone): string {
        return match ($tone) {
            'emerald' => 'bg-emerald-600',
            'sage' => 'bg-brand-forest',
            'amber' => 'bg-amber-500',
            default => 'bg-brand-mist',
        };
    };

    $runtimeCatalog = is_array($report['mise_runtime_catalog'] ?? null) && ($report['mise_runtime_catalog'] ?? []) !== []
        ? $report['mise_runtime_catalog']
        : config('server_manage.mise_runtimes', []);

    $misePresent = (bool) ($heroTool['present'] ?? false);
    $miseVersion = $heroTool['version'] ?? null;
    $miseAction = is_array($heroTool['action'] ?? null) ? $heroTool['action'] : null;
    $misePruneAction = is_array($serviceActions['mise_prune'] ?? null) ? $serviceActions['mise_prune'] : null;
    $miseReshimAction = is_array($serviceActions['mise_reshim'] ?? null) ? $serviceActions['mise_reshim'] : null;
    $activeMiseRuntimeOps = is_array($activeMiseRuntimeOps ?? null) ? $activeMiseRuntimeOps : [];
    $activeToolActionOps = is_array($activeToolActionOps ?? null) ? $activeToolActionOps : [];
    $miseReprobePending = (bool) ($miseReprobePending ?? false);

    $toolActionOperation = static function (array $tool) use ($activeToolActionOps): ?array {
        foreach ([$tool['present_action_key'] ?? null, $tool['action_key'] ?? null] as $key) {
            if (is_string($key) && $key !== '' && isset($activeToolActionOps[$key])) {
                return $activeToolActionOps[$key];
            }
        }

        return null;
    };
@endphp

<section class="space-y-6" aria-labelledby="manage-tools-title">
    <h2 id="manage-tools-title" class="sr-only">{{ __('Tools') }}</h2>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <x-explainer>
                <p>{{ __('Server toolchain — presence + version pills read from the last inventory probe, with operator-facing install / repair actions. The mise card lists managed runtimes (Node, Python, Ruby, Go, Bun, Deno, Java) and exposes install, uninstall, and default actions per version.') }}</p>
            </x-explainer>
        </div>
        @if ($opsReady && ! $isDeployer)
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($miseReprobePending)
                    <span class="inline-flex items-center gap-1.5 rounded-md border border-brand-sage/30 bg-brand-sage/10 px-2.5 py-1.5 text-xs font-medium text-brand-forest">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Refreshing probe…') }}
                    </span>
                @endif
                <button
                type="button"
                wire:click="refreshServerInventoryDetails"
                wire:loading.attr="disabled"
                wire:target="refreshServerInventoryDetails"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Refresh probe') }}
                </span>
                <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Refreshing…') }}
                </span>
                </button>
            </div>
        @endif
    </div>

    @if ($report)
        {{-- Overall --}}
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}">
                            <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Server toolchain') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @switch($overall)
                                    @case('stale')
                                        {{ __('Probe data is stale — refresh recommended') }}
                                        @break
                                    @case('blocked')
                                        {{ __('SSH not ready for install actions') }}
                                        @break
                                    @default
                                        {{ __(':installed of :total tools detected', [
                                            'installed' => $summary['installed_count'] ?? 0,
                                            'total' => $summary['catalog_count'] ?? 0,
                                        ]) }}
                                @endswitch
                            </h3>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($overall === 'stale')
                                    {{ __('The last inventory probe predates toolchain fields — click Refresh probe to populate version pills and the catalog below.') }}
                                @elseif ($checkedAt)
                                    {{ __('Last probed :time', ['time' => $checkedAt->diffForHumans()]) }}
                                    @if ($summary['mise_present'] ?? false)
                                        · {{ trans_choice(':count mise runtime version|:count mise runtime versions', $summary['runtime_versions'] ?? 0, ['count' => $summary['runtime_versions'] ?? 0]) }}
                                    @endif
                                @else
                                    {{ __('No inventory probe yet — refresh when SSH is ready.') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    ['label' => __('In catalog'), 'value' => number_format((int) ($summary['catalog_count'] ?? 0))],
                    ['label' => __('Installed'), 'value' => number_format((int) ($summary['installed_count'] ?? 0))],
                    ['label' => __('mise'), 'value' => ($summary['mise_present'] ?? false) ? __('Yes') : __('No')],
                    ['label' => __('Runtime versions'), 'value' => number_format((int) ($summary['runtime_versions'] ?? 0))],
                    ['label' => __('PHP stack'), 'value' => ($summary['php_available'] ?? false) ? __('Yes') : __('No')],
                    ['label' => __('Probe'), 'value' => $checkedAt ? $checkedAt->diffForHumans() : __('Never')],
                ] as $stat)
                    <div class="bg-white px-4 py-3.5">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                        <p class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Catalog table removed — install/repair actions live on the tool cards below. --}}

        {{-- Related --}}
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sky'] }}">
                            <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Caches') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Provision Redis/Valkey and use redis-cli stats, key browser, and REPL.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-5 text-sm sm:px-7">
                    <a href="{{ route('servers.caches', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                        {{ __('Open Caches') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('PHP') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('PHP versions, extensions, and FPM pools — Composer installs appear here when PHP is present.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-5 text-sm sm:px-7">
                    <a href="{{ route('servers.php', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                        {{ __('Open PHP') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['mist'] }}">
                            <x-heroicon-o-play-circle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Run') }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Ad-hoc SSH commands and saved recipes — use for Git install when the CLI is missing.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-5 text-sm sm:px-7">
                    <a href="{{ route('servers.run', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                        {{ __('Open Run') }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </div>
            </section>

            @feature('workspace.docker')
                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                                <x-heroicon-o-square-3-stack-3d class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Docker') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Engine overview, container start/stop/restart, and image prune.') }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-5 text-sm sm:px-7">
                        <a href="{{ route('servers.docker', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                            {{ __('Open Docker') }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    </div>
                </section>
            @endfeature
        </div>
    @endif

    {{-- mise hero card --}}
    @if ($heroTool)
        <div class="{{ $card }} flex flex-col p-6 sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-dynamic-component :component="$heroTool['icon']" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-brand-ink">{{ $heroTool['label'] }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $heroTool['description'] }}</p>
                        @if ($heroTool['docs_url'])
                            <a href="{{ $heroTool['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-ink hover:text-brand-sage">
                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                {{ __('Upstream docs') }}
                            </a>
                        @endif
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($misePresent)
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 {{ $tonePalette['emerald'] }}" title="{{ __('Laid down during server provisioning.') }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusBadgeDot('emerald') }}"></span>
                            {{ __('Preinstalled') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 {{ $tonePalette['amber'] }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusBadgeDot('amber') }}"></span>
                            {{ __('Not detected') }}
                        </span>
                    @endif
                </div>
            </div>

            <dl class="mt-5 grid gap-3 text-xs sm:grid-cols-3">
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                    <dd class="mt-1 font-mono text-brand-ink">{{ $miseVersion ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last probed') }}</dt>
                    <dd class="mt-1 text-brand-moss">
                        @if ($checkedAt)
                            {{ $checkedAt->diffForHumans() }}
                        @else
                            {{ __('never') }}
                        @endif
                    </dd>
                </div>
                @if ($miseAction && $heroTool['action_key'] && $opsReady && ! $isDeployer)
                    <div class="sm:col-span-2 sm:justify-self-end">
                        <div class="flex flex-wrap justify-end gap-2">
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $heroTool['action_key'] }}'], @js($miseAction['label']), @js($miseAction['confirm']), @js($miseAction['label']), false)"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                <x-dynamic-component :component="$heroTool['icon']" class="h-4 w-4 opacity-80" />
                                {{ $miseAction['label'] }}
                            </button>
                            @if ($misePresent && $misePruneAction)
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('runAllowlistedAction', ['mise_prune'], @js($misePruneAction['label']), @js($misePruneAction['confirm']), @js($misePruneAction['label']), false)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-trash class="h-4 w-4 opacity-80" aria-hidden="true" />
                                    {{ $misePruneAction['label'] }}
                                </button>
                            @endif
                            @if ($misePresent && $miseReshimAction)
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('runAllowlistedAction', ['mise_reshim'], @js($miseReshimAction['label']), @js($miseReshimAction['confirm']), @js($miseReshimAction['label']), false)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-arrow-path class="h-4 w-4 opacity-80" aria-hidden="true" />
                                    {{ $miseReshimAction['label'] }}
                                </button>
                            @endif
                        </div>
                    </div>
                @endif
            </dl>

            @if ($misePresent)
                <div class="mt-6 border-t border-brand-ink/10 pt-5">
                    <h4 class="text-sm font-semibold text-brand-ink">{{ __('Managed runtimes') }}</h4>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Versions installed under the deploy user via mise. The default is what new sites without a pinned version pick up.') }}</p>

                    @if (! $miseRuntimesProbed)
                        <p class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-xs text-brand-moss">
                            {{ __('No runtime probe data yet — click Refresh probe at the top of the page to populate this list.') }}
                        </p>
                    @else
                        <div class="mt-4 space-y-4">
                            @foreach ($runtimeCatalog as $runtime => $catalog)
                                @php
                                    $entry = is_array($manageMiseRuntimes[$runtime] ?? null) ? $manageMiseRuntimes[$runtime] : ['versions' => [], 'active' => null];
                                    $versions = is_array($entry['versions'] ?? null) ? $entry['versions'] : [];
                                    $active = is_string($entry['active'] ?? null) && $entry['active'] !== '' ? $entry['active'] : null;
                                    $hasVersions = ! empty($versions);

                                    $systemEntry = is_array($manageSystemRuntimes[$runtime] ?? null) ? $manageSystemRuntimes[$runtime] : ['present' => false, 'version' => null];
                                    $systemPresent = ! empty($systemEntry['present']);
                                    $systemVersion = is_string($systemEntry['version'] ?? null) && $systemEntry['version'] !== '' ? $systemEntry['version'] : null;

                                    $miseOp = $activeMiseRuntimeOps[$runtime] ?? null;
                                    $isMiseBusy = $miseOp !== null;
                                @endphp
                                <div
                                    @class([
                                        'rounded-2xl border px-5 py-4 transition-colors',
                                        'border-brand-sage/30 bg-brand-sage/5' => $isMiseBusy,
                                        'border-brand-ink/10 bg-white' => ! $isMiseBusy,
                                    ])
                                    wire:key="mise-runtime-row-{{ $runtime }}"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h5 class="text-sm font-semibold text-brand-ink">{{ $catalog['label'] }}</h5>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-mist">
                                                @if ($isMiseBusy)
                                                    <span class="inline-flex items-center gap-1.5 font-medium text-brand-forest">
                                                        <x-spinner variant="forest" size="sm" />
                                                        {{ $miseOp['message'] }}
                                                    </span>
                                                @elseif ($active)
                                                    <span>{{ __('mise default: ') }}<span class="font-mono text-brand-ink">{{ $active }}</span></span>
                                                @elseif ($hasVersions)
                                                    <span>{{ __('mise: no global default set.') }}</span>
                                                @else
                                                    <span>{{ __('mise: not installed.') }}</span>
                                                @endif
                                                @if ($systemPresent)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-medium text-brand-ink" title="{{ __('Detected on the system PATH (apt-installed or distribution default). Not managed by mise.') }}">
                                                        <span aria-hidden="true" class="inline-block h-1 w-1 rounded-full bg-brand-forest"></span>
                                                        {{ __('system') }}: <span class="font-mono">{{ $systemVersion ?? '—' }}</span>
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($opsReady && ! $isDeployer)
                                            @if ($isMiseBusy)
                                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-sage/30 bg-brand-sage/10 px-2.5 py-1.5 text-xs font-medium text-brand-forest">
                                                    <x-spinner variant="forest" size="sm" />
                                                    {{ $miseOp['status'] === 'queued' ? __('Queued…') : __('Running on server…') }}
                                                </span>
                                            @else
                                            @php
                                                $availableVersions = $mise_available_versions[$runtime] ?? null;
                                            @endphp
                                            @if ($availableVersions === null)
                                                <button
                                                    type="button"
                                                    wire:click="loadMiseAvailableVersions('{{ $runtime }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="loadMiseAvailableVersions('{{ $runtime }}')"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                                >
                                                    <span wire:loading.remove wire:target="loadMiseAvailableVersions('{{ $runtime }}')" class="inline-flex items-center gap-1.5">
                                                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                                                        {{ __('Load versions') }}
                                                    </span>
                                                    <span wire:loading wire:target="loadMiseAvailableVersions('{{ $runtime }}')" class="inline-flex items-center gap-1.5">
                                                        <x-spinner variant="forest" size="sm" />
                                                        {{ __('Fetching…') }}
                                                    </span>
                                                </button>
                                            @elseif ($availableVersions === [])
                                                <button
                                                    type="button"
                                                    wire:click="loadMiseAvailableVersions('{{ $runtime }}')"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100"
                                                >
                                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Retry fetch') }}
                                                </button>
                                            @else
                                                <form
                                                    x-data="{ v: '' }"
                                                    x-on:submit.prevent="if (v !== '') { $wire.miseInstallRuntime(@js($runtime), v); v = ''; }"
                                                    class="flex flex-wrap items-end gap-2"
                                                >
                                                    <label class="sr-only" for="mise-install-{{ $runtime }}">{{ __('Install and activate :runtime version', ['runtime' => $catalog['label']]) }}</label>
                                                    <select
                                                        id="mise-install-{{ $runtime }}"
                                                        x-model="v"
                                                        class="min-w-[11rem] shrink-0 rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                                                        title="{{ $catalog['hint'] }}"
                                                    >
                                                        <option value="">{{ __('Select version') }}</option>
                                                        @foreach ($availableVersions as $v)
                                                            <option value="{{ $v }}">{{ $v }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button
                                                        type="submit"
                                                        x-bind:disabled="v === ''"
                                                        wire:loading.attr="disabled"
                                                        wire:target="miseInstallRuntime"
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        <span wire:loading.remove wire:target="miseInstallRuntime" class="inline-flex items-center gap-1.5">
                                                            <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                                            {{ __('Install & activate') }}
                                                        </span>
                                                        <span wire:loading wire:target="miseInstallRuntime" class="inline-flex items-center gap-1.5">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Queuing…') }}
                                                        </span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="loadMiseAvailableVersions('{{ $runtime }}')"
                                                        class="inline-flex items-center gap-1 text-[11px] text-brand-mist hover:text-brand-ink"
                                                    >
                                                        <x-heroicon-o-arrow-path class="h-3 w-3" aria-hidden="true" />
                                                    </button>
                                                </form>
                                            @endif
                                            @endif
                                        @endif
                                    </div>

                                    @if ($hasVersions)
                                        <div @class(['mt-3 flex flex-wrap gap-2', 'opacity-60' => $isMiseBusy])>
                                            @foreach ($versions as $v)
                                                @php
                                                    $isActive = $active !== null && $active === $v;
                                                    $confirmRemove = __('Uninstall :runtime :version? The deploy user\'s mise data directory drops the install; sites already pinned to this version will fall back to the runtime default.', [
                                                        'runtime' => $catalog['label'],
                                                        'version' => $v,
                                                    ]);
                                                    $confirmDefault = __('Set :runtime :version as the deploy user\'s global default? New sites without a pinned version will use this one.', [
                                                        'runtime' => $catalog['label'],
                                                        'version' => $v,
                                                    ]);
                                                @endphp
                                                <span
                                                    @class([
                                                        'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                                        'border-brand-forest/20 bg-brand-sage/15 text-brand-forest' => $isActive,
                                                        'border-brand-ink/15 bg-white text-brand-ink' => ! $isActive,
                                                    ])
                                                >
                                                    <span class="font-mono">{{ $v }}</span>
                                                    @if ($isActive)
                                                        <span class="text-[10px] uppercase tracking-wide opacity-80">{{ __('default') }}</span>
                                                    @elseif ($opsReady && ! $isDeployer && ! $isMiseBusy)
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('miseSetRuntimeDefault', ['{{ $runtime }}', @js($v)], @js(__('Set :v as default', ['v' => $v])), @js($confirmDefault), @js(__('Set :runtime default to :v', ['runtime' => $catalog['label'], 'v' => $v])), false)"
                                                            class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink/70 hover:text-brand-ink"
                                                        >
                                                            {{ __('set default') }}
                                                        </button>
                                                    @endif
                                                    @if (! $isActive && $opsReady && ! $isDeployer && ! $isMiseBusy)
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('miseUninstallRuntime', ['{{ $runtime }}', @js($v)], @js(__('Uninstall :v', ['v' => $v])), @js($confirmRemove), @js(__('Uninstall :runtime :v', ['runtime' => $catalog['label'], 'v' => $v])), true)"
                                                            class="text-brand-ink/50 hover:text-rose-700"
                                                        >
                                                            <x-heroicon-o-x-mark class="h-3 w-3" aria-hidden="true" />
                                                        </button>
                                                    @endif
                                                </span>
                                            @endforeach
                                        </div>

                                        @if ($opsReady && ! $isDeployer && ! $isMiseBusy)
                                            <form
                                                x-data="{ uv: '' }"
                                                class="mt-3 flex flex-wrap items-end gap-2 border-t border-brand-ink/5 pt-3"
                                            >
                                                <label class="sr-only" for="mise-uninstall-{{ $runtime }}">{{ __('Uninstall :runtime version', ['runtime' => $catalog['label']]) }}</label>
                                                <select
                                                    id="mise-uninstall-{{ $runtime }}"
                                                    x-model="uv"
                                                    class="min-w-[11rem] shrink-0 rounded-lg border border-brand-ink/15 bg-white py-1.5 pl-3 pr-8 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                                                >
                                                    <option value="">{{ __('Uninstall version') }}</option>
                                                    @foreach ($versions as $v)
                                                        <option value="{{ $v }}" @disabled($active !== null && $active === $v)>{{ $v }}@if ($active !== null && $active === $v) ({{ __('default') }})@endif</option>
                                                    @endforeach
                                                </select>
                                                <button
                                                    type="button"
                                                    x-bind:disabled="uv === '' || uv === @js($active)"
                                                    x-on:click="if (uv !== '' && uv !== @js($active)) { $wire.promptMiseUninstallRuntime(@js($runtime), uv); }"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-medium text-rose-800 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Uninstall') }}
                                                </button>
                                                @if ($active !== null)
                                                    <p class="w-full text-[11px] text-brand-mist">{{ __('The global default cannot be uninstalled until you set a different default.') }}</p>
                                                @endif
                                            </form>
                                        @endif
                                    @elseif ($isMiseBusy)
                                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs text-brand-moss">
                                            <x-spinner variant="forest" size="sm" />
                                            {{ $miseOp['message'] }}
                                        </p>
                                    @else
                                        <p class="mt-3 text-xs text-brand-mist">{{ __('No versions installed yet.') }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Generic tool cards --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($genericTools as $tool)
            @php
                $toolOp = $toolActionOperation($tool);
                $toolBusy = $toolOp !== null
                    && in_array($toolOp['status'] ?? '', ['queued', 'running'], true);
            @endphp
            <div
                @class([
                    $card,
                    'flex h-full flex-col p-6 sm:p-7 transition-colors',
                    'border-brand-sage/30 bg-brand-sage/5' => $toolBusy,
                ])
                wire:key="manage-tool-card-{{ $tool['slug'] }}"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-dynamic-component :component="$tool['icon']" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ $tool['label'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $tool['description'] }}</p>
                            @if ($tool['docs_url'])
                                <a href="{{ $tool['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-ink hover:text-brand-sage">
                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Upstream docs') }}
                                </a>
                            @endif
                        </div>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 {{ $statusTone($tool['status_tone']) }}">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusBadgeDot($tool['status_tone']) }}"></span>
                        {{ $tool['status_label'] }}
                    </span>
                </div>

                <dl class="mt-5 grid gap-3 text-xs sm:grid-cols-2">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                        <dd class="mt-1 font-mono text-brand-ink">{{ $tool['version'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last probed') }}</dt>
                        <dd class="mt-1 text-brand-moss">
                            @if ($checkedAt)
                                {{ $checkedAt->diffForHumans() }}
                            @else
                                {{ __('never') }}
                            @endif
                        </dd>
                    </div>
                    @if ($tool['present'] && $tool['slug'] === 'git')
                        @php
                            $gitIdentityBusy = $activeToolActionOps['set_deploy_git_identity'] ?? null;
                            $gitDefaults = is_array($tool['identity_defaults'] ?? null) ? $tool['identity_defaults'] : [];
                        @endphp
                        <div class="sm:col-span-2">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Deploy user identity') }}</dt>
                            @if ($opsReady && ! $isDeployer)
                                <dd class="mt-2 space-y-3">
                                    @if ($gitIdentityBusy)
                                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-sage/30 bg-brand-sage/10 px-2.5 py-1.5 text-xs font-medium text-brand-forest">
                                            <x-spinner variant="forest" size="sm" />
                                            {{ $gitIdentityBusy['message'] }}
                                        </span>
                                    @endif
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="git-deploy-identity-name-{{ $tool['slug'] }}" class="sr-only">{{ __('Git user name') }}</label>
                                            <input
                                                id="git-deploy-identity-name-{{ $tool['slug'] }}"
                                                type="text"
                                                wire:model="git_deploy_identity_name"
                                                @disabled($gitIdentityBusy !== null)
                                                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:opacity-60"
                                                placeholder="{{ $gitDefaults['name'] ?? __('Name') }}"
                                            />
                                            @error('git_deploy_identity_name')
                                                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label for="git-deploy-identity-email-{{ $tool['slug'] }}" class="sr-only">{{ __('Git user email') }}</label>
                                            <input
                                                id="git-deploy-identity-email-{{ $tool['slug'] }}"
                                                type="email"
                                                wire:model="git_deploy_identity_email"
                                                @disabled($gitIdentityBusy !== null)
                                                class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:opacity-60"
                                                placeholder="{{ $gitDefaults['email'] ?? __('Email') }}"
                                            />
                                            @error('git_deploy_identity_email')
                                                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button
                                            type="button"
                                            wire:click="saveDeployGitIdentity"
                                            wire:loading.attr="disabled"
                                            wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity"
                                            @disabled($gitIdentityBusy !== null)
                                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity" class="inline-flex items-center gap-2">
                                                <x-heroicon-o-check class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Save identity') }}
                                            </span>
                                            <span wire:loading wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity" class="inline-flex items-center gap-2">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Saving…') }}
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="applyDefaultDeployGitIdentity"
                                            wire:loading.attr="disabled"
                                            wire:target="saveDeployGitIdentity,applyDefaultDeployGitIdentity"
                                            @disabled($gitIdentityBusy !== null)
                                            class="text-xs font-semibold text-brand-moss hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {{ __('Use Dply default') }}
                                        </button>
                                    </div>
                                    <p class="text-[11px] leading-relaxed text-brand-moss">
                                        {{ __('Used for git commits on this server as the deploy user. Default is :name with :email.', [
                                            'name' => $gitDefaults['name'] ?? '—',
                                            'email' => $gitDefaults['email'] ?? '—',
                                        ]) }}
                                    </p>
                                </dd>
                            @elseif ($tool['identity_name'] || $tool['identity_email'])
                                <dd class="mt-1 font-mono text-brand-ink">
                                    @if ($tool['identity_name'] && $tool['identity_email'])
                                        {{ $tool['identity_name'] }} &lt;{{ $tool['identity_email'] }}&gt;
                                    @elseif ($tool['identity_name'])
                                        {{ $tool['identity_name'] }}
                                    @else
                                        &lt;{{ $tool['identity_email'] }}&gt;
                                    @endif
                                </dd>
                            @else
                                <dd class="mt-1 text-brand-moss">{{ __('Not set on the last probe.') }}</dd>
                            @endif
                        </div>
                    @elseif ($tool['present'] && ($tool['identity_name'] || $tool['identity_email']))
                        <div class="sm:col-span-2">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Deploy user identity') }}</dt>
                            <dd class="mt-1 font-mono text-brand-ink">
                                @if ($tool['identity_name'] && $tool['identity_email'])
                                    {{ $tool['identity_name'] }} &lt;{{ $tool['identity_email'] }}&gt;
                                @elseif ($tool['identity_name'])
                                    {{ $tool['identity_name'] }}
                                @else
                                    &lt;{{ $tool['identity_email'] }}&gt;
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-auto flex flex-wrap items-center gap-3 pt-5">
                    @if ($toolBusy)
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-sage/30 bg-brand-sage/10 px-3 py-2 text-sm font-medium text-brand-forest">
                            <x-spinner variant="forest" size="sm" />
                            {{ $toolOp['message'] }}
                        </span>
                    @elseif ($tool['show_present_action'] && $tool['present_action'] && $opsReady && ! $isDeployer)
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $tool['present_action_key'] }}'], @js($tool['present_action']['label']), @js($tool['present_action']['confirm']), @js($tool['present_action']['label']), false)"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                        >
                            <x-dynamic-component :component="$tool['icon']" class="h-4 w-4 opacity-80" />
                            {{ $tool['present_action']['label'] }}
                        </button>
                    @elseif ($tool['show_action'] && $tool['action'] && $opsReady && ! $isDeployer)
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $tool['action_key'] }}'], @js($tool['action']['label']), @js($tool['action']['confirm']), @js($tool['action']['label']), false)"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                        >
                            <x-dynamic-component :component="$tool['icon']" class="h-4 w-4 opacity-80" />
                            {{ $tool['action']['label'] }}
                        </button>
                    @elseif (! $opsReady)
                        <p class="text-xs text-brand-moss">{{ __('Provisioning and SSH must be ready before installs can run.') }}</p>
                    @elseif ($tool['present'] && ! $tool['show_present_action'] && ! $tool['source_control_url'] && ! $tool['caches_url'] && ! $tool['run_url'] && ! $tool['docker_url'])
                        <p class="text-xs text-brand-moss">{{ __('Detected on the last probe.') }}</p>
                    @endif

                    @if ($tool['source_control_url'])
                        <a href="{{ $tool['source_control_url'] }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                            {{ __('Source control') }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    @endif

                    @if ($tool['caches_url'])
                        <a href="{{ $tool['caches_url'] }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                            {{ __('Open Caches') }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    @endif

                    @if ($tool['run_url'])
                        <a href="{{ $tool['run_url'] }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                            {{ __('Open Run') }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    @endif

                    @if ($tool['docker_url'])
                        @feature('workspace.docker')
                            <a href="{{ $tool['docker_url'] }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                                {{ __('Open Docker') }}
                                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                            </a>
                        @endfeature
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
