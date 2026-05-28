@php
    /**
     * Operator-installable server toolchain. Two zones:
     *
     *   1. mise card (full width) — preinstalled by provisioning, but also gets
     *      a runtimes panel that lists every Node / Python / Ruby / Go version
     *      installed under the deploy user with actions to install, uninstall,
     *      or set as global default. Reads from $server->meta['manage_mise_runtimes']
     *      (probed by ServerInventoryProbeScript's MISE_RUNTIMES block).
     *
     *   2. Tool grid — Docker, wp-cli (and any future generic tools). Each row:
     *      - reads presence + version from $server->meta['manage_tools'][$slug]
     *        (probed by ServerInventoryProbeScript's TOOLS block)
     *      - calls runAllowlistedAction('install_*') for Install / Reinstall
     *
     * Adding a new generic tool = one row in the TOOLS probe + one entry in
     * service_actions + one row in $generic_tools below.
     */
    $meta = $server->meta ?? [];
    $manageTools = is_array($meta['manage_tools'] ?? null) ? $meta['manage_tools'] : [];
    $manageMiseRuntimes = is_array($meta['manage_mise_runtimes'] ?? null) ? $meta['manage_mise_runtimes'] : [];
    $manageSystemRuntimes = is_array($meta['manage_system_runtimes'] ?? null) ? $meta['manage_system_runtimes'] : [];
    $checkedAt = $meta['inventory_checked_at'] ?? null;
    // The TOOLS / runtimes blocks are recent — if the probe ran before this code
    // shipped, the keys aren't there yet. Distinguish "probe hasn't seen this
    // yet" from "genuinely missing" so the empty state is honest.
    $toolsProbed = array_key_exists('manage_tools', $meta);
    $miseRuntimesProbed = array_key_exists('manage_mise_runtimes', $meta);
    $systemRuntimesProbed = array_key_exists('manage_system_runtimes', $meta);

    $miseTool = [
        'slug' => 'mise',
        'label' => __('mise (dev tool version manager)'),
        'description' => __('Installs Node, Python, Ruby, Go per project via .tool-versions / .mise.toml. dply installs mise from the official apt repo during provisioning — this surface is here for repair / version refresh, not first-install.'),
        'docs_url' => 'https://mise.jdx.dev',
        'icon' => 'heroicon-o-cube-transparent',
        'action_key' => 'install_mise',
        'preinstalled' => true,
    ];

    $generic_tools = [
        [
            'slug' => 'docker',
            'label' => __('Docker Engine'),
            'description' => __('Container runtime + CLI. Installs docker-ce, docker-ce-cli, and containerd from the official Docker apt repo via the get.docker.com convenience script.'),
            'docs_url' => 'https://docs.docker.com/engine/install/',
            'icon' => 'heroicon-o-square-3-stack-3d',
            'action_key' => 'install_docker',
        ],
        [
            'slug' => 'wp_cli',
            'label' => __('wp-cli (WordPress CLI)'),
            'description' => __('Command-line interface for managing WordPress sites — plugins, themes, users, search-replace. dply\'s WordPress scaffold installs this automatically on first scaffold; this surface re-installs / pulls the latest phar on demand.'),
            'docs_url' => 'https://wp-cli.org',
            'icon' => 'heroicon-o-code-bracket',
            'action_key' => 'install_wp_cli',
        ],
    ];

    $miseState = is_array($manageTools['mise'] ?? null) ? $manageTools['mise'] : ['present' => false, 'version' => null];
    $misePresent = ! empty($miseState['present']);
    $miseVersion = is_string($miseState['version'] ?? null) && $miseState['version'] !== '' ? $miseState['version'] : null;
    $miseAction = $serviceActions[$miseTool['action_key']] ?? null;

    $runtimeCatalog = [
        'node' => ['label' => 'Node.js', 'placeholder' => '20.16.0', 'hint' => __('Numeric major or full semver (e.g. 20, 20.16.0, lts).')],
        'python' => ['label' => 'Python', 'placeholder' => '3.12.5', 'hint' => __('Major.minor or full version (e.g. 3.12, 3.12.5).')],
        'ruby' => ['label' => 'Ruby', 'placeholder' => '3.3.4', 'hint' => __('Major.minor.patch (e.g. 3.3.4). Pre-builds take 30–60s on small droplets.')],
        'go' => ['label' => 'Go', 'placeholder' => '1.23.0', 'hint' => __('Major.minor or full version (e.g. 1.23, 1.23.0).')],
    ];
@endphp

<section class="space-y-6" aria-labelledby="manage-tools-title">
    <h2 id="manage-tools-title" class="sr-only">{{ __('Tools') }}</h2>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <x-explainer>
                <p>{{ __('Server toolchain — presence + version pills read from the last inventory probe, with operator-facing install / repair actions. The mise card also lists installed runtime versions (Node, Python, Ruby, Go) and exposes per-version manage actions.') }}</p>
            </x-explainer>
        </div>
        @if ($opsReady && ! $isDeployer)
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
        @endif
    </div>

    @if ($checkedAt && (! $toolsProbed || ! $miseRuntimesProbed || ! $systemRuntimesProbed))
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                        <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('In progress') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Toolchain probe stale') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('The toolchain probe runs as part of the inventory refresh. The last probe predates this section — click Refresh probe to populate the pills below.') }}</p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- mise card — full width so the runtimes panel has room to breathe. --}}
    <div class="{{ $card }} flex flex-col p-6 sm:p-7">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                    <x-dynamic-component :component="$miseTool['icon']" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ $miseTool['label'] }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $miseTool['description'] }}</p>
                    <a href="{{ $miseTool['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-ink hover:text-brand-sage">
                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                        {{ __('Upstream docs') }}
                    </a>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if ($misePresent)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[11px] font-medium text-brand-forest" title="{{ __('Laid down during server provisioning.') }}">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ __('Preinstalled') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-ink/10 px-2 py-0.5 text-[11px] font-medium text-brand-moss">
                        <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-mist"></span>
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
                        {{ \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans() }}
                    @else
                        {{ __('never') }}
                    @endif
                </dd>
            </div>
            @if ($miseAction && $opsReady && ! $isDeployer)
                <div class="sm:justify-self-end">
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $miseTool['action_key'] }}'], @js($miseAction['label']), @js($miseAction['confirm']), @js($miseAction['label']), false)"
                        class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                    >
                        <x-dynamic-component :component="$miseTool['icon']" class="h-4 w-4 opacity-80" />
                        {{ $miseAction['label'] }}
                    </button>
                </div>
            @endif
        </dl>

        {{-- Runtimes panel — per-runtime list of installed versions, active-default pill, and actions. --}}
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
                            @endphp
                            <div class="rounded-2xl border border-brand-ink/10 bg-white px-5 py-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h5 class="text-sm font-semibold text-brand-ink">{{ $catalog['label'] }}</h5>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-mist">
                                            @if ($active)
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
                                        @php
                                            $availableVersions = $mise_available_versions[$runtime] ?? null;
                                            $isLoadingVersions = $mise_loading_versions_for === $runtime;
                                        @endphp
                                        @if ($availableVersions === null)
                                            <button
                                                type="button"
                                                wire:click="loadMiseAvailableVersions('{{ $runtime }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="loadMiseAvailableVersions('{{ $runtime }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                                title="{{ __('Fetch available versions via `mise ls-remote :tool` over SSH.', ['tool' => $runtime]) }}"
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
                                                <label class="sr-only" for="mise-install-{{ $runtime }}">{{ __('Install :runtime version', ['runtime' => $catalog['label']]) }}</label>
                                                <select
                                                    id="mise-install-{{ $runtime }}"
                                                    x-model="v"
                                                    class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                                                    title="{{ $catalog['hint'] }}"
                                                >
                                                    <option value="">{{ __('Select version …') }}</option>
                                                    @foreach ($availableVersions as $v)
                                                        <option value="{{ $v }}">{{ $v }}</option>
                                                    @endforeach
                                                </select>
                                                <button
                                                    type="submit"
                                                    x-bind:disabled="v === ''"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                                    {{ __('Install version') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="loadMiseAvailableVersions('{{ $runtime }}')"
                                                    class="inline-flex items-center gap-1 text-[11px] text-brand-mist hover:text-brand-ink"
                                                    title="{{ __('Re-fetch the latest version list.') }}"
                                                >
                                                    <x-heroicon-o-arrow-path class="h-3 w-3" aria-hidden="true" />
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                </div>

                                @if ($hasVersions)
                                    <div class="mt-3 flex flex-wrap gap-2">
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
                                                <span aria-hidden="true" @class([
                                                    'inline-block h-1.5 w-1.5 rounded-full',
                                                    'bg-brand-forest' => $isActive,
                                                    'bg-brand-mist' => ! $isActive,
                                                ])></span>
                                                <span class="font-mono">{{ $v }}</span>
                                                @if ($isActive)
                                                    <span class="text-[10px] uppercase tracking-wide opacity-80">{{ __('default') }}</span>
                                                @elseif ($opsReady && ! $isDeployer)
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('miseSetRuntimeDefault', ['{{ $runtime }}', @js($v)], @js(__('Set :v as default', ['v' => $v])), @js($confirmDefault), @js(__('Set :runtime default to :v', ['runtime' => $catalog['label'], 'v' => $v])), false)"
                                                        class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink/70 hover:text-brand-ink"
                                                        title="{{ __('Set as default') }}"
                                                    >
                                                        {{ __('set default') }}
                                                    </button>
                                                @endif
                                                @if (! $isActive && $opsReady && ! $isDeployer)
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('miseUninstallRuntime', ['{{ $runtime }}', @js($v)], @js(__('Uninstall :v', ['v' => $v])), @js($confirmRemove), @js(__('Uninstall :runtime :v', ['runtime' => $catalog['label'], 'v' => $v])), true)"
                                                        class="text-brand-ink/50 hover:text-rose-700"
                                                        title="{{ __('Uninstall version') }}"
                                                    >
                                                        <x-heroicon-o-x-mark class="h-3 w-3" aria-hidden="true" />
                                                    </button>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
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

    {{-- Generic tools grid (Docker, wp-cli, …). --}}
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($generic_tools as $tool)
            @php
                $state = is_array($manageTools[$tool['slug']] ?? null) ? $manageTools[$tool['slug']] : ['present' => false, 'version' => null];
                $present = ! empty($state['present']);
                $version = is_string($state['version'] ?? null) && $state['version'] !== '' ? $state['version'] : null;
                $action = $serviceActions[$tool['action_key']] ?? null;
            @endphp
            <div class="{{ $card }} flex h-full flex-col p-6 sm:p-7">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-dynamic-component :component="$tool['icon']" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ $tool['label'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $tool['description'] }}</p>
                            <a href="{{ $tool['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-ink hover:text-brand-sage">
                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                {{ __('Upstream docs') }}
                            </a>
                        </div>
                    </div>
                    @if ($present)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[11px] font-medium text-brand-forest">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ __('Installed') }}
                        </span>
                    @else
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-ink/10 px-2 py-0.5 text-[11px] font-medium text-brand-moss">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-mist"></span>
                            {{ __('Not installed') }}
                        </span>
                    @endif
                </div>

                <dl class="mt-5 grid gap-3 text-xs sm:grid-cols-2">
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                        <dd class="mt-1 font-mono text-brand-ink">{{ $version ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last probed') }}</dt>
                        <dd class="mt-1 text-brand-moss">
                            @if ($checkedAt)
                                {{ \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans() }}
                            @else
                                {{ __('never') }}
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($action && $opsReady && ! $isDeployer)
                    <div class="mt-auto pt-5">
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $tool['action_key'] }}'], @js($action['label']), @js($action['confirm']), @js($action['label']), false)"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                        >
                            <x-dynamic-component :component="$tool['icon']" class="h-4 w-4 opacity-80" />
                            {{ $action['label'] }}
                        </button>
                    </div>
                @elseif (! $opsReady)
                    <p class="mt-auto pt-5 text-xs text-brand-moss">{{ __('Provisioning and SSH must be ready before installs can run.') }}</p>
                @endif
            </div>
        @endforeach
    </div>
</section>
