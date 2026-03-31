@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-ink/90 disabled:cursor-not-allowed disabled:opacity-60';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2 text-sm font-medium text-brand-ink transition hover:border-brand-ink/20 hover:bg-brand-sand/30 disabled:cursor-not-allowed disabled:opacity-60';
    $badge = 'inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-sand/30 px-2.5 py-1 text-xs font-medium text-brand-ink';
@endphp

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

    <div class="space-y-6">
        <div class="{{ $card }}">
            <div class="p-6 sm:p-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Server PHP summary') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Review the server-owned PHP inventory and refresh the saved snapshot before you make PHP changes elsewhere.') }}
                        </p>
                    </div>

                    @can('update', $server)
                        @if ($opsReady)
                            <button type="button" wire:click="refreshPhpInventory" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
                                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="refreshPhpInventory" />
                                <span wire:loading.remove wire:target="refreshPhpInventory">{{ __('Refresh inventory') }}</span>
                                <span wire:loading wire:target="refreshPhpInventory">{{ __('Refreshing…') }}</span>
                            </button>
                        @endif
                    @endcan
                </div>

                <div class="mt-6 space-y-4">
                    @if ($sshUnavailable)
                        <div class="rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('SSH unavailable') }}</p>
                            <p class="mt-1 text-amber-900/90">
                                {{ __('Add or restore this server\'s SSH access before Dply can inspect or manage PHP.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryRefreshRunning)
                        <div class="rounded-2xl border border-sky-200/80 bg-sky-50/90 px-5 py-4 text-sm text-sky-950">
                            <p class="font-semibold">{{ __('PHP inventory refresh running') }}</p>
                            <p class="mt-1 text-sky-900/90">
                                {{ __('Dply is collecting the latest installed versions and CLI default from the server.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryRefreshFailed)
                        <div class="rounded-2xl border border-red-200/80 bg-red-50/90 px-5 py-4 text-sm text-red-950">
                            <p class="font-semibold">{{ __('PHP inventory refresh failed') }}</p>
                            <p class="mt-1 text-red-900/90">
                                {{ $phpInventoryRefreshError ?: __('The last PHP inspection attempt did not complete successfully.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryStale)
                        <div class="rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('PHP inventory may be stale') }}</p>
                            <p class="mt-1 text-amber-900/90">
                                {{ $phpInventoryRefreshError ?: __('Remote PHP state changed, but Dply could not save the refreshed snapshot.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpEnvironmentUnsupported)
                        <div class="rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('Unsupported environment') }}</p>
                            <p class="mt-1 text-amber-900/90">
                                {{ __('This server does not currently report a PHP environment that the management workspace can support.') }}
                            </p>
                        </div>
                    @endif

                    @if ($phpInventoryNeverRun)
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/25 px-5 py-4 text-sm text-brand-ink">
                            <p class="font-semibold">{{ __('No PHP inventory yet') }}</p>
                            <p class="mt-1 text-brand-moss">
                                {{ __('PHP inventory will appear here after the first refresh runs.') }}
                            </p>
                        </div>
                    @endif

                    <dl class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                            <dt class="text-sm font-medium text-brand-moss">{{ __('Installed versions') }}</dt>
                            <dd class="mt-2 text-2xl font-semibold text-brand-ink">{{ $phpSummary['installed_count'] }}</dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                            <dt class="text-sm font-medium text-brand-moss">{{ __('CLI default') }}</dt>
                            <dd class="mt-2 text-lg font-semibold text-brand-ink">
                                {{ $phpSummary['cli_default'] ? 'PHP '.$phpSummary['cli_default'] : __('Not set') }}
                            </dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                            <dt class="text-sm font-medium text-brand-moss">{{ __('Default for new sites') }}</dt>
                            <dd class="mt-2 text-lg font-semibold text-brand-ink">
                                {{ $phpSummary['new_site_default'] ? 'PHP '.$phpSummary['new_site_default'] : __('Not set') }}
                            </dd>
                        </div>
                    </dl>

                    @if (! $opsReady && ! $sshUnavailable)
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/70 px-5 py-4 text-sm text-brand-moss">
                            {{ __('Once provisioning finishes, this page will show installed PHP versions, defaults, and shared configuration entry points.') }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        @if ($opsReady && ! $sshUnavailable && ! $phpInventoryNeverRun)
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Installed and supported versions') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Keep actions visible, but disable any row action that would violate current site usage or server defaults.') }}</p>
                </div>

                <div class="divide-y divide-brand-ink/10">
                    @foreach ($phpVersionRows as $row)
                        @php
                            $isInstalled = $row['is_installed'] ?? false;
                            $isCliDefault = ($phpSummary['cli_default'] ?? null) === $row['id'];
                            $isNewSiteDefault = ($phpSummary['new_site_default'] ?? null) === $row['id'];
                            $siteCount = (int) ($row['site_count'] ?? 0);
                            $disableUninstall = ! $isInstalled || $siteCount > 0 || $isCliDefault || $isNewSiteDefault;
                            $actionTarget = fn (string $action) => "runPhpPackageAction('{$action}', '{$row['id']}')";
                        @endphp

                        <div class="px-6 py-5 sm:px-8">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div class="space-y-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-brand-ink">{{ $row['label'] }}</h3>
                                        @if ($isInstalled)
                                            <span class="{{ $badge }}">{{ __('Installed') }}</span>
                                        @else
                                            <span class="{{ $badge }}">{{ __('Available to install') }}</span>
                                        @endif
                                        @if ($isCliDefault)
                                            <span class="{{ $badge }}">{{ __('CLI default') }}</span>
                                        @endif
                                        @if ($isNewSiteDefault)
                                            <span class="{{ $badge }}">{{ __('Default for new sites') }}</span>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap gap-4 text-sm text-brand-moss">
                                        <span>{{ trans_choice('Used by :count site|Used by :count sites', $siteCount, ['count' => $siteCount]) }}</span>
                                        @if (! $row['is_supported'])
                                            <span>{{ __('Detected in inventory, but not in the current supported catalog.') }}</span>
                                        @endif
                                    </div>

                                    @if ($disableUninstall)
                                        <p class="text-sm text-brand-moss">
                                            @if (! $isInstalled)
                                                {{ __('Install this version before using server-level actions.') }}
                                            @elseif ($siteCount > 0)
                                                {{ __('Uninstall is blocked while any site still uses this version.') }}
                                            @elseif ($isCliDefault)
                                                {{ __('Uninstall is blocked while this is the CLI default.') }}
                                            @elseif ($isNewSiteDefault)
                                                {{ __('Uninstall is blocked while this is the default for new PHP sites.') }}
                                            @endif
                                        </p>
                                    @endif
                                </div>

                                <div class="flex flex-col gap-3 xl:items-end">
                                    <div class="flex flex-wrap gap-2">
                                        @can('update', $server)
                                            <button
                                                type="button"
                                                wire:click="runPhpPackageAction('install', '{{ $row['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $actionTarget('install') }}"
                                                class="{{ $btnPrimary }}"
                                                @disabled($isInstalled)
                                            >
                                                {{ __('Install') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="runPhpPackageAction('patch', '{{ $row['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $actionTarget('patch') }}"
                                                class="{{ $btnSecondary }}"
                                                @disabled(! $isInstalled)
                                            >
                                                {{ __('Patch') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="runPhpPackageAction('set_cli_default', '{{ $row['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $actionTarget('set_cli_default') }}"
                                                class="{{ $btnSecondary }}"
                                                @disabled(! $isInstalled || $isCliDefault)
                                            >
                                                {{ __('Set CLI default') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="runPhpPackageAction('set_new_site_default', '{{ $row['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $actionTarget('set_new_site_default') }}"
                                                class="{{ $btnSecondary }}"
                                                @disabled(! $isInstalled || $isNewSiteDefault)
                                            >
                                                {{ __('Set new-site default') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="runPhpPackageAction('uninstall', '{{ $row['id'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="{{ $actionTarget('uninstall') }}"
                                                class="{{ $btnSecondary }}"
                                                @disabled($disableUninstall)
                                            >
                                                {{ __('Uninstall') }}
                                            </button>
                                        @endcan
                                    </div>

                                    @can('update', $server)
                                        <div class="flex flex-wrap gap-2 text-sm">
                                            <button
                                                type="button"
                                                wire:click="openPhpConfigEditor('{{ $row['id'] }}', 'cli_ini')"
                                                wire:loading.attr="disabled"
                                                wire:target="openPhpConfigEditor('{{ $row['id'] }}', 'cli_ini')"
                                                class="{{ $btnSecondary }}"
                                                @disabled(! $isInstalled)
                                            >
                                                {{ __('CLI ini') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openPhpConfigEditor('{{ $row['id'] }}', 'fpm_ini')"
                                                wire:loading.attr="disabled"
                                                wire:target="openPhpConfigEditor('{{ $row['id'] }}', 'fpm_ini')"
                                                class="{{ $btnSecondary }}"
                                                @disabled(! $isInstalled)
                                            >
                                                {{ __('FPM ini') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openPhpConfigEditor('{{ $row['id'] }}', 'pool_config')"
                                                wire:loading.attr="disabled"
                                                wire:target="openPhpConfigEditor('{{ $row['id'] }}', 'pool_config')"
                                                class="{{ $btnSecondary }}"
                                                @disabled(! $isInstalled)
                                            >
                                                {{ __('Pool config') }}
                                            </button>
                                        </div>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($phpConfigEditorOpen)
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">
                                {{ __('Editing PHP :version :target', ['version' => $phpConfigEditorVersion, 'target' => $phpConfigEditorTargetLabel]) }}
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Review and verify the selected shared PHP config before replacing the live file.') }}
                            </p>
                            @if ($phpConfigEditorPath)
                                <p class="mt-2 font-mono text-xs text-brand-moss">{{ $phpConfigEditorPath }}</p>
                            @endif
                        </div>

                        <button type="button" wire:click="closePhpConfigEditor" wire:loading.attr="disabled" class="{{ $btnSecondary }}">
                            {{ __('Close') }}
                        </button>
                    </div>
                </div>

                <div class="space-y-4 p-6 sm:p-8">
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
                            class="w-full rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-ink/20 focus:outline-none focus:ring-0"
                        ></textarea>
                    </div>

                    @if ($phpConfigEditorValidationOutput)
                        <div class="{{ $card }}">
                            <div class="border-b border-brand-ink/10 px-5 py-3 text-sm font-medium text-brand-ink">
                                {{ __('Verification output') }}
                            </div>
                            <pre class="max-h-80 overflow-x-auto bg-brand-ink p-4 text-sm text-emerald-400/95">{{ $phpConfigEditorValidationOutput }}</pre>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-3">
                        <button type="button" wire:click="savePhpConfigEditor" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
                            {{ __('Save config') }}
                        </button>
                        <button type="button" wire:click="closePhpConfigEditor" wire:loading.attr="disabled" class="{{ $btnSecondary }}">
                            {{ __('Cancel') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-server-workspace-layout>
