@php
    $card = 'dply-card overflow-hidden';
    $btnPrimary = 'inline-flex w-auto shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-ink/90 disabled:cursor-not-allowed disabled:opacity-60';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2 text-sm font-medium text-brand-ink transition hover:border-brand-ink/20 hover:bg-brand-sand/30 disabled:cursor-not-allowed disabled:opacity-60';
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

{{-- Single stable root: the layout below plus the trailing modal include are two
     top-level siblings. A Livewire component must morph against ONE consistent
     root element — without this wrapper the morph / wire:navigate cycle can
     leave an orphaned, snapshot-less root ("Snapshot missing on Livewire
     component"). `display:contents` keeps the wrapper layout-neutral. --}}
<div class="contents">
<x-server-workspace-layout
    :server="$server"
    active="php"
    :title="__('PHP')"
    :description="__('Review server-level PHP inventory, defaults, and runtime configuration from one workspace.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if (! $opsReady && ! $sshUnavailable)
        @include('livewire.servers.partials.workspace-ops-not-ready', ['server' => $server])
    @endif

    <x-explainer>
        <p>{{ __('This workspace inventories the PHP versions installed on this server, lets you set the default CLI version, and review FPM + ini configuration. Inventory is read live from the box via SSH on each render; default-version changes happen through update-alternatives.') }}</p>
        <p>{{ __('Adding/removing PHP versions runs apt against the upstream Sury/Ondrej PPA. Existing sites pin to a specific version in their FPM pool, so changing the server default doesn\'t move sites — that\'s a per-site setting on the Sites workspace.') }}</p>
    </x-explainer>

    {{-- Console banner — install/uninstall/patch/refresh-inventory, config load/save,
         and set-default actions stream into the shared ConsoleAction partial. --}}
    @include('livewire.partials.console-action-banner-static', [
        'run' => $phpRun,
        'kindLabels' => (array) config('console_actions.kinds', []),
    ])

    {{-- PHP runtime: hero card with eyebrow + title + summary stat tiles. --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                        <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('PHP runtime') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                            {{ __('Server-owned PHP inventory, CLI default, and new-site default.') }}
                        </p>
                    </div>
                @can('update', $server)
                    @if ($opsReady)
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button
                                type="button"
                                wire:click="refreshPhpInventory"
                                wire:loading.attr="disabled"
                                wire:target="refreshPhpInventory"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.remove wire:target="refreshPhpInventory" aria-hidden="true" />
                                <span wire:loading wire:target="refreshPhpInventory" class="inline-flex h-4 w-4 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span wire:loading.remove wire:target="refreshPhpInventory">{{ __('Refresh inventory') }}</span>
                                <span wire:loading wire:target="refreshPhpInventory">{{ __('Refreshing…') }}</span>
                            </button>
                        </div>
                    @endif
                @endcan
        </div>
        <dl class="grid grid-cols-1 gap-2 p-6 sm:grid-cols-3 sm:p-7">
            <div @class([
                'rounded-2xl border px-4 py-3 shadow-sm',
                'border-brand-sage/30 bg-brand-sage/8' => (int) ($phpSummary['installed_count'] ?? 0) > 0,
                'border-brand-ink/10 bg-white' => (int) ($phpSummary['installed_count'] ?? 0) === 0,
            ])>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Installed versions') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ (int) $phpSummary['installed_count'] }}</span>
                    <span class="text-[11px] text-brand-moss">{{ trans_choice('version|versions', (int) $phpSummary['installed_count']) }}</span>
                </dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Detected on host') }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('CLI default') }}</dt>
                <dd class="mt-1 truncate font-mono text-sm font-semibold text-brand-ink">{{ $phpSummary['cli_default'] ? 'PHP '.$phpSummary['cli_default'] : __('not set') }}</dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('update-alternatives') }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('New-site default') }}</dt>
                <dd class="mt-1 truncate font-mono text-sm font-semibold text-brand-ink">{{ $phpSummary['new_site_default'] ? 'PHP '.$phpSummary['new_site_default'] : __('not set') }}</dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Applied to new sites') }}</p>
            </div>
        </dl>

        @if ($sshUnavailable || $phpInventoryRefreshRunning || $phpInventoryRefreshFailed || $phpInventoryStale || $phpEnvironmentUnsupported || $phpInventoryNeverRun || (! $opsReady && ! $sshUnavailable))
            <div class="space-y-3 border-t border-brand-ink/10 bg-brand-cream/20 px-6 py-5 sm:px-7">
                    @if ($sshUnavailable)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                            <p class="min-w-0 leading-6">
                                <span class="font-semibold">{{ __('SSH unavailable.') }}</span>
                                {{ __('Add or restore this server\'s SSH access before Dply can inspect or manage PHP.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryRefreshRunning)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-sm text-sky-900">
                            <p class="min-w-0 leading-6">
                                <span class="font-semibold">{{ __('PHP inventory refresh running') }}.</span>
                                {{ __('Dply is collecting the latest installed versions and CLI default from the server.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryRefreshFailed)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50/70 px-4 py-3 text-sm text-rose-900">
                            <p class="min-w-0 leading-6">
                                <span class="font-semibold">{{ __('PHP inventory refresh failed') }}.</span>
                                {{ $phpInventoryRefreshError ?: __('The last PHP inspection attempt did not complete successfully.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryStale)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                            <p class="min-w-0 leading-6">
                                <span class="font-semibold">{{ __('PHP inventory may be stale') }}.</span>
                                {{ $phpInventoryRefreshError ?: __('Remote PHP state changed, but Dply could not save the refreshed snapshot.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpEnvironmentUnsupported)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
                            <p class="min-w-0 leading-6">
                                <span class="font-semibold">{{ __('Unsupported environment') }}.</span>
                                {{ __('This server does not currently report a PHP environment that the management workspace can support.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryNeverRun)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/25 px-4 py-3 text-sm text-brand-ink">
                            <p class="min-w-0 leading-6">
                                <span class="font-semibold">{{ __('No PHP inventory yet') }}.</span>
                                {{ __('PHP inventory will appear here after the first refresh runs.') }}
                            </p>
                        </div>
                    @endif

                    @if (! $opsReady && ! $sshUnavailable)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white/70 px-4 py-3 text-sm text-brand-moss">
                            <p class="min-w-0 leading-6">
                                {{ __('Once provisioning finishes, this page will show installed PHP versions, defaults, and shared configuration entry points.') }}
                            </p>
                        </div>
                    @endif
            </div>
        @endif
    </section>

    @if ($opsReady && ! $sshUnavailable && ! $phpInventoryNeverRun)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Versions on this server') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Install, patch, set defaults, or edit ini/FPM configuration — applied on the server when you click.') }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ count($phpVersionRows) }}</span>
            </div>

            <ul class="divide-y divide-brand-ink/10">
                    @foreach ($phpVersionRows as $row)
                        @php
                            $isInstalled = $row['is_installed'] ?? false;
                            $isCliDefault = ($phpSummary['cli_default'] ?? null) === $row['id'];
                            $isNewSiteDefault = ($phpSummary['new_site_default'] ?? null) === $row['id'];
                            $siteCount = (int) ($row['site_count'] ?? 0);
                            $migrationTarget = $row['migration_target_version'] ?? null;
                            $uninstallFallback = $row['uninstall_fallback_version'] ?? null;
                            $disableUninstall = ! $isInstalled || ($siteCount > 0 && $migrationTarget === null);
                            $canMigrateSites = $isInstalled && $siteCount > 0 && is_string($migrationTarget) && $migrationTarget !== '';
                            $canMigrateAndUninstall = $canMigrateSites;
                            $actionTarget = fn (string $action) => "runPhpPackageAction('{$action}', '{$row['id']}')";
                            $configTarget = fn (string $target) => "openPhpConfigEditor('{$row['id']}', '{$target}')";
                            // Combined target list for row-level loading state. Lets the whole row
                            // dim + show a spinner whenever any action targeting this version is
                            // running — the dropdown closes on click, so per-item spinners alone
                            // would be invisible.
                            $rowTargets = implode(',', [
                                $actionTarget('install'),
                                $actionTarget('patch'),
                                $actionTarget('migrate_sites'),
                                $actionTarget('set_cli_default'),
                                $actionTarget('set_new_site_default'),
                                $actionTarget('uninstall'),
                                'runPhpMigrateAndUninstall',
                                $configTarget('cli_ini'),
                                $configTarget('fpm_ini'),
                                $configTarget('pool_config'),
                            ]);
                            $rowHasEditorError = $phpConfigEditorOpen
                                && $phpConfigEditorVersion === $row['id']
                                && ! empty($phpConfigEditorErrorLines);
                        @endphp

                        <x-workspace-table-row
                            wire:key="php-{{ $row['id'] }}"
                            :wire-target="$rowTargets"
                            class="px-6 py-4 sm:px-8"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                <span class="mt-0.5 hidden h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/30 text-brand-forest sm:inline-flex">
                                    <x-heroicon-o-command-line class="h-4 w-4" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $row['label'] }}</p>
                                        @if ($isInstalled)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ __('Installed') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ __('Available') }}
                                            </span>
                                        @endif
                                        @if ($isCliDefault)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ __('CLI default') }}
                                            </span>
                                        @endif
                                        @if ($isNewSiteDefault)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                <x-heroicon-m-sparkles class="h-3 w-3" />
                                                {{ __('Default for new sites') }}
                                            </span>
                                        @endif
                                        @if (! ($row['is_supported'] ?? true))
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                                                {{ __('Unsupported') }}
                                            </span>
                                        @endif
                                        @if ($rowHasEditorError)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-rose-200" title="{{ __('Validation failed on line :lines — fix or discard in the editor.', ['lines' => implode(', ', $phpConfigEditorErrorLines)]) }}">
                                                <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                                {{ __('Config error') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-[11px] text-brand-mist">
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-m-globe-alt class="h-3 w-3" />
                                            {{ trans_choice('Used by :count site|Used by :count sites', $siteCount, ['count' => $siteCount]) }}
                                        </span>
                                        @if ($disableUninstall && $isInstalled)
                                            <span class="text-brand-mist/60">·</span>
                                            <span class="italic">
                                                @if ($siteCount > 0 && $migrationTarget === null)
                                                    {{ __('uninstall blocked: no other PHP version installed') }}
                                                @elseif ($siteCount > 0)
                                                    {{ __('uninstall blocked: sites in use') }}
                                                @endif
                                            </span>
                                        @elseif ($isInstalled && is_string($uninstallFallback) && $uninstallFallback !== '' && ($isCliDefault || $isNewSiteDefault))
                                            <span class="text-brand-mist/60">·</span>
                                            <span class="italic">
                                                {{ __('Uninstall moves defaults to PHP :version', ['version' => $uninstallFallback]) }}
                                            </span>
                                        @endif
                                    </p>
                                </div>

                                @can('update', $server)
                                    <div class="flex flex-wrap items-center gap-2 self-start sm:self-center">
                                        @if (! $isInstalled)
                                            <button
                                                type="button"
                                                wire:click="runPhpPackageAction('install', '{{ $row['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $actionTarget('install') }}"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <x-heroicon-o-arrow-down-tray class="h-4 w-4" wire:loading.remove wire:target="{{ $actionTarget('install') }}" />
                                                <span wire:loading wire:target="{{ $actionTarget('install') }}" class="inline-flex h-4 w-4 items-center justify-center">
                                                    <x-spinner variant="cream" size="sm" />
                                                </span>
                                                <span wire:loading.remove wire:target="{{ $actionTarget('install') }}">{{ __('Install') }}</span>
                                                <span wire:loading wire:target="{{ $actionTarget('install') }}">{{ __('Installing…') }}</span>
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="runPhpPackageAction('patch', '{{ $row['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $actionTarget('patch') }}"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="{{ $actionTarget('patch') }}" />
                                                <span wire:loading wire:target="{{ $actionTarget('patch') }}" class="inline-flex h-4 w-4 items-center justify-center">
                                                    <x-spinner variant="forest" size="sm" />
                                                </span>
                                                <span wire:loading.remove wire:target="{{ $actionTarget('patch') }}">{{ __('Patch') }}</span>
                                                <span wire:loading wire:target="{{ $actionTarget('patch') }}">{{ __('Patching…') }}</span>
                                            </button>

                                            <x-dropdown align="right" width="w-56" contentClasses="py-1.5">
                                                <x-slot name="trigger">
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                                        aria-label="{{ __('Version actions') }}"
                                                        aria-haspopup="true"
                                                    >
                                                        {{ __('Actions') }}
                                                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-brand-ink/70" />
                                                    </button>
                                                </x-slot>
                                                <x-slot name="content">
                                                    <button
                                                        type="button"
                                                        wire:click="runPhpPackageAction('set_cli_default', '{{ $row['id'] }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="{{ $actionTarget('set_cli_default') }}"
                                                        @disabled($isCliDefault)
                                                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        <x-heroicon-o-command-line class="h-4 w-4 shrink-0 text-brand-moss" />
                                                        <span wire:loading.remove wire:target="{{ $actionTarget('set_cli_default') }}">{{ __('Set CLI default') }}</span>
                                                        <span wire:loading wire:target="{{ $actionTarget('set_cli_default') }}">{{ __('Setting CLI default…') }}</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="runPhpPackageAction('set_new_site_default', '{{ $row['id'] }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="{{ $actionTarget('set_new_site_default') }}"
                                                        @disabled($isNewSiteDefault)
                                                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-brand-moss" />
                                                        <span wire:loading.remove wire:target="{{ $actionTarget('set_new_site_default') }}">{{ __('Set new-site default') }}</span>
                                                        <span wire:loading wire:target="{{ $actionTarget('set_new_site_default') }}">{{ __('Setting new-site default…') }}</span>
                                                    </button>
                                                    @if ($canMigrateSites)
                                                        <button
                                                            type="button"
                                                            wire:click="runPhpPackageAction('migrate_sites', '{{ $row['id'] }}')"
                                                            wire:loading.attr="disabled"
                                                            wire:target="{{ $actionTarget('migrate_sites') }}"
                                                            class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                        >
                                                            <x-heroicon-o-arrow-up-circle class="h-4 w-4 shrink-0 text-brand-moss" />
                                                            <span wire:loading.remove wire:target="{{ $actionTarget('migrate_sites') }}">{{ trans_choice('Upgrade :count site to PHP :target|Upgrade :count sites to PHP :target', $siteCount, ['count' => $siteCount, 'target' => $migrationTarget]) }}</span>
                                                            <span wire:loading wire:target="{{ $actionTarget('migrate_sites') }}">{{ __('Upgrading sites…') }}</span>
                                                        </button>
                                                    @endif
                                                    <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                                    @if ($canMigrateAndUninstall)
                                                        <button
                                                            type="button"
                                                            wire:click="openMigrateAndUninstallPhpModal('{{ $row['id'] }}', {{ $siteCount }}, '{{ $migrationTarget }}')"
                                                            wire:loading.attr="disabled"
                                                            wire:target="runPhpMigrateAndUninstall,openMigrateAndUninstallPhpModal"
                                                            class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50"
                                                        >
                                                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" />
                                                            <span wire:loading.remove wire:target="runPhpMigrateAndUninstall">{{ trans_choice('Migrate :count site and uninstall|Migrate :count sites and uninstall', $siteCount, ['count' => $siteCount]) }}</span>
                                                            <span wire:loading wire:target="runPhpMigrateAndUninstall">{{ __('Migrating sites and uninstalling…') }}</span>
                                                        </button>
                                                    @else
                                                        <button
                                                            type="button"
                                                            wire:click="runPhpPackageAction('uninstall', '{{ $row['id'] }}')"
                                                            wire:loading.attr="disabled"
                                                            wire:target="{{ $actionTarget('uninstall') }}"
                                                            @disabled($disableUninstall)
                                                            class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" />
                                                            <span wire:loading.remove wire:target="{{ $actionTarget('uninstall') }}">{{ __('Uninstall') }}</span>
                                                            <span wire:loading wire:target="{{ $actionTarget('uninstall') }}">{{ __('Uninstalling…') }}</span>
                                                        </button>
                                                    @endif
                                                </x-slot>
                                            </x-dropdown>

                                            <x-dropdown align="right" width="w-48" contentClasses="py-1.5">
                                                <x-slot name="trigger">
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                                        aria-label="{{ __('Edit config') }}"
                                                        aria-haspopup="true"
                                                    >
                                                        <x-heroicon-o-cog-6-tooth class="h-4 w-4" />
                                                        {{ __('Config') }}
                                                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5 text-brand-ink/70" />
                                                    </button>
                                                </x-slot>
                                                <x-slot name="content">
                                                    <button
                                                        type="button"
                                                        wire:click="openPhpConfigEditor('{{ $row['id'] }}', 'cli_ini')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="{{ $configTarget('cli_ini') }}"
                                                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                    >
                                                        <x-heroicon-o-command-line class="h-4 w-4 shrink-0 text-brand-moss" />
                                                        <span wire:loading.remove wire:target="{{ $configTarget('cli_ini') }}">{{ __('CLI ini') }}</span>
                                                        <span wire:loading wire:target="{{ $configTarget('cli_ini') }}">{{ __('Opening CLI ini…') }}</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="openPhpConfigEditor('{{ $row['id'] }}', 'fpm_ini')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="{{ $configTarget('fpm_ini') }}"
                                                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                    >
                                                        <x-heroicon-o-bolt class="h-4 w-4 shrink-0 text-brand-moss" />
                                                        <span wire:loading.remove wire:target="{{ $configTarget('fpm_ini') }}">{{ __('FPM ini') }}</span>
                                                        <span wire:loading wire:target="{{ $configTarget('fpm_ini') }}">{{ __('Opening FPM ini…') }}</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="openPhpConfigEditor('{{ $row['id'] }}', 'pool_config')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="{{ $configTarget('pool_config') }}"
                                                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                    >
                                                        <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 text-brand-moss" />
                                                        <span wire:loading.remove wire:target="{{ $configTarget('pool_config') }}">{{ __('Pool config') }}</span>
                                                        <span wire:loading wire:target="{{ $configTarget('pool_config') }}">{{ __('Opening pool config…') }}</span>
                                                    </button>
                                                </x-slot>
                                            </x-dropdown>
                                        @endif
                                    </div>
                                @endcan
                            </div>
                        </x-workspace-table-row>
                    @endforeach
                </ul>
        </section>
    @endif

    <x-slot name="modals">
        @if ($phpConfigEditorOpen)
            @php
                $editorHasErrors = ! empty($phpConfigEditorErrorLines);
                $diffOpen = $phpConfigDiffText !== null;
            @endphp
            <div
                class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                role="dialog"
                aria-modal="true"
                aria-labelledby="php-config-editor-title"
            >
                <div class="fixed inset-0 bg-brand-ink/30" @if (! $editorHasErrors) wire:click="closePhpConfigEditor" @endif></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div
                        class="my-auto w-full max-w-7xl dply-modal-panel overflow-hidden shadow-xl"
                        @click.stop
                        x-data="{ sidebarCollapsed: (localStorage.getItem('php-config-sidebar-collapsed') === '1') }"
                    >
                        <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-7">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 items-start gap-3">
                                    <x-icon-badge>
                                        <x-heroicon-o-cog-6-tooth class="h-5 w-5" aria-hidden="true" />
                                    </x-icon-badge>
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('PHP') }} {{ $phpConfigEditorVersion }}</p>
                                        <h2 id="php-config-editor-title" class="mt-1 text-lg font-semibold text-brand-ink">
                                            {{ __('Editing :target', ['target' => $phpConfigEditorTargetLabel]) }}
                                        </h2>
                                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                                            {{ __('Edit the config, then save to validate it before Dply replaces the live file.') }}
                                        </p>
                                        @if ($phpConfigEditorPath)
                                            <p class="mt-2 break-all font-mono text-xs text-brand-moss">{{ $phpConfigEditorPath }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        @click="sidebarCollapsed = !sidebarCollapsed; localStorage.setItem('php-config-sidebar-collapsed', sidebarCollapsed ? '1' : '0')"
                                        class="{{ $btnSecondary }}"
                                        :title="sidebarCollapsed ? @js(__('Show revision history')) : @js(__('Hide revision history'))"
                                    >
                                        <x-heroicon-o-clock class="h-4 w-4" />
                                        <span x-show="!sidebarCollapsed">{{ __('Hide history') }}</span>
                                        <span x-show="sidebarCollapsed" x-cloak>{{ __('History') }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="closePhpConfigEditor"
                                        wire:loading.attr="disabled"
                                        @disabled($editorHasErrors)
                                        title="{{ $editorHasErrors ? __('Fix the validation errors first, or click "Discard changes" below.') : '' }}"
                                        class="{{ $btnSecondary }}"
                                    >
                                        {{ __('Close') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="flex">
                            {{-- Revision sidebar --}}
                            <aside
                                x-show="!sidebarCollapsed"
                                x-cloak
                                class="w-80 shrink-0 border-r border-brand-ink/10 bg-brand-sand/10 max-h-[75vh] overflow-y-auto"
                            >
                                @if ($phpConfigEditorDriftDetected)
                                    <div class="border-b border-amber-200 bg-amber-50/70 px-4 py-3 text-xs text-amber-900">
                                        <p class="leading-relaxed">
                                            <span class="font-semibold">{{ __('Live file has drifted.') }}</span>
                                            {{ __('The file on disk differs from the latest saved revision — it may have been edited outside Dply.') }}
                                        </p>
                                        <button
                                            type="button"
                                            wire:click="captureLiveAsRevision"
                                            wire:loading.attr="disabled"
                                            wire:target="captureLiveAsRevision"
                                            class="mt-2 inline-flex items-center gap-1 rounded-md border border-amber-300 bg-white px-2 py-1 text-[11px] font-semibold text-amber-900 hover:bg-amber-100"
                                        >
                                            <x-heroicon-o-camera class="h-3 w-3" />
                                            <span wire:loading.remove wire:target="captureLiveAsRevision">{{ __('Capture as revision') }}</span>
                                            <span wire:loading wire:target="captureLiveAsRevision">{{ __('Capturing…') }}</span>
                                        </button>
                                    </div>
                                @endif

                                <div class="flex items-center justify-between gap-2 border-b border-brand-ink/10 px-4 py-2 text-[11px] text-brand-moss">
                                    <span class="font-semibold uppercase tracking-wide">{{ __('Revisions') }}</span>
                                    @if ($phpConfigRevisions->isNotEmpty())
                                        <button
                                            type="button"
                                            wire:click="toggleCompareMode"
                                            class="text-[11px] underline {{ $phpConfigEditorCompareMode ? 'text-brand-ink' : '' }}"
                                        >
                                            {{ $phpConfigEditorCompareMode ? __('Cancel compare') : __('Compare two') }}
                                        </button>
                                    @endif
                                </div>

                                <ul class="divide-y divide-brand-ink/8">
                                    @forelse ($phpConfigRevisions as $rev)
                                        @php
                                            $isCurrent = $phpConfigEditorCurrentRevisionId === $rev->id;
                                            $compareA = $phpConfigEditorCompareA === $rev->id;
                                            $compareB = $phpConfigEditorCompareB === $rev->id;
                                        @endphp
                                        <li class="px-4 py-3 {{ $isCurrent ? 'bg-emerald-50/40' : '' }}" wire:key="rev-{{ $rev->id }}">
                                            <div class="flex items-center gap-2">
                                                <p class="text-xs font-medium text-brand-ink">
                                                    {{ optional($rev->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                                </p>
                                                @if ($isCurrent)
                                                    <span class="rounded-full bg-emerald-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('Current') }}</span>
                                                @endif
                                                @if ($compareA)
                                                    <span class="rounded-full bg-sky-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">A</span>
                                                @elseif ($compareB)
                                                    <span class="rounded-full bg-sky-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">B</span>
                                                @endif
                                            </div>
                                            @if ($rev->user)
                                                <p class="text-[11px] text-brand-moss">{{ $rev->user->name }}</p>
                                            @endif
                                            @if ($rev->summary)
                                                <p class="mt-1 text-[11px] italic text-brand-ink/75">{{ $rev->summary }}</p>
                                            @endif
                                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
                                                @if ($phpConfigEditorCompareMode)
                                                    <button
                                                        type="button"
                                                        wire:click="selectForCompare('{{ $rev->id }}')"
                                                        class="font-medium text-brand-ink hover:underline"
                                                    >
                                                        {{ $compareA ? __('Picked as A') : ($compareB ? __('Picked as B') : __('Select')) }}
                                                    </button>
                                                @else
                                                    @can('update', $server)
                                                        <button
                                                            type="button"
                                                            wire:click="loadRevision('{{ $rev->id }}')"
                                                            class="font-medium text-brand-ink hover:underline"
                                                        >
                                                            {{ __('Load') }}
                                                        </button>
                                                    @endcan
                                                    <button
                                                        type="button"
                                                        wire:click="showRevisionDiff('{{ $rev->id }}')"
                                                        class="font-medium text-brand-ink hover:underline"
                                                    >
                                                        {{ __('Diff') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </li>
                                    @empty
                                        <li class="px-4 py-6 text-center text-xs text-brand-moss">
                                            {{ __('No revisions yet. The next save will create one.') }}
                                        </li>
                                    @endforelse
                                </ul>

                                @if ($phpConfigRevisions->count() >= $phpConfigEditorRevisionsLimit)
                                    <div class="border-t border-brand-ink/10 px-4 py-3 text-center">
                                        <button
                                            type="button"
                                            wire:click="showOlderRevisions"
                                            class="text-xs font-medium text-brand-moss underline hover:text-brand-ink"
                                        >
                                            {{ __('Show older') }}
                                        </button>
                                    </div>
                                @endif
                            </aside>

                            <div class="min-w-0 flex-1 space-y-4 p-6 sm:p-8">
                            @if ($diffOpen)
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-medium text-brand-ink">{{ $phpConfigDiffHeader }}</p>
                                    <button
                                        type="button"
                                        wire:click="closeRevisionDiff"
                                        class="{{ $btnSecondary }}"
                                    >
                                        {{ __('Back to editor') }}
                                    </button>
                                </div>
                                <pre class="max-h-[65vh] overflow-auto rounded-2xl bg-brand-ink p-4 font-mono text-xs leading-5 text-emerald-200">{{ $phpConfigDiffText !== '' ? $phpConfigDiffText : __('(no differences)') }}</pre>
                            @else
                            @if ($phpConfigEditorReloadGuidance)
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
                                    {{ $phpConfigEditorReloadGuidance }}
                                </div>
                            @endif

                            <div class="space-y-2">
                                <label for="php-config-editor" class="text-sm font-medium text-brand-ink">{{ __('Config content') }}</label>
                                <textarea
                                    id="php-config-editor"
                                    wire:model.defer="phpConfigEditorContent"
                                    rows="18"
                                    class="w-full rounded-2xl border {{ ! empty($phpConfigEditorErrorLines) ? 'border-rose-300 focus:border-rose-400' : 'border-brand-ink/10 focus:border-brand-ink/20' }} bg-white px-4 py-3 font-mono text-sm text-brand-ink shadow-sm focus:outline-none focus:ring-0"
                                ></textarea>
                            </div>

                            @if (! empty($phpConfigEditorErrorLines))
                                @php
                                    $editorLines = preg_split("/\r?\n/", $phpConfigEditorContent ?? '') ?: [];
                                    $totalLines = count($editorLines);
                                    $contextRadius = 3;
                                    $windowLines = collect($phpConfigEditorErrorLines)
                                        ->flatMap(fn ($n) => range(max(1, $n - $contextRadius), min(max($totalLines, $n), $n + $contextRadius)))
                                        ->unique()
                                        ->sort()
                                        ->values()
                                        ->all();
                                    $errorSet = array_flip($phpConfigEditorErrorLines);
                                @endphp
                                <div class="overflow-hidden rounded-2xl border border-rose-200 bg-white">
                                    <div class="flex items-center gap-2 border-b border-rose-200 bg-rose-50 px-4 py-2.5">
                                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-rose-700" />
                                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-900">
                                            {{ trans_choice('Problem on line :lines|Problems on lines :lines', count($phpConfigEditorErrorLines), ['lines' => implode(', ', $phpConfigEditorErrorLines)]) }}
                                        </p>
                                    </div>
                                    <div class="max-h-80 overflow-auto font-mono text-xs leading-5">
                                        @php $previousLine = null; @endphp
                                        @foreach ($windowLines as $lineNum)
                                            @if ($previousLine !== null && $lineNum > $previousLine + 1)
                                                <div class="select-none border-y border-brand-ink/5 bg-brand-sand/20 px-3 py-0.5 text-center text-[10px] tracking-wide text-brand-mist">···</div>
                                            @endif
                                            @php $isError = isset($errorSet[$lineNum]); @endphp
                                            <div class="flex {{ $isError ? 'bg-rose-50' : '' }}">
                                                <span class="w-12 shrink-0 select-none border-r {{ $isError ? 'border-rose-200 bg-rose-100 font-semibold text-rose-700' : 'border-brand-ink/8 text-brand-mist' }} px-3 py-0.5 text-right">{{ $lineNum }}</span>
                                                <span class="overflow-x-auto whitespace-pre px-3 py-0.5 {{ $isError ? 'text-rose-900' : 'text-brand-ink' }}">{{ $editorLines[$lineNum - 1] ?? '' }}</span>
                                            </div>
                                            @php $previousLine = $lineNum; @endphp
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if ($phpConfigEditorValidationOutput)
                                <div class="{{ $card }}">
                                    <div class="border-b border-brand-ink/10 px-5 py-3 text-sm font-medium text-brand-ink">
                                        {{ __('Verification output') }}
                                    </div>
                                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words bg-brand-ink/95 p-4 font-mono text-xs leading-relaxed text-emerald-100">{{ $phpConfigEditorValidationOutput }}</pre>
                                </div>
                            @endif

                            <div class="space-y-3">
                                @if ($editorHasErrors)
                                    <div class="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50/70 px-4 py-3 text-sm text-rose-900">
                                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-rose-700" />
                                        <p class="leading-relaxed">
                                            <span class="font-semibold">{{ __('Validation failed.') }}</span>
                                            {{ __('Fix the highlighted line(s) and re-save, or click "Discard changes" to revert to the version currently on the server.') }}
                                        </p>
                                    </div>
                                @endif

                                <details class="text-sm" @if (trim($phpConfigEditorSummary) !== '') open @endif>
                                    <summary class="cursor-pointer text-brand-moss hover:text-brand-ink">{{ __('Add note (optional)') }}</summary>
                                    <input
                                        type="text"
                                        wire:model.defer="phpConfigEditorSummary"
                                        maxlength="255"
                                        placeholder="{{ __('What did you change? Attached to the revision saved on next save.') }}"
                                        class="mt-2 w-full rounded-xl border border-brand-ink/10 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-ink/20 focus:outline-none focus:ring-0"
                                    />
                                </details>

                                <div class="flex flex-wrap gap-3">
                                    <button type="button" wire:click="savePhpConfigEditor" wire:loading.attr="disabled" wire:target="savePhpConfigEditor" class="{{ $btnPrimary }}">
                                        <span wire:loading.remove wire:target="savePhpConfigEditor">{{ __('Save and validate config') }}</span>
                                        <span wire:loading wire:target="savePhpConfigEditor">{{ __('Validating and saving…') }}</span>
                                    </button>
                                    @if ($editorHasErrors)
                                        <button type="button" wire:click="discardPhpConfigEditor" wire:loading.attr="disabled" wire:target="discardPhpConfigEditor" class="inline-flex items-center justify-center gap-2 rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm font-medium text-rose-700 transition hover:border-rose-300 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60">
                                            <x-heroicon-o-arrow-uturn-left class="h-4 w-4" wire:loading.remove wire:target="discardPhpConfigEditor" />
                                            <span wire:loading wire:target="discardPhpConfigEditor" class="inline-flex h-4 w-4 items-center justify-center">
                                                <x-spinner variant="forest" size="sm" />
                                            </span>
                                            <span wire:loading.remove wire:target="discardPhpConfigEditor">{{ __('Discard changes') }}</span>
                                            <span wire:loading wire:target="discardPhpConfigEditor">{{ __('Discarding…') }}</span>
                                        </button>
                                    @else
                                        <button type="button" wire:click="closePhpConfigEditor" wire:loading.attr="disabled" wire:target="closePhpConfigEditor" class="{{ $btnSecondary }}">
                                            <span wire:loading.remove wire:target="closePhpConfigEditor">{{ __('Cancel') }}</span>
                                            <span wire:loading wire:target="closePhpConfigEditor">{{ __('Closing…') }}</span>
                                        </button>
                                    @endif
                                </div>
                            </div>
                            @endif {{-- diffOpen / editor toggle --}}
                            </div> {{-- editor pane --}}
                        </div> {{-- flex wrapper --}}
                    </div>
                </div>
            </div>
        @endif
    </x-slot>
</x-server-workspace-layout>

@include('livewire.partials.confirm-action-modal')
</div>{{-- /single root --}}
