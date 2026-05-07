@php
    // No overflow-hidden on the table card — the row-action kebab
    // dropdown is positioned absolute and gets clipped at the card
    // edge otherwise. dply-card already handles rounded corners.
    $card = 'dply-card';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnDanger = 'inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white shadow-sm hover:bg-red-700 transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $customMeta = $server->meta['custom_systemd_services'] ?? [];
    $customMetaList = is_array($customMeta) ? array_values(array_filter($customMeta, fn ($v) => is_string($v) && $v !== '')) : [];
    $manageableSystemdCount = collect($systemdInventory ?? [])->filter(fn ($r) => ! empty($r['may_mutate']))->count();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="services"
    :title="__('Services')"
    :description="__('Running systemd units from database-backed inventory; actions use the same SSH safeguards as Manage.')"
>
    @if ($systemdRemoteTaskId)
        <div wire:poll.2s="syncSystemdRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif
    {{--
        Reverb context for the systemd action banner. bindDplyServerSystemdActionChannel() in
        bootstrap.js subscribes to private-server.{id} when subscribe="1" (i.e. a queued task is
        in flight) and dispatches the 'systemd-action-completed' Livewire event on broadcast.
        wire:poll above remains as the fallback when Reverb is off or events drop.
    --}}
    <div
        id="dply-server-systemd-action-context"
        class="hidden"
        aria-hidden="true"
        data-server-id="{{ $server->id }}"
        data-subscribe="{{ $systemdRemoteTaskId ? '1' : '0' }}"
    ></div>
    @script
        <script>
            // Re-bind on every Livewire render so subscribe="1"/"0" transitions take effect
            // without waiting for livewire:navigated.
            window.__dplyBindServicesEcho?.();
        </script>
    @endscript
    <div wire:init="maybeRefreshSystemdInventoryOnLoad" class="hidden" aria-hidden="true"></div>
    @if ($opsReady && ! $showSystemdStatusModal)
        {{-- Avoid concurrent poll + modal SSH refresh (Livewire request overlap). --}}
        <div wire:poll.5s="refreshSystemdUiFromDatabase" class="hidden" aria-hidden="true"></div>
    @endif
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4">
        <p>{{ __('Inventory of the systemd units on this server, surfaced live from systemctl list-units. Restart, stop, start, and enable/disable map to the matching systemctl verbs and run as root over SSH.') }}</p>
        <p>{{ __('Custom services are systemd unit files dply tracks specifically — they show up as actionable rows. Stock units (sshd, networkd, etc.) are visible but actions are gated to the ones dply considers safe to mutate.') }}</p>
    </x-explainer>

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project operations shortcut') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('Service changes here may affect the wider project. Use the project operations page to review runbooks, recent activity, and alert routing when this server is part of a larger grouped stack.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                <a href="{{ route('projects.access', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Review project access') }}</a>
            </div>
        </div>
    @endif

    @if ($isDeployer && ($deployerSystemdLocked ?? true))
        <div class="rounded-2xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
            {{ __('Deployers can view this page but cannot run service actions over SSH unless your organization allows deployer systemd access.') }}
        </div>
    @endif

    @if (! $opsReady)
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before managing services.') }}
        </div>
    @endif

    @if ($opsReady)
        <div class="space-y-6">

        @php
            $actionBusy = in_array($systemdActionBannerStatus, ['queued', 'running'], true);
            $actionSettled = in_array($systemdActionBannerStatus, ['completed', 'failed'], true);
            $showActionBanner = $actionBusy || $actionSettled;
            $actionUnitLabel = $systemdActionBannerUnit;
            $actionVerbBusy = match ($systemdActionBannerKind) {
                'start' => __('Starting :unit…', ['unit' => $actionUnitLabel]),
                'stop' => __('Stopping :unit…', ['unit' => $actionUnitLabel]),
                'restart' => __('Restarting :unit…', ['unit' => $actionUnitLabel]),
                'reload' => __('Reloading :unit…', ['unit' => $actionUnitLabel]),
                'enable' => __('Enabling :unit at boot…', ['unit' => $actionUnitLabel]),
                'disable' => __('Disabling :unit at boot…', ['unit' => $actionUnitLabel]),
                'bulk-restart' => __('Restarting :unit…', ['unit' => $actionUnitLabel]),
                'bulk-stop' => __('Stopping :unit…', ['unit' => $actionUnitLabel]),
                'inventory-sync' => __('Syncing inventory on :host …', ['host' => $server->getSshConnectionString()]),
                default => __('Running on :unit…', ['unit' => $actionUnitLabel]),
            };
            $actionVerbDone = match ($systemdActionBannerKind) {
                'start' => __('Started :unit', ['unit' => $actionUnitLabel]),
                'stop' => __('Stopped :unit', ['unit' => $actionUnitLabel]),
                'restart' => __('Restarted :unit', ['unit' => $actionUnitLabel]),
                'reload' => __('Reloaded :unit', ['unit' => $actionUnitLabel]),
                'enable' => __('Enabled :unit at boot', ['unit' => $actionUnitLabel]),
                'disable' => __('Disabled :unit at boot', ['unit' => $actionUnitLabel]),
                'bulk-restart' => __('Bulk restart finished — :unit', ['unit' => $actionUnitLabel]),
                'bulk-stop' => __('Bulk stop finished — :unit', ['unit' => $actionUnitLabel]),
                'inventory-sync' => __('Inventory synced'),
                default => __('Action finished — :unit', ['unit' => $actionUnitLabel]),
            };
            $actionVerbFailed = match ($systemdActionBannerKind) {
                'bulk-restart', 'bulk-stop' => __('Bulk action failed — :unit', ['unit' => $actionUnitLabel]),
                'inventory-sync' => __('Inventory sync failed'),
                default => __('Action failed — :unit', ['unit' => $actionUnitLabel]),
            };
            $actionMessage = match ($systemdActionBannerStatus) {
                'queued', 'running' => $actionVerbBusy,
                'completed' => $actionVerbDone,
                'failed' => $actionVerbFailed,
                default => '',
            };
            $actionFinishedRel = null;
            if ($systemdActionBannerFinishedAt) {
                try {
                    $actionFinishedRel = \Illuminate\Support\Carbon::parse($systemdActionBannerFinishedAt)->diffForHumans();
                } catch (\Throwable) {
                    $actionFinishedRel = null;
                }
            }
            $actionSubtitle = match (true) {
                $systemdActionBannerStatus === 'queued' => __('Task queued — waiting for a worker to pick it up.'),
                $systemdActionBannerStatus === 'running' => __('Running on :host …', ['host' => $server->getSshConnectionString()]),
                $systemdActionBannerStatus === 'failed' && $systemdActionBannerError => $systemdActionBannerError,
                $systemdActionBannerStatus === 'completed' && $actionFinishedRel
                    => __('Finished :time', ['time' => $actionFinishedRel]),
                default => null,
            };
        @endphp

        @if ($showActionBanner)
            <x-workspace-console-banner
                :status="$systemdActionBannerStatus"
                :message="$actionMessage"
                :subtitle="$actionSubtitle"
                :output="$systemdActionBannerLines"
                :busy="$actionBusy"
                :dismiss-action="$actionBusy ? null : 'dismissSystemdActionBanner'"
                :poll-action="$actionBusy && $systemdRemoteTaskId ? 'syncSystemdRemoteTaskFromCache' : null"
                poll-interval="2s"
                :default-expanded="true"
            />
        @endif

        @php
            $syncStatus = (string) ($systemdSyncMeta['status'] ?? '');
            $syncAt = $systemdSyncMeta['at'] ?? null;
            $syncError = (string) ($systemdSyncMeta['error'] ?? '');
            $syncDurationMs = $systemdSyncMeta['duration_ms'] ?? null;
            $syncDismissed = $systemdSyncBannerDismissedAt !== null
                && (string) $systemdSyncBannerDismissedAt === (string) $syncAt;
            $showSyncBanner = ! $showActionBanner
                && ! $syncDismissed
                && in_array($syncStatus, ['success', 'failed'], true);
            $syncBannerStatus = $syncStatus === 'success' ? 'completed' : 'failed';
            $syncRel = null;
            if ($syncAt !== null) {
                try {
                    $syncRel = \Illuminate\Support\Carbon::parse($syncAt)->diffForHumans();
                } catch (\Throwable) {
                    $syncRel = null;
                }
            }
            $syncBannerMessage = $syncStatus === 'success'
                ? __('Inventory sync succeeded')
                : __('Last inventory sync failed');
            $syncBannerSubtitle = match (true) {
                $syncStatus === 'failed' && $syncError !== '' => $syncError,
                $syncStatus === 'success' && $syncDurationMs !== null && $syncRel !== null
                    => __('Finished :time · in :ms ms', ['time' => $syncRel, 'ms' => (int) $syncDurationMs]),
                $syncStatus === 'success' && $syncRel !== null
                    => __('Finished :time', ['time' => $syncRel]),
                $syncStatus === 'failed' && $syncRel !== null
                    => __('Failed :time', ['time' => $syncRel]),
                default => null,
            };
        @endphp

        @if ($showSyncBanner)
            <x-workspace-console-banner
                :status="$syncBannerStatus"
                :message="$syncBannerMessage"
                :subtitle="$syncBannerSubtitle"
                :output="[]"
                :busy="false"
                dismiss-action="dismissSystemdSyncBanner"
                :default-expanded="false"
            />
        @endif

        <x-server-workspace-tablist :aria-label="__('Services workspace')">
            <x-server-workspace-tab id="services-tab-inventory" :active="$services_workspace_tab === 'inventory'" wire:click="$set('services_workspace_tab', 'inventory')">
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-cog-6-tooth class="h-4 w-4" aria-hidden="true" />
                    {{ __('Inventory') }}
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab id="services-tab-activity" :active="$services_workspace_tab === 'activity'" wire:click="$set('services_workspace_tab', 'activity')">
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                    {{ __('Activity') }}
                </span>
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        <x-server-workspace-tab-panel
            id="services-panel-inventory"
            labelled-by="services-tab-inventory"
            :hidden="$services_workspace_tab !== 'inventory'"
            panel-class="space-y-6"
        >

    <div class="{{ $card }}">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('System services') }}</h2>
                @if ($systemdInventoryFetchedAt)
                    @php
                        try {
                            $snapHuman = \Carbon\Carbon::parse($systemdInventoryFetchedAt)->timezone(config('app.timezone'))->diffForHumans();
                        } catch (\Throwable) {
                            $snapHuman = null;
                        }
                    @endphp
                    @if ($snapHuman)
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Last inventory sync: :time', ['time' => $snapHuman]) }}</p>
                    @endif
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="openCustomSystemdModal"
                    wire:loading.attr="disabled"
                    wire:target="openCustomSystemdModal"
                    @disabled($isDeployer)
                    class="{{ $btnSecondary }}"
                >
                    <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0 opacity-90" wire:loading.remove wire:target="openCustomSystemdModal" />
                    <span wire:loading wire:target="openCustomSystemdModal" class="inline-flex h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                    <span wire:loading.remove wire:target="openCustomSystemdModal">{{ __('Custom services') }}</span>
                    <span wire:loading wire:target="openCustomSystemdModal">{{ __('Working…') }}</span>
                </button>
                @php
                    $syncInFlight = $systemdActionBannerKind === 'inventory-sync'
                        && in_array($systemdActionBannerStatus, ['queued', 'running'], true);
                @endphp
                <button
                    type="button"
                    wire:click="refreshSystemdInventory"
                    wire:loading.attr="disabled"
                    @disabled(! $opsReady || $isDeployer || $syncInFlight)
                    title="{{ $syncInFlight ? __('A sync is already running. Wait for it to finish.') : '' }}"
                    class="{{ $btnPrimary }}"
                >
                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 opacity-90 {{ $syncInFlight ? 'animate-spin' : '' }}" wire:loading.class="animate-spin" wire:target="refreshSystemdInventory" />
                    <span wire:loading.remove wire:target="refreshSystemdInventory">{{ $syncInFlight ? __('Syncing…') : __('Sync now') }}</span>
                    <span wire:loading wire:target="refreshSystemdInventory">{{ __('Working…') }}</span>
                </button>
            </div>
        </div>

        @php
            $selectedCount = count($systemdSelectedList ?? []);
        @endphp
        @if ($selectedCount > 0 && ! ($deployerSystemdLocked ?? true) && $opsReady)
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <p class="text-sm font-medium text-brand-ink">
                    {{ __(':count selected', ['count' => $selectedCount]) }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="openSystemdActionConfirm('bulk-restart')"
                        wire:loading.attr="disabled"
                        wire:target="openSystemdActionConfirm"
                        @disabled($systemdBulkBusy || ($systemdRowBusyUnit !== null && $systemdRowBusyUnit !== ''))
                        class="{{ $btnSecondary }}"
                    >
                        <span wire:loading wire:target="openSystemdActionConfirm" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                        <span wire:loading.remove wire:target="openSystemdActionConfirm">{{ __('Restart selected') }}</span>
                        <span wire:loading wire:target="openSystemdActionConfirm">{{ __('Working…') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="openSystemdActionConfirm('bulk-stop')"
                        wire:loading.attr="disabled"
                        wire:target="openSystemdActionConfirm"
                        @disabled($systemdBulkBusy || ($systemdRowBusyUnit !== null && $systemdRowBusyUnit !== ''))
                        class="{{ $btnDanger }}"
                    >
                        <span wire:loading wire:target="openSystemdActionConfirm" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>
                        <span wire:loading.remove wire:target="openSystemdActionConfirm">{{ __('Stop selected') }}</span>
                        <span wire:loading wire:target="openSystemdActionConfirm">{{ __('Working…') }}</span>
                    </button>
                    <button type="button" wire:click="$set('systemdSelectedList', []); $set('systemdSelectAll', false)" class="text-sm font-semibold text-brand-moss hover:text-brand-ink">
                        {{ __('Clear') }}
                    </button>
                </div>
            </div>
        @endif

        <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-4 sm:flex-row sm:flex-wrap sm:items-end sm:gap-4 sm:px-8">
            <div class="min-w-[12rem] flex-1">
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Search') }}</label>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="systemdFilterSearch"
                    placeholder="{{ __('Unit or label') }}"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                />
            </div>
            <div>
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Activity') }}</label>
                <select wire:model.live="systemdFilterActive" class="mt-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30">
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="failed">{{ __('Failed') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Source') }}</label>
                <select wire:model.live="systemdFilterCustom" class="mt-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30">
                    <option value="all">{{ __('All') }}</option>
                    <option value="custom">{{ __('Custom only') }}</option>
                    <option value="default">{{ __('Default list only') }}</option>
                </select>
            </div>
        </div>

        @php
            $systemHiddenCount = $this->systemdHiddenSystemCount();
        @endphp
        <div class="-mx-4 sm:mx-0">
            {{-- Round the bottom edge of the last row so the table doesn't
                 poke past the card's rounded-2xl corners. We removed
                 overflow-hidden from the card to let the row-action kebab
                 dropdown escape; this restores the visual seal. --}}
            <table class="w-full divide-y divide-brand-ink/10 text-left text-sm
                [&>tbody>tr:last-child>td:first-child]:rounded-bl-2xl
                [&>tbody>tr:last-child>td:last-child]:rounded-br-2xl">
                <thead class="bg-brand-sand/40 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                    <tr>
                        <th scope="col" class="w-10 p-4">
                            <span class="sr-only">{{ __('Select') }}</span>
                            <input
                                type="checkbox"
                                class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                                wire:model.live="systemdSelectAll"
                                title="{{ __('Select allowlisted services only') }}"
                                @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || $manageableSystemdCount === 0)
                            />
                        </th>
                        <th scope="col" class="w-2 p-0"></th>
                        <th scope="col" class="p-4">{{ __('Name') }}</th>
                        <th scope="col" class="p-4">{{ __('Last state change') }}</th>
                        <th scope="col" class="p-4 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @forelse (($filteredSystemdInventory ?? $systemdInventory) as $row)
                        @php
                            $canManage = ! empty($row['can_manage']);
                            $mayMutate = ! empty($row['may_mutate']);
                            $isFailed = ! empty($row['is_failed']);
                            $isActive = ($row['active'] ?? '') === 'active';
                            $tsLabel = $row['ts'] ?? '';
                            $rel = null;
                            if ($tsLabel !== '' && $tsLabel !== 'n/a') {
                                try {
                                    $rel = \Carbon\Carbon::parse($tsLabel)->diffForHumans();
                                } catch (\Throwable) {
                                    $rel = null;
                                }
                            }
                            $ver = trim((string) ($row['version'] ?? ''));
                            $nameLine = $row['label'] ?? $row['unit'];
                            if ($ver !== '') {
                                $nameLine .= ' ('.$ver.')';
                            }
                            $rowUnit = $row['unit'] ?? '';
                            $rowBusy = ($systemdRowBusyUnit ?? '') !== '' && ($systemdRowBusyUnit ?? '') === $rowUnit;
                            $otherBusy = (($systemdRowBusyUnit ?? '') !== '' && ! $rowBusy) || ($systemdBulkBusy ?? false);
                            $bootUnk = trim((string) ($row['boot_state'] ?? '')) === '';
                            // Optimistic pending action surface. When set, render
                            // "Starting…" / "Stopping…" / "Restarting…" / "Reloading…"
                            // immediately after the user clicks, before the SSH
                            // round-trip + inventory sync confirms the new state.
                            $pendingAction = $row['pending_action'] ?? null;
                            $pendingLabel = match ($pendingAction) {
                                'start' => __('Starting…'),
                                'stop' => __('Stopping…'),
                                'restart' => __('Restarting…'),
                                'reload' => __('Reloading…'),
                                'enable' => __('Enabling at boot…'),
                                'disable' => __('Disabling at boot…'),
                                default => null,
                            };
                            // While an action is in flight on this unit, lock
                            // out every other action button on the same row so
                            // the operator can't queue a Stop on top of a Start
                            // (or open the kebab to do it via a different path).
                            $rowPending = $pendingLabel !== null;
                        @endphp
                        <tr
                            wire:key="systemd-svc-{{ $rowUnit }}"
                            wire:loading.class="opacity-60 pointer-events-none"
                            wire:target="openSystemdStatusModalForService({{ json_encode($rowUnit) }}),openSystemdLogsModalForService({{ json_encode($rowUnit) }}),openSystemdNotifyModalForService({{ json_encode($rowUnit) }}),runSystemdServiceAction({{ json_encode($rowUnit) }})"
                            x-data="{
                                rowLoading: false,
                                fireRowLoading() {
                                    this.rowLoading = true;
                                    clearTimeout(this._rl);
                                    // 8 s covers most SSH-driven action / status round-trips.
                                    // The persistent server-side $rowBusy or modal-visible
                                    // state takes over for genuinely long actions; this is
                                    // just the click→first-feedback bridge.
                                    this._rl = setTimeout(() => { this.rowLoading = false; }, 8000);
                                },
                            }"
                            :class="rowLoading ? 'opacity-60 pointer-events-none' : ''"
                            @class(['bg-red-50/40' => $isFailed, 'transition-opacity duration-150'])
                        >
                            <td class="p-4 align-top">
                            <input
                                type="checkbox"
                                class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                                value="{{ $rowUnit }}"
                                wire:model.live="systemdSelectedList"
                                @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || ! $mayMutate)
                            />
                            </td>
                            <td class="relative p-0">
                                <span class="absolute inset-y-3 left-0 w-1 rounded-full {{ $pendingLabel ? 'bg-amber-500 animate-pulse' : ($isFailed ? 'bg-red-500' : ($isActive ? 'bg-emerald-500' : 'bg-brand-mist/60')) }}" title="{{ $row['active'] ?? '' }} / {{ $row['sub'] ?? '' }}"></span>
                            </td>
                            <td class="p-4 align-top">
                                <p class="font-medium text-brand-ink">{{ $nameLine }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    @if ($pendingLabel)
                                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-amber-200">
                                            <span class="inline-block size-2.5 shrink-0 animate-spin rounded-full border-2 border-amber-300 border-t-amber-700" aria-hidden="true"></span>
                                            {{ $pendingLabel }}
                                        </span>
                                    @elseif ($isFailed)
                                        <span class="inline-flex rounded-md bg-red-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-900 ring-1 ring-red-200">{{ __('Failed') }}</span>
                                    @endif
                                    @if (! empty($row['main_pid']) && ($row['main_pid'] ?? '') !== '0')
                                        <span class="text-[10px] font-mono text-brand-moss" title="{{ __('Main PID') }}">PID {{ $row['main_pid'] }}</span>
                                    @endif
                                    @if (! $bootUnk)
                                        <span class="text-[10px] font-medium uppercase tracking-wide text-brand-moss">{{ __('Boot') }}: {{ $row['boot_state'] }}</span>
                                    @endif
                                </div>
                                @if (! $canManage)
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('View only') }}</p>
                                @elseif (! empty($row['status_only']))
                                    <p class="mt-0.5 text-xs text-amber-800">{{ __('Status-only (org policy)') }}</p>
                                @elseif (! empty($row['custom']))
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Custom') }}</p>
                                @endif
                            </td>
                            <td class="p-4 align-top text-brand-moss">
                                @php
                                    $tsCompact = null;
                                    if ($tsLabel !== '' && $tsLabel !== 'n/a') {
                                        try {
                                            $tsCompact = \Carbon\Carbon::parse($tsLabel)->format('M j, H:i');
                                        } catch (\Throwable) {
                                            $tsCompact = null;
                                        }
                                    }
                                @endphp
                                @if ($tsCompact)
                                    <span class="text-xs whitespace-nowrap" title="{{ $tsLabel }}">
                                        <span class="font-medium text-brand-ink">{{ $tsCompact }}</span>
                                        @if ($rel)
                                            <span class="text-brand-moss">· {{ $rel }}</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="p-4 align-top text-right">
                                @php
                                    $rowManageExtras = $mayMutate && $opsReady && ! ($deployerSystemdLocked ?? true);
                                    $rowAlerts = ! $isDeployer && $server->organization_id;
                                    $showBootEnable = (bool) ($row['boot_menu_show_enable'] ?? true);
                                    $showBootDisable = (bool) ($row['boot_menu_show_disable'] ?? true);
                                    $hasBootMenu = $rowManageExtras && ($showBootEnable || $showBootDisable);
                                    $hasMoreMenu = $rowManageExtras || $rowAlerts || (! empty($row['custom']));
                                @endphp
                                <div class="flex flex-nowrap items-center justify-end gap-2">
                                    {{-- Persistent SSH-running pill, driven server-side once
                                         the actual remote task is firing for THIS unit. The
                                         row's Alpine rowLoading flag handles the briefer
                                         click → modal-open window by dimming the whole tr. --}}
                                    @if ($rowBusy || ($systemdBulkBusy ?? false))
                                        <span class="inline-flex items-center gap-1.5 text-[11px] font-medium text-brand-moss">
                                            <span class="inline-block size-3.5 animate-spin rounded-full border-2 border-brand-ink/20 border-t-brand-ink" aria-hidden="true"></span>
                                            {{ __('Working…') }}
                                        </span>
                                    @endif
                                    {{-- One primary action stays visible: Start when inactive,
                                         Restart when active and manageable, Status for view-only.
                                         Everything else (Stop, Status, Reload, Boot toggles,
                                         Notify, Notification channels, Remove custom) lives in
                                         the per-row More menu below. --}}
                                    @if ($mayMutate && ! $isActive)
                                        <button
                                            type="button"
                                            @click="fireRowLoading()"
                                            wire:click="runSystemdServiceAction(@js($rowUnit), 'start')"
                                            wire:loading.attr="disabled"
                                            wire:target="runSystemdServiceAction"
                                            @disabled(! $opsReady || $otherBusy || $rowBusy || $rowPending)
                                            class="{{ $btnSecondary }} !inline-flex !items-center !gap-1.5 !shrink-0 !py-2 !text-[11px]"
                                        >
                                            <x-heroicon-o-play class="h-3.5 w-3.5 shrink-0 text-emerald-700" wire:loading.remove wire:target="runSystemdServiceAction" aria-hidden="true" />
                                            <span wire:loading wire:target="runSystemdServiceAction" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                                            <span wire:loading.remove wire:target="runSystemdServiceAction">{{ __('Start') }}</span>
                                            <span wire:loading wire:target="runSystemdServiceAction">{{ __('Working…') }}</span>
                                        </button>
                                    @elseif ($mayMutate)
                                        <button
                                            type="button"
                                            @click="fireRowLoading()"
                                            wire:click="openSystemdActionConfirm('restart', @js($rowUnit))"
                                            wire:loading.attr="disabled"
                                            wire:target="openSystemdActionConfirm"
                                            @disabled(! $opsReady || $otherBusy || $rowBusy || $rowPending)
                                            class="{{ $btnSecondary }} !inline-flex !items-center !gap-1.5 !shrink-0 !py-2 !text-[11px]"
                                        >
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 text-brand-ink/80" wire:loading.remove wire:target="openSystemdActionConfirm" aria-hidden="true" />
                                            <span wire:loading wire:target="openSystemdActionConfirm" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                                            <span wire:loading.remove wire:target="openSystemdActionConfirm">{{ __('Restart') }}</span>
                                            <span wire:loading wire:target="openSystemdActionConfirm">{{ __('Working…') }}</span>
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            @click="fireRowLoading()"
                                            wire:click="openSystemdStatusModalForService(@js($rowUnit))"
                                            wire:loading.attr="disabled"
                                            wire:target="openSystemdStatusModalForService"
                                            @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || $otherBusy)
                                            class="{{ $btnSecondary }} !inline-flex !items-center !gap-1.5 !shrink-0 !py-2 !text-[11px]"
                                        >
                                            <x-heroicon-o-eye class="h-3.5 w-3.5 shrink-0 text-brand-ink/80" wire:loading.remove wire:target="openSystemdStatusModalForService" aria-hidden="true" />
                                            <span wire:loading wire:target="openSystemdStatusModalForService" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                                            <span wire:loading.remove wire:target="openSystemdStatusModalForService">{{ __('Status') }}</span>
                                            <span wire:loading wire:target="openSystemdStatusModalForService">{{ __('Working…') }}</span>
                                        </button>
                                    @endif
                                    <div class="shrink-0">
                                        <x-dropdown align="right" width="w-56" contentClasses="py-1.5">
                                            <x-slot name="trigger">
                                                <button
                                                    type="button"
                                                    class="{{ $btnSecondary }} !inline-flex !shrink-0 !items-center !gap-1 !px-2 !py-2 !text-[11px]"
                                                    aria-label="{{ __('More actions') }}"
                                                    aria-haspopup="true"
                                                    @disabled($otherBusy || $rowBusy || $rowPending)
                                                >
                                                    <x-heroicon-o-ellipsis-horizontal class="h-4 w-4 shrink-0 text-brand-ink/80" />
                                                </button>
                                            </x-slot>
                                            <x-slot name="content">
                                                @php
                                                    // Each menu item: icon on the left, label,
                                                    // and a tiny spinner that swaps in for the
                                                    // icon while the wire:click round-trip is in
                                                    // flight. The row-level "Working…" pill at
                                                    // the top of the actions cell handles the
                                                    // long-running SSH portion that fires after
                                                    // a confirm modal closes.
                                                    $menuItem = 'flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50';
                                                    $menuItemDanger = 'flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50';
                                                    $iconClasses = 'h-4 w-4 shrink-0 text-brand-ink/70';
                                                    $iconClassesDanger = 'h-4 w-4 shrink-0 text-red-600';
                                                    $spinnerClasses = 'inline-block h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink';
                                                @endphp
                                                @if ($mayMutate && $isActive)
                                                    {{-- Status came off the row when manageable + active.
                                                         Surfaced here so it's still one click away. --}}
                                                    <button
                                                        type="button"
                                                        @click="fireRowLoading()"
                                                        wire:click="openSystemdStatusModalForService(@js($rowUnit))"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openSystemdStatusModalForService(@js($rowUnit))"
                                                        class="{{ $menuItem }}"
                                                    >
                                                        <span wire:loading.remove wire:target="openSystemdStatusModalForService(@js($rowUnit))">
                                                            <x-heroicon-o-eye class="{{ $iconClasses }}" aria-hidden="true" />
                                                        </span>
                                                        <span wire:loading wire:target="openSystemdStatusModalForService(@js($rowUnit))" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                        {{ __('Status') }}
                                                    </button>
                                                @endif
                                                @if ($canManage)
                                                    {{-- Journal logs (`journalctl -u <unit>`). Available
                                                         to anyone who can see the unit, including
                                                         view-only and status-only roles, since logs
                                                         are read-only. --}}
                                                    <button
                                                        type="button"
                                                        @click="fireRowLoading()"
                                                        wire:click="openSystemdLogsModalForService(@js($rowUnit))"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openSystemdLogsModalForService(@js($rowUnit))"
                                                        class="{{ $menuItem }}"
                                                    >
                                                        <span wire:loading.remove wire:target="openSystemdLogsModalForService(@js($rowUnit))">
                                                            <x-heroicon-o-document-text class="{{ $iconClasses }}" aria-hidden="true" />
                                                        </span>
                                                        <span wire:loading wire:target="openSystemdLogsModalForService(@js($rowUnit))" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                        {{ __('Logs') }}
                                                    </button>
                                                @endif
                                                @if ($mayMutate)
                                                    <button
                                                        type="button"
                                                        @click="fireRowLoading()"
                                                        wire:click="openSystemdActionConfirm('stop', @js($rowUnit))"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openSystemdActionConfirm"
                                                        @disabled(! $opsReady || $otherBusy || $rowBusy || $rowPending)
                                                        class="{{ $menuItemDanger }}"
                                                    >
                                                        <x-heroicon-o-stop-circle class="{{ $iconClassesDanger }}" wire:loading.remove wire:target="openSystemdActionConfirm" aria-hidden="true" />
                                                        <span wire:loading wire:target="openSystemdActionConfirm" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                        {{ __('Stop') }}
                                                    </button>
                                                @endif
                                                    @if ($rowManageExtras)
                                                        <button
                                                            type="button"
                                                            @click="fireRowLoading()"
                                                            wire:click="openSystemdActionConfirm('reload', @js($rowUnit))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="openSystemdActionConfirm"
                                                            class="{{ $menuItem }}"
                                                        >
                                                            <x-heroicon-o-arrow-path class="{{ $iconClasses }}" wire:loading.remove wire:target="openSystemdActionConfirm" aria-hidden="true" />
                                                            <span wire:loading wire:target="openSystemdActionConfirm" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                            {{ __('Reload') }}
                                                        </button>
                                                    @endif
                                                    @if ($hasBootMenu)
                                                        @if ($showBootEnable)
                                                            <button
                                                                type="button"
                                                                @click="fireRowLoading()"
                                                                wire:click="openSystemdActionConfirm('enable', @js($rowUnit))"
                                                                wire:loading.attr="disabled"
                                                                wire:target="openSystemdActionConfirm"
                                                                class="{{ $menuItem }}"
                                                            >
                                                                <x-heroicon-o-bolt class="{{ $iconClasses }}" wire:loading.remove wire:target="openSystemdActionConfirm" aria-hidden="true" />
                                                                <span wire:loading wire:target="openSystemdActionConfirm" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                                {{ __('Enable at boot') }}
                                                            </button>
                                                        @endif
                                                        @if ($showBootDisable)
                                                            <button
                                                                type="button"
                                                                @click="fireRowLoading()"
                                                                wire:click="openSystemdActionConfirm('disable', @js($rowUnit))"
                                                                wire:loading.attr="disabled"
                                                                wire:target="openSystemdActionConfirm"
                                                                class="{{ $menuItem }}"
                                                            >
                                                                <x-heroicon-o-no-symbol class="{{ $iconClasses }}" wire:loading.remove wire:target="openSystemdActionConfirm" aria-hidden="true" />
                                                                <span wire:loading wire:target="openSystemdActionConfirm" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                                {{ __('Disable at boot') }}
                                                            </button>
                                                        @endif
                                                    @endif
                                                    @if ($rowAlerts)
                                                        @if ($rowManageExtras || $hasBootMenu)
                                                            <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                                        @endif
                                                        <button
                                                            type="button"
                                                            @click="fireRowLoading()"
                                                            wire:click="openSystemdNotifyModalForService(@js($rowUnit))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="openSystemdNotifyModalForService(@js($rowUnit))"
                                                            class="{{ $menuItem }}"
                                                        >
                                                            <span wire:loading.remove wire:target="openSystemdNotifyModalForService(@js($rowUnit))">
                                                                <x-heroicon-o-bell class="{{ $iconClasses }}" aria-hidden="true" />
                                                            </span>
                                                            <span wire:loading wire:target="openSystemdNotifyModalForService(@js($rowUnit))" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                            {{ __('Notify') }}
                                                            @if (($row['alert_subscription_count'] ?? 0) > 0)
                                                                <span class="ml-auto rounded-full bg-brand-sand px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-brand-ink">{{ (int) ($row['alert_subscription_count'] ?? 0) }}</span>
                                                            @endif
                                                        </button>
                                                    @endif
                                                    @if (! empty($row['custom']))
                                                        <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                                        <button
                                                            type="button"
                                                            @click="fireRowLoading()"
                                                            wire:click="openSystemdActionConfirm('remove-custom', @js($rowUnit))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="openSystemdActionConfirm"
                                                            @disabled($isDeployer)
                                                            class="{{ $menuItemDanger }}"
                                                        >
                                                            <x-heroicon-o-trash class="{{ $iconClassesDanger }}" wire:loading.remove wire:target="openSystemdActionConfirm" aria-hidden="true" />
                                                            <span wire:loading wire:target="openSystemdActionConfirm" class="{{ $spinnerClasses }}" aria-hidden="true"></span>
                                                            {{ __('Remove custom unit') }}
                                                        </button>
                                                    @endif
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-8 text-center text-sm text-brand-moss">
                                @if (($filteredSystemdInventory ?? null) !== null && count($systemdInventory ?? []) > 0)
                                    {{ __('No services match the current filters.') }}
                                @else
                                    {{ __('No services recorded yet. A scheduled sync will populate this list, or choose Sync now to queue an immediate run.') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                    @if ($systemHiddenCount > 0 || $systemdShowSystem)
                        <tr>
                            <td colspan="5" class="p-3 text-center text-xs text-brand-moss">
                                <button
                                    type="button"
                                    wire:click="toggleSystemdShowSystem"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleSystemdShowSystem"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <span wire:loading wire:target="toggleSystemdShowSystem" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                                    @if ($systemdShowSystem)
                                        <x-heroicon-o-chevron-up class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="toggleSystemdShowSystem" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="toggleSystemdShowSystem" aria-hidden="true" />
                                    @endif
                                    <span wire:loading.remove wire:target="toggleSystemdShowSystem">
                                        @if ($systemdShowSystem)
                                            {{ __('Hide system services') }}
                                        @else
                                            {{ trans_choice('Show :count system service|Show :count system services', $systemHiddenCount, ['count' => $systemHiddenCount]) }}
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="toggleSystemdShowSystem">{{ __('Working…') }}</span>
                                </button>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="services-panel-activity"
            labelled-by="services-tab-activity"
            :hidden="$services_workspace_tab !== 'activity'"
            panel-class="space-y-6"
        >
            @php
                $activityCount = count($systemdServiceActivity ?? []);
                $latestActivityRel = null;
                if ($activityCount > 0) {
                    try {
                        $latestActivityRel = \Illuminate\Support\Carbon::parse($systemdServiceActivity[0]['at'] ?? null)
                            ->timezone(config('app.timezone'))
                            ->diffForHumans();
                    } catch (\Throwable) {
                        $latestActivityRel = null;
                    }
                }
            @endphp
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-clock class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Service activity') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Started, stopped, restarted, and state-change events Dply observed between inventory snapshots.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                {{ trans_choice('{0} no events recorded|{1} :count event recorded|[2,*] :count events recorded', $activityCount, ['count' => $activityCount]) }}
                            </span>
                            @if ($latestActivityRel)
                                <span class="text-brand-mist/60">·</span>
                                <span>{{ __('latest :time', ['time' => $latestActivityRel]) }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($activityCount > 0)
                    <ul class="mt-6 space-y-2">
                        @foreach ($systemdServiceActivity as $ev)
                            @php
                                $kind = (string) ($ev['kind'] ?? '');
                                $kindLabel = match ($kind) {
                                    'started' => __('Started'),
                                    'stopped' => __('Stopped'),
                                    'restarted' => __('Restarted'),
                                    'state_changed' => __('State change'),
                                    default => $kind,
                                };
                                $atEv = $ev['at'] ?? '';
                                $atRel = null;
                                if ($atEv !== '') {
                                    try {
                                        $atRel = \Carbon\Carbon::parse($atEv)->timezone(config('app.timezone'))->diffForHumans();
                                    } catch (\Throwable) {
                                        $atRel = null;
                                    }
                                }
                                $iconCls = match ($kind) {
                                    'stopped' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                    'started' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    'restarted' => 'bg-amber-50 text-amber-800 ring-amber-200',
                                    default => 'bg-brand-sand/40 text-brand-moss ring-brand-ink/10',
                                };
                                $iconComponent = match ($kind) {
                                    'stopped' => 'heroicon-o-stop-circle',
                                    'started' => 'heroicon-o-play-circle',
                                    'restarted' => 'heroicon-o-arrow-path',
                                    default => 'heroicon-o-bolt',
                                };
                            @endphp
                            <li class="flex flex-wrap items-start gap-x-3 gap-y-1 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 text-sm">
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-1 {{ $iconCls }}">
                                    <x-dynamic-component :component="$iconComponent" class="h-3.5 w-3.5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-semibold text-brand-ink">{{ $kindLabel }}</span>
                                        <span class="font-mono text-xs text-brand-moss">{{ $ev['label'] ?? $ev['unit'] ?? '' }}</span>
                                        @if ($atRel)
                                            <span class="ml-auto text-[11px] text-brand-mist" title="{{ $atEv }}">{{ $atRel }}</span>
                                        @endif
                                    </div>
                                    @if (! empty($ev['detail']))
                                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ $ev['detail'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="mt-6 flex flex-col items-center gap-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <p class="text-sm font-medium text-brand-ink">{{ __('No service activity yet.') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Start, stop, or restart a unit and the events will show up here as Dply detects them.') }}</p>
                    </div>
                @endif
            </div>

            @livewire(\App\Livewire\Servers\RecentActionsLog::class, ['server' => $server], key('recent-actions-log-'.$server->id))
        </x-server-workspace-tab-panel>

        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @if ($showSystemdActionConfirm)
            @php
                $kind = $systemdActionConfirmKind;
                $unit = $systemdActionConfirmUnit;
                $isBulk = str_starts_with($kind, 'bulk-');
                $bulkCount = $isBulk ? count($systemdSelectedList ?? []) : 0;
                $config = match ($kind) {
                    'start' => [
                        'title' => __('Start service'),
                        'description' => __('Starts the unit via systemctl on the server.'),
                        'icon' => 'heroicon-o-play-circle',
                        'tone' => 'emerald',
                        'confirm' => __('Start service'),
                        'destructive' => false,
                    ],
                    'restart' => [
                        'title' => __('Restart service'),
                        'description' => __('Stops then starts the unit. Connections may briefly drop while the service comes back up.'),
                        'icon' => 'heroicon-o-arrow-path',
                        'tone' => 'amber',
                        'confirm' => __('Restart service'),
                        'destructive' => false,
                    ],
                    'stop' => [
                        'title' => __('Stop service'),
                        'description' => __('Stops the unit immediately. Anything depending on it will lose its connection until you start it again.'),
                        'icon' => 'heroicon-o-stop-circle',
                        'tone' => 'rose',
                        'confirm' => __('Stop service'),
                        'destructive' => true,
                    ],
                    'reload' => [
                        'title' => __('Reload service'),
                        'description' => __('Reapplies the unit\'s configuration without a full restart, when the unit supports reload.'),
                        'icon' => 'heroicon-o-arrow-path-rounded-square',
                        'tone' => 'sky',
                        'confirm' => __('Reload service'),
                        'destructive' => false,
                    ],
                    'enable' => [
                        'title' => __('Enable at boot'),
                        'description' => __('Marks the unit to start automatically when the server boots. Does not start it now.'),
                        'icon' => 'heroicon-o-bolt',
                        'tone' => 'emerald',
                        'confirm' => __('Enable at boot'),
                        'destructive' => false,
                    ],
                    'disable' => [
                        'title' => __('Disable at boot'),
                        'description' => __('Removes the boot-time auto-start. The unit may keep running until you stop it.'),
                        'icon' => 'heroicon-o-no-symbol',
                        'tone' => 'rose',
                        'confirm' => __('Disable at boot'),
                        'destructive' => true,
                    ],
                    'bulk-restart' => [
                        'title' => __('Restart selected services'),
                        'description' => __('Each unit is restarted in sequence. Connections to those units may briefly drop while they come back up.'),
                        'icon' => 'heroicon-o-arrow-path',
                        'tone' => 'amber',
                        'confirm' => trans_choice('Restart :count service|Restart :count services', $bulkCount, ['count' => $bulkCount]),
                        'destructive' => false,
                    ],
                    'bulk-stop' => [
                        'title' => __('Stop selected services'),
                        'description' => __('Each unit is stopped in sequence. Anything depending on them loses its connection until you start them again.'),
                        'icon' => 'heroicon-o-stop-circle',
                        'tone' => 'rose',
                        'confirm' => trans_choice('Stop :count service|Stop :count services', $bulkCount, ['count' => $bulkCount]),
                        'destructive' => true,
                    ],
                    'remove-custom' => [
                        'title' => __('Remove custom unit'),
                        'description' => __('Removes this unit from Dply\'s custom-services allowlist. The unit itself stays on the server; only Dply\'s tracking is dropped.'),
                        'icon' => 'heroicon-o-trash',
                        'tone' => 'rose',
                        'confirm' => __('Remove from list'),
                        'destructive' => true,
                    ],
                    default => [
                        'title' => __('Confirm action'),
                        'description' => '',
                        'icon' => 'heroicon-o-question-mark-circle',
                        'tone' => 'sand',
                        'confirm' => __('Confirm'),
                        'destructive' => false,
                    ],
                };
                $iconRing = match ($config['tone']) {
                    'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                    'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
                    'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
                    'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
                    default => 'bg-brand-sand/40 text-brand-moss ring-brand-ink/10',
                };
                $confirmBtn = $config['destructive']
                    ? 'inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-white shadow-sm hover:bg-rose-700 transition-colors disabled:cursor-not-allowed disabled:opacity-50'
                    : 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
                $cancelBtn = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
            @endphp
            @teleport('body')
                <div
                    class="fixed inset-0 isolate z-[100] overflow-y-auto"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="systemd-action-confirm-title"
                    x-data="{ close() { $wire.closeSystemdActionConfirm() } }"
                    x-init="setTimeout(() => $refs.confirm?.focus(), 50)"
                    x-on:keydown.escape.window="close()"
                >
                    <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="close()"></div>
                    <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                        <div class="relative w-full max-w-lg dply-modal-panel">
                            <div class="flex items-start gap-3 px-6 py-5 sm:px-7">
                                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ring-1 {{ $iconRing }}">
                                    <x-dynamic-component :component="$config['icon']" class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <h2 id="systemd-action-confirm-title" class="text-base font-semibold text-brand-ink">{{ $config['title'] }}</h2>
                                    @if ($isBulk)
                                        <p class="mt-1 text-xs font-medium text-brand-moss">
                                            {{ trans_choice('{0} no units selected|{1} :count selected unit|[2,*] :count selected units', $bulkCount, ['count' => $bulkCount]) }}
                                        </p>
                                    @elseif ($unit !== '')
                                        <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-2 py-0.5 font-mono text-[11px] text-brand-ink ring-1 ring-brand-ink/10">{{ $unit }}</p>
                                    @endif
                                </div>
                            </div>

                            @php
                                $confirmRow = $this->systemdActionConfirmRow();
                            @endphp
                            @if ($confirmRow !== null)
                                @php
                                    $rowActive = (string) ($confirmRow['active'] ?? '');
                                    $rowSub = (string) ($confirmRow['sub'] ?? '');
                                    $rowFailed = ! empty($confirmRow['is_failed']);
                                    $rowPid = trim((string) ($confirmRow['main_pid'] ?? ''));
                                    $rowBoot = trim((string) ($confirmRow['boot_state'] ?? ''));
                                    $rowVersion = trim((string) ($confirmRow['version'] ?? ''));
                                    $rowTs = (string) ($confirmRow['ts'] ?? '');
                                    $rowTsRel = null;
                                    $rowTsCompact = null;
                                    if ($rowTs !== '' && $rowTs !== 'n/a') {
                                        try {
                                            $rowTsRel = \Carbon\Carbon::parse($rowTs)->diffForHumans();
                                            $rowTsCompact = \Carbon\Carbon::parse($rowTs)->format('M j, H:i');
                                        } catch (\Throwable) {
                                            $rowTsRel = null;
                                            $rowTsCompact = null;
                                        }
                                    }
                                    $stateBadge = match (true) {
                                        $rowFailed => 'bg-rose-50 text-rose-800 ring-rose-200',
                                        $rowActive === 'active' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
                                        $rowActive === 'activating', $rowActive === 'reloading' => 'bg-amber-50 text-amber-900 ring-amber-200',
                                        default => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
                                    };
                                    $stateLabel = $rowFailed ? __('Failed') : ($rowActive !== '' ? ucfirst($rowActive) : __('Unknown'));
                                @endphp
                                <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                                    <p class="mb-3 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Current state') }}</p>
                                    <div class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Active') }}</p>
                                            <p class="mt-1">
                                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $stateBadge }}">
                                                    {{ $stateLabel }}@if ($rowSub !== '' && $rowSub !== $rowActive) <span class="font-normal">/ {{ $rowSub }}</span>@endif
                                                </span>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('PID') }}</p>
                                            <p class="mt-1 font-mono text-[11px] text-brand-ink">{{ $rowPid !== '' && $rowPid !== '0' ? $rowPid : '—' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Boot') }}</p>
                                            <p class="mt-1 font-mono text-[11px] text-brand-ink">{{ $rowBoot !== '' ? $rowBoot : '—' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Last change') }}</p>
                                            <p class="mt-1 text-[11px] text-brand-ink" title="{{ $rowTs }}">
                                                @if ($rowTsCompact)
                                                    {{ $rowTsCompact }}
                                                    @if ($rowTsRel)
                                                        <span class="text-brand-mist">· {{ $rowTsRel }}</span>
                                                    @endif
                                                @else
                                                    —
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                    @if ($rowVersion !== '')
                                        <p class="mt-3 text-[11px] text-brand-mist">{{ __('Version') }}: <span class="font-mono text-brand-moss">{{ $rowVersion }}</span></p>
                                    @endif
                                </div>
                            @endif

                            <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-7">
                                <p class="text-sm leading-relaxed text-brand-moss">{{ $config['description'] }}</p>
                                <ul class="mt-3 space-y-1 text-[11px] text-brand-mist">
                                    <li class="inline-flex items-center gap-1.5">
                                        <x-heroicon-m-command-line class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                        {{ __('Runs as root over SSH on :host', ['host' => $server->getSshConnectionString()]) }}
                                    </li>
                                    @if (! in_array($kind, ['remove-custom'], true))
                                        <li class="inline-flex items-center gap-1.5">
                                            <x-heroicon-m-arrow-path class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" />
                                            {{ __('Inventory refreshes once the action completes') }}
                                        </li>
                                    @endif
                                </ul>
                            </div>

                            @php $previewCommand = $this->systemdActionConfirmCommand(); @endphp
                            @if ($previewCommand !== null)
                                <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Console — what will run') }}</p>
                                        <span class="inline-flex items-center gap-1 text-[10px] text-brand-mist">
                                            <x-heroicon-m-arrow-down-on-square class="h-3 w-3" aria-hidden="true" />
                                            ssh
                                        </span>
                                    </div>
                                    <pre class="overflow-x-auto rounded-lg border border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100"><code>{{ $previewCommand }}</code></pre>
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                                <button type="button" x-on:click="close()" class="{{ $cancelBtn }}">
                                    {{ __('Cancel') }}
                                </button>
                                <button
                                    type="button"
                                    x-ref="confirm"
                                    wire:click="confirmSystemdAction"
                                    wire:loading.attr="disabled"
                                    class="{{ $confirmBtn }}"
                                >
                                    <x-dynamic-component :component="$config['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" wire:loading.remove wire:target="confirmSystemdAction" />
                                    <span wire:loading wire:target="confirmSystemdAction" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-current/40 border-t-current" aria-hidden="true"></span>
                                    <span wire:loading.remove wire:target="confirmSystemdAction">{{ $config['confirm'] }}</span>
                                    <span wire:loading wire:target="confirmSystemdAction">{{ __('Working…') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endteleport
        @endif
        @if ($showCustomSystemdModal)
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="custom-systemd-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeCustomSystemdModal"></div>
                <div class="relative z-10 w-full max-w-lg rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-xl">
                    <h2 id="custom-systemd-heading" class="text-lg font-semibold text-brand-ink">{{ __('Custom systemd units') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Add unit names (for example mysql or myapp-worker). They are allowlisted for inventory and actions on this server only. Use full names like mysql.service or short names — we normalize to .service.') }}
                    </p>
                    <div class="mt-4 flex gap-2">
                        <input
                            type="text"
                            wire:model="newCustomSystemdUnit"
                            wire:keydown.enter.prevent="addCustomSystemdUnit"
                            placeholder="{{ __('e.g. mysql or redis-server') }}"
                            class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        />
                        <button
                            type="button"
                            wire:click="addCustomSystemdUnit"
                            wire:loading.attr="disabled"
                            wire:target="addCustomSystemdUnit"
                            @disabled($isDeployer)
                            class="{{ $btnPrimary }} shrink-0"
                        >
                            <span wire:loading wire:target="addCustomSystemdUnit" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-cream/40 border-t-brand-cream" aria-hidden="true"></span>
                            <span wire:loading.remove wire:target="addCustomSystemdUnit">{{ __('Add') }}</span>
                            <span wire:loading wire:target="addCustomSystemdUnit">{{ __('Working…') }}</span>
                        </button>
                    </div>
                    @if ($customMetaList !== [])
                        <ul class="mt-4 max-h-48 space-y-2 overflow-y-auto rounded-xl border border-brand-ink/10 p-3 text-sm">
                            @foreach ($customMetaList as $cu)
                                <li class="flex items-center justify-between gap-2 font-mono text-xs text-brand-ink">
                                    <span>{{ $cu }}</span>
                                    <button
                                        type="button"
                                        wire:click="openSystemdActionConfirm('remove-custom', @js($cu))"
                                        wire:loading.attr="disabled"
                                        wire:target="openSystemdActionConfirm"
                                        class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-red-700 hover:text-red-900 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span wire:loading wire:target="openSystemdActionConfirm" class="inline-flex h-3 w-3 shrink-0 animate-spin rounded-full border-2 border-red-300 border-t-red-700" aria-hidden="true"></span>
                                        <span wire:loading.remove wire:target="openSystemdActionConfirm">{{ __('Remove') }}</span>
                                        <span wire:loading wire:target="openSystemdActionConfirm">{{ __('Working…') }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 text-sm text-brand-moss">{{ __('No custom units yet.') }}</p>
                    @endif
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" wire:click="closeCustomSystemdModal" class="{{ $btnSecondary }}">
                            {{ __('Done') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif
        @if ($showSystemdStatusModal)
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="systemd-status-modal-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeSystemdStatusModal"></div>
                <div class="relative z-10 max-h-[min(92vh,52rem)] w-full max-w-[min(96vw,72rem)] overflow-y-auto overscroll-contain dply-modal-panel [-webkit-overflow-scrolling:touch]">
                    <div class="sticky top-0 z-[1] flex flex-col gap-3 border-b border-brand-ink/10 bg-white px-4 py-4 sm:px-6 sm:py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                                    @if ($systemdStatusModalView === 'logs')
                                        <x-heroicon-o-document-text class="h-4 w-4" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-eye class="h-4 w-4" aria-hidden="true" />
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <h2 id="systemd-status-modal-heading" class="text-base font-semibold text-brand-ink">
                                        {{ $systemdStatusModalView === 'logs' ? __('Service logs') : __('Service status') }}
                                    </h2>
                                    <p class="mt-0.5 font-mono text-xs text-brand-moss break-all">{{ $systemdStatusModalUnit }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    wire:click="fetchSystemdModalStatus"
                                    wire:loading.attr="disabled"
                                    wire:target="fetchSystemdModalStatus"
                                    @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || $systemdStatusModalLoading)
                                    class="{{ $btnSecondary }} !inline-flex !items-center !gap-1.5 !py-2 !text-[11px]"
                                >
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 text-brand-ink/80" wire:loading.class="animate-spin" wire:target="fetchSystemdModalStatus" />
                                    <span wire:loading.remove wire:target="fetchSystemdModalStatus">{{ __('Refresh') }}</span>
                                    <span wire:loading wire:target="fetchSystemdModalStatus">{{ __('Working…') }}</span>
                                </button>
                                <button type="button" wire:click="closeSystemdStatusModal" class="{{ $btnSecondary }} !py-2 !text-[11px]">
                                    {{ __('Close') }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="px-4 py-4 sm:px-6 sm:py-5">
                        @if ($systemdStatusModalLoading)
                            <p class="text-xs font-medium text-brand-ink">{{ $systemdStatusModalView === 'logs' ? __('Fetching journalctl logs…') : __('Fetching systemctl status…') }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('This can take a few seconds over SSH.') }}</p>
                        @endif
                        @if ($systemdStatusModalError)
                            <div class="mb-3 rounded-lg border border-red-200/80 bg-red-50/90 px-3 py-2 text-xs text-red-900 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $systemdStatusModalError }}</div>
                        @endif
                        @if ($systemdStatusModalOutput !== '')
                            @php
                                // Lightweight syntax-highlighter for the
                                // `systemctl status` text dump. Escape
                                // the buffer first, then layer span
                                // classes on top so the whole thing
                                // remains safe to render with {!! !!}.
                                $statusOut = e($systemdStatusModalOutput);

                                // The leading state bullet ("● unit"
                                // active, "×" failed) — color the dot
                                // before the unit name.
                                $statusOut = preg_replace_callback(
                                    '/^(\s*)([●×])(\s+\S+)/m',
                                    function (array $m): string {
                                        $color = $m[2] === '×' ? 'text-red-500' : 'text-emerald-500';

                                        return $m[1].'<span class="'.$color.' font-semibold">'.$m[2].'</span>'.$m[3];
                                    },
                                    $statusOut
                                ) ?? $statusOut;

                                // State words inside parens — "(running)"
                                // green, "(dead)"/"(failed)" red, others
                                // amber for transitions.
                                $statusOut = preg_replace_callback(
                                    '/\b(active)\s+\((running|listening|exited|waiting)\)/i',
                                    fn (array $m): string => '<span class="text-emerald-700 font-semibold">'.$m[0].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/\b(inactive|failed)\s+\(([^)]+)\)/i',
                                    fn (array $m): string => '<span class="text-red-700 font-semibold">'.$m[0].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/\b(activating|deactivating|reloading)\s+\(([^)]+)\)/i',
                                    fn (array $m): string => '<span class="text-amber-700 font-semibold">'.$m[0].'</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // "enabled" / "disabled" / "static" /
                                // "masked" descriptors after Loaded:
                                $statusOut = preg_replace_callback(
                                    '/(;\s*)(enabled|enabled-runtime|alias|generated|indirect)\b/',
                                    fn (array $m): string => $m[1].'<span class="text-emerald-700">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/(;\s*)(disabled|masked|bad)\b/',
                                    fn (array $m): string => $m[1].'<span class="text-red-700">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                                $statusOut = preg_replace_callback(
                                    '/(;\s*)(static|preset:\s*\S+)/',
                                    fn (array $m): string => $m[1].'<span class="text-zinc-500">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // Field labels at the start of a line:
                                // Loaded, Active, Main PID, Tasks, Memory,
                                // CPU, CGroup, Docs, Status, etc.
                                $statusOut = preg_replace_callback(
                                    '/^(\s*)(Loaded|Active|Main PID|Tasks|Memory|CPU|CGroup|Docs|Status|Process|TriggeredBy|IP|IO|Drop-In):/m',
                                    fn (array $m): string => $m[1].'<span class="text-zinc-500">'.$m[2].':</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // WARNING / ERROR severity tags in journal
                                // tail lines.
                                $statusOut = preg_replace_callback(
                                    '/\b(WARNING|ERROR|CRITICAL|FATAL)\b/',
                                    fn (array $m): string => '<span class="font-semibold '.match (strtoupper($m[1])) {
                                        'WARNING' => 'text-amber-700',
                                        default => 'text-red-700',
                                    }.'">'.$m[1].'</span>',
                                    $statusOut
                                ) ?? $statusOut;

                                // Mute timestamps prefixing journal lines:
                                // "May 04 19:28:09 silver-stag …".
                                $statusOut = preg_replace_callback(
                                    '/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})(\s+\S+)/m',
                                    fn (array $m): string => '<span class="text-zinc-400">'.$m[1].'</span><span class="text-zinc-500">'.$m[2].'</span>',
                                    $statusOut
                                ) ?? $statusOut;
                            @endphp
                            <div
                                x-data="{
                                    copied: false,
                                    async copy() {
                                        try {
                                            await navigator.clipboard.writeText(this.$refs.statusRaw.textContent);
                                            this.copied = true;
                                            setTimeout(() => { this.copied = false; }, 1500);
                                        } catch (e) { /* clipboard blocked — silent */ }
                                    },
                                }"
                                class="rounded-xl border border-brand-ink/15 bg-zinc-50 p-3 shadow-inner"
                            >
                                <div class="mb-2 flex items-center justify-between gap-3">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink">{{ $systemdStatusModalView === 'logs' ? __('journalctl -u') : __('systemctl status') }}</p>
                                    <button
                                        type="button"
                                        @click="copy()"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <template x-if="copied">
                                            <span class="inline-flex items-center gap-1 text-emerald-700">
                                                <x-heroicon-o-check class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Copied') }}
                                            </span>
                                        </template>
                                        <template x-if="! copied">
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-o-clipboard class="h-3 w-3 shrink-0 text-brand-ink/70" aria-hidden="true" />
                                                {{ __('Copy') }}
                                            </span>
                                        </template>
                                    </button>
                                </div>
                                {{-- Hidden raw copy target keeps the copied
                                     payload free of the colorization spans
                                     while the visible <pre> renders the
                                     highlighted version. --}}
                                <pre x-ref="statusRaw" class="hidden">{{ $systemdStatusModalOutput }}</pre>
                                <pre class="font-mono text-[11px] leading-snug whitespace-pre-wrap break-words text-zinc-900 [overflow-wrap:anywhere]">{!! $statusOut !!}</pre>
                            </div>
                        @elseif (! $systemdStatusModalLoading && $systemdStatusModalError === null)
                            <p class="text-xs text-brand-moss">{{ __('No output yet. Choose Refresh status.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        @if ($showSystemdNotifyModal)
            @php
                $systemdKindLabels = \App\Support\ServerSystemdServiceNotificationKeys::kindLabels();
            @endphp
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="systemd-notify-modal-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeSystemdNotifyModal"></div>
                <div class="relative z-10 max-h-[min(92vh,42rem)] w-full max-w-[min(96vw,48rem)] overflow-y-auto overscroll-contain dply-modal-panel">
                    <div class="border-b border-brand-ink/10 px-4 py-4 sm:px-6 sm:py-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="systemd-notify-modal-heading" class="text-base font-semibold text-brand-ink">{{ __('Notify on service changes') }}</h2>
                                <p class="mt-0.5 font-mono text-xs text-brand-moss break-all">{{ $systemdNotifyUnit }}</p>
                            </div>
                            <button type="button" wire:click="closeSystemdNotifyModal" class="{{ $btnSecondary }} !py-2 !text-[11px]">
                                {{ __('Close') }}
                            </button>
                        </div>
                    </div>
                    <div class="px-4 py-4 sm:px-6 sm:py-5">
                        <p class="text-xs text-brand-moss leading-snug">
                            {{ __('When background inventory detects a change, notify the channels you choose. Tick the events you care about per channel, then save.') }}
                        </p>
                        <p class="mt-2 text-[11px] text-brand-moss leading-snug">
                            {{ __('Tip: “Restarted” can be noisy during deploys; “Stopped” and “State change” are usually enough.') }}
                        </p>
                        @if ($systemdNotifyChannelRows === [])
                            <p class="mt-3 text-xs text-brand-moss">{{ __('No notification channels are available to your account in this organization yet.') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($server->organization_id)
                                    <a
                                        href="{{ route('organizations.notification-channels', $server->organization_id) }}"
                                        wire:navigate
                                        class="{{ $btnPrimary }} !py-2 !text-[11px]"
                                    >
                                        {{ __('Add organization channels') }}
                                    </a>
                                @endif
                                <a href="{{ route('profile.notification-channels') }}" wire:navigate class="{{ $btnSecondary }} !py-2 !text-[11px]">
                                    {{ __('My notification channels') }}
                                </a>
                            </div>
                        @else
                            <div class="mt-3 overflow-x-auto rounded-xl border border-brand-ink/10">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="bg-brand-sand/40 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                        <tr>
                                            <th class="px-2 py-2">{{ __('Channel') }}</th>
                                            @foreach ($systemdKindLabels as $kind => $_label)
                                                <th class="px-2 py-2 text-center">{{ $_label }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/10 bg-white">
                                        @foreach ($systemdNotifyChannelRows as $chRow)
                                            <tr wire:key="svc-notify-{{ $chRow['id'] }}">
                                                <td class="px-2 py-2 font-medium text-brand-ink">{{ $chRow['label'] }}</td>
                                                @foreach ($systemdKindLabels as $kind => $_label)
                                                    <td class="px-2 py-2 text-center align-middle">
                                                        <input
                                                            type="checkbox"
                                                            class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                                                            wire:model.live="systemdNotifyMatrix.{{ $chRow['id'] }}.{{ $kind }}"
                                                            @disabled($isDeployer)
                                                        />
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3 flex flex-wrap justify-end gap-2">
                                <button
                                    type="button"
                                    wire:click="saveSystemdNotifyPreferences"
                                    wire:loading.attr="disabled"
                                    wire:target="saveSystemdNotifyPreferences"
                                    @disabled($isDeployer)
                                    class="{{ $btnPrimary }} !py-2 !text-[11px]"
                                >
                                    <span wire:loading wire:target="saveSystemdNotifyPreferences" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-cream/40 border-t-brand-cream" aria-hidden="true"></span>
                                    <span wire:loading.remove wire:target="saveSystemdNotifyPreferences">{{ __('Save alert routing') }}</span>
                                    <span wire:loading wire:target="saveSystemdNotifyPreferences">{{ __('Working…') }}</span>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
