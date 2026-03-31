@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
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
    <div wire:init="maybeRefreshSystemdInventoryOnLoad" class="hidden" aria-hidden="true"></div>
    @if ($opsReady && ! $showSystemdStatusModal)
        {{-- Avoid concurrent poll + modal SSH refresh (Livewire request overlap). --}}
        <div wire:poll.5s="refreshSystemdUiFromDatabase" class="hidden" aria-hidden="true"></div>
    @endif
    @include('livewire.servers.partials.workspace-flashes', ['command_output' => null, 'command_error' => $remote_error ?? null])
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

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

    <div class="{{ $card }} overflow-hidden">
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
                <button type="button" wire:click="openCustomSystemdModal" @disabled($isDeployer) class="{{ $btnSecondary }}">
                    <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0 opacity-90" />
                    {{ __('Custom services') }}
                </button>
                @if ($server->organization_id)
                    <a
                        href="{{ route('organizations.notification-channels', $server->organization_id) }}"
                        wire:navigate
                        class="{{ $btnSecondary }}"
                    >
                        <x-heroicon-o-bell class="h-4 w-4 shrink-0 opacity-90" />
                        {{ __('Notification channels') }}
                    </a>
                @endif
                <button
                    type="button"
                    wire:click="refreshSystemdInventory"
                    wire:loading.attr="disabled"
                    @disabled(! $opsReady || $isDeployer)
                    class="{{ $btnPrimary }}"
                >
                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 opacity-90" wire:loading.class="animate-spin" wire:target="refreshSystemdInventory" />
                    <span wire:loading.remove wire:target="refreshSystemdInventory">{{ __('Sync now') }}</span>
                    <span wire:loading wire:target="refreshSystemdInventory">{{ __('Queuing…') }}</span>
                </button>
            </div>
        </div>

        @php
            $syncMeta = $systemdSyncMeta ?? ['at' => null, 'status' => null, 'error' => null, 'duration_ms' => null];
        @endphp
        @if ($opsReady && ($syncMeta['status'] ?? null) === 'failed')
            <div class="flex flex-col gap-2 border-b border-red-200/80 bg-red-50/90 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                <div class="text-sm text-red-950">
                    <p class="font-semibold">{{ __('Last inventory sync failed') }}</p>
                    @if (! empty($syncMeta['error']))
                        <p class="mt-1 font-mono text-xs">{{ \Illuminate\Support\Str::limit($syncMeta['error'], 400) }}</p>
                    @endif
                </div>
                @if (! $isDeployer)
                    <button type="button" wire:click="retrySystemdInventorySyncFromBanner" wire:loading.attr="disabled" class="{{ $btnSecondary }} shrink-0 border-red-200 text-red-950">
                        {{ __('Retry sync') }}
                    </button>
                @endif
            </div>
        @elseif ($opsReady && ! empty($syncMeta['at']) && ($syncMeta['status'] ?? null) === 'success' && ($syncMeta['duration_ms'] ?? null) !== null)
            <p class="border-b border-brand-ink/5 px-6 py-2 text-[11px] text-brand-moss sm:px-8">
                {{ __('Last inventory job: success in :ms ms', ['ms' => (int) $syncMeta['duration_ms']]) }}
            </p>
        @endif

        @if (($systemdServiceActivity ?? []) !== [])
            <div class="border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-clock class="h-5 w-5 shrink-0 text-brand-forest" />
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Service activity') }}</h3>
                    <span class="text-xs text-brand-moss">{{ __('from inventory comparisons') }}</span>
                </div>
                <ul class="mt-3 max-h-48 space-y-2 overflow-y-auto text-sm">
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
                            $badge = match ($kind) {
                                'stopped' => 'bg-red-100 text-red-900 ring-red-200',
                                'started' => 'bg-emerald-100 text-emerald-900 ring-emerald-200',
                                'restarted' => 'bg-amber-100 text-amber-950 ring-amber-200',
                                default => 'bg-brand-sand text-brand-ink ring-brand-ink/10',
                            };
                        @endphp
                        <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-brand-ink/5 pb-2 last:border-0 last:pb-0">
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $badge }}">{{ $kindLabel }}</span>
                            <span class="font-mono text-xs text-brand-ink">{{ $ev['label'] ?? $ev['unit'] ?? '' }}</span>
                            @if (! empty($ev['detail']))
                                <span class="text-xs text-brand-moss">{{ $ev['detail'] }}</span>
                            @endif
                            @if ($atRel)
                                <span class="ml-auto text-xs text-brand-mist">{{ $atRel }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

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
                        wire:click="bulkSystemdRestart"
                        wire:confirm="{{ __('Restart all selected services?') }}"
                        @disabled($systemdBulkBusy || ($systemdRowBusyUnit !== null && $systemdRowBusyUnit !== ''))
                        class="{{ $btnSecondary }}"
                    >
                        {{ __('Restart selected') }}
                    </button>
                    <button
                        type="button"
                        wire:click="bulkSystemdStop"
                        wire:confirm="{{ __('Stop all selected services?') }}"
                        @disabled($systemdBulkBusy || ($systemdRowBusyUnit !== null && $systemdRowBusyUnit !== ''))
                        class="{{ $btnDanger }}"
                    >
                        {{ __('Stop selected') }}
                    </button>
                    @if ($server->organization_id && ! $isDeployer)
                        <button type="button" wire:click="openSystemdBulkNotifyModal" class="{{ $btnSecondary }}">
                            {{ __('Notify selected…') }}
                        </button>
                    @endif
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
            <div>
                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Alerts') }}</label>
                <select wire:model.live="systemdFilterNotify" class="mt-1 rounded-lg border border-brand-ink/15 px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30">
                    <option value="all">{{ __('All') }}</option>
                    <option value="subscribed">{{ __('Has subscriptions') }}</option>
                    <option value="none">{{ __('No subscriptions') }}</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-sm">
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
                        @endphp
                        <tr wire:key="systemd-svc-{{ $rowUnit }}" @class(['bg-red-50/40' => $isFailed])>
                            <td class="p-4 align-top">
                            <input
                                type="checkbox"
                                class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                                value="{{ $rowUnit }}"
                                wire:model.live="systemdSelectedList"
                                @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || ! $mayMutate)
                            />
                            </td>
                            <td class="p-0 align-top">
                                <span class="inline-block min-h-[3rem] w-1 rounded-full {{ $isFailed ? 'bg-red-500' : ($isActive ? 'bg-emerald-500' : 'bg-brand-mist/60') }}" title="{{ $row['active'] ?? '' }} / {{ $row['sub'] ?? '' }}"></span>
                            </td>
                            <td class="p-4 align-top">
                                <p class="font-medium text-brand-ink">{{ $nameLine }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    @if ($isFailed)
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
                                @if ($tsLabel !== '')
                                    <span class="font-mono text-xs text-brand-ink">{{ $tsLabel }}</span>
                                    @if ($rel)
                                        <span class="mt-1 block text-xs">{{ $rel }}</span>
                                    @endif
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
                                    @if ($rowBusy || ($systemdBulkBusy ?? false))
                                        <span class="inline-flex items-center gap-1.5 text-[11px] font-medium text-brand-moss">
                                            <span class="inline-block size-3.5 animate-spin rounded-full border-2 border-brand-ink/20 border-t-brand-ink" aria-hidden="true"></span>
                                            {{ __('Working…') }}
                                        </span>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="openSystemdStatusModalForService(@js($rowUnit))"
                                        wire:loading.attr="disabled"
                                        wire:target="openSystemdStatusModalForService"
                                        @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || $otherBusy)
                                        class="{{ $btnSecondary }} !inline-flex !h-9 !min-w-[7.25rem] !shrink-0 !items-center !justify-center !gap-1.5 !px-2 !py-0 !text-[11px]"
                                    >
                                        <span
                                            wire:loading
                                            wire:target="openSystemdStatusModalForService"
                                            class="inline-block size-3 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink"
                                            aria-hidden="true"
                                        ></span>
                                        <span wire:loading.remove wire:target="openSystemdStatusModalForService" class="uppercase tracking-wide">{{ __('Status') }}</span>
                                        <span wire:loading wire:target="openSystemdStatusModalForService" class="uppercase tracking-wide">{{ __('Loading') }}</span>
                                    </button>
                                    @if (! $isActive)
                                        <button
                                            type="button"
                                            wire:click="runSystemdServiceAction(@js($rowUnit), 'start')"
                                            @disabled(! $opsReady || ! $mayMutate || $otherBusy || $rowBusy)
                                            class="{{ $btnSecondary }} !shrink-0 !py-2 !text-[11px]"
                                        >
                                            {{ __('Start') }}
                                        </button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="runSystemdServiceAction(@js($rowUnit), 'restart')"
                                        wire:confirm="{{ __('Restart this service?') }}"
                                        @disabled(! $opsReady || ! $mayMutate || $otherBusy || $rowBusy)
                                        class="{{ $btnSecondary }} !shrink-0 !py-2 !text-[11px]"
                                    >
                                        {{ __('Restart') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="runSystemdServiceAction(@js($rowUnit), 'stop')"
                                        wire:confirm="{{ __('Stop this service?') }}"
                                        @disabled(! $opsReady || ! $mayMutate || $otherBusy || $rowBusy)
                                        class="{{ $btnDanger }} !shrink-0 !py-2 !text-[11px]"
                                    >
                                        {{ __('Stop') }}
                                    </button>
                                    @if ($hasMoreMenu)
                                        <div class="shrink-0">
                                            <x-dropdown align="right" width="w-56" contentClasses="py-1 bg-white">
                                                <x-slot name="trigger">
                                                    <button
                                                        type="button"
                                                        class="{{ $btnSecondary }} !inline-flex !shrink-0 !items-center !gap-1 !py-2 !pl-2 !pr-2 !text-[11px]"
                                                        aria-label="{{ __('More actions') }}"
                                                        aria-haspopup="true"
                                                        @disabled(
                                                            (! $opsReady && ! $rowAlerts)
                                                            || (($deployerSystemdLocked ?? true) && ! $rowManageExtras && ! $rowAlerts)
                                                            || $otherBusy
                                                            || $rowBusy
                                                        )
                                                    >
                                                        <span class="uppercase tracking-wide">{{ __('More') }}</span>
                                                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5 shrink-0 text-brand-ink/70" />
                                                    </button>
                                                </x-slot>
                                                <x-slot name="content">
                                                    @if ($rowManageExtras)
                                                        <button
                                                            type="button"
                                                            wire:click="runSystemdServiceAction(@js($rowUnit), 'reload')"
                                                            wire:confirm="{{ __('Reload this service? Configuration is reapplied without a full restart when the unit supports reload.') }}"
                                                            class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                        >
                                                            {{ __('Reload') }}
                                                        </button>
                                                    @endif
                                                    @if ($hasBootMenu)
                                                        @if ($showBootEnable)
                                                            <button
                                                                type="button"
                                                                wire:click="runSystemdServiceAction(@js($rowUnit), 'enable')"
                                                                wire:confirm="{{ __('Enable this unit to start at boot?') }}"
                                                                class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                            >
                                                                {{ __('Enable at boot') }}
                                                            </button>
                                                        @endif
                                                        @if ($showBootDisable)
                                                            <button
                                                                type="button"
                                                                wire:click="runSystemdServiceAction(@js($rowUnit), 'disable')"
                                                                wire:confirm="{{ __('Disable this unit at boot? It will not start automatically after reboot; it may keep running until stopped.') }}"
                                                                class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                            >
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
                                                            wire:click="openSystemdAlertsModalForService(@js($rowUnit))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="openSystemdAlertsModalForService"
                                                            class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                        >
                                                            <span class="inline-flex items-center gap-2">
                                                                <x-heroicon-o-bell class="h-4 w-4 shrink-0 text-brand-ink/80" />
                                                                {{ __('Notify') }}
                                                                @if (($row['alert_subscription_count'] ?? 0) > 0)
                                                                    <span class="rounded-full bg-brand-sand px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-brand-ink">{{ (int) ($row['alert_subscription_count'] ?? 0) }}</span>
                                                                @endif
                                                            </span>
                                                        </button>
                                                    @endif
                                                    @if ($server->organization_id && ($rowManageExtras || $hasBootMenu || $rowAlerts))
                                                        <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                                        <a
                                                            href="{{ route('organizations.notification-channels', $server->organization_id) }}"
                                                            wire:navigate
                                                            class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50"
                                                        >
                                                            {{ __('Notification channels') }}
                                                        </a>
                                                    @endif
                                                    @if (! empty($row['custom']))
                                                        <div class="my-1 border-t border-brand-ink/10" role="presentation"></div>
                                                        <button
                                                            type="button"
                                                            wire:click="removeCustomSystemdUnit(@js($rowUnit))"
                                                            wire:confirm="{{ __('Remove this unit from custom services?') }}"
                                                            @disabled($isDeployer)
                                                            class="block w-full px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {{ __('Remove custom unit') }}
                                                        </button>
                                                    @endif
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    @endif
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
                </tbody>
            </table>
        </div>
    </div>

    <x-slot name="modals">
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
                        <button type="button" wire:click="addCustomSystemdUnit" @disabled($isDeployer) class="{{ $btnPrimary }} shrink-0">
                            {{ __('Add') }}
                        </button>
                    </div>
                    @if ($customMetaList !== [])
                        <ul class="mt-4 max-h-48 space-y-2 overflow-y-auto rounded-xl border border-brand-ink/10 p-3 text-sm">
                            @foreach ($customMetaList as $cu)
                                <li class="flex items-center justify-between gap-2 font-mono text-xs text-brand-ink">
                                    <span>{{ $cu }}</span>
                                    <button
                                        type="button"
                                        wire:click="removeCustomSystemdUnit(@js($cu))"
                                        wire:confirm="{{ __('Remove this custom unit?') }}"
                                        class="shrink-0 text-xs font-semibold text-red-700 hover:text-red-900"
                                    >
                                        {{ __('Remove') }}
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
        @if ($showSystemdBulkNotifyModal)
            <div class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="bulk-systemd-notify-heading">
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeSystemdBulkNotifyModal"></div>
                <div class="relative z-10 w-full max-w-lg rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-xl">
                    <h2 id="bulk-systemd-notify-heading" class="text-lg font-semibold text-brand-ink">{{ __('Notify selected services') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Subscribe the selected units on this server to one channel. Defaults favor stopped and state-change events; enable “Restarted” only if you want noisy alerts.') }}</p>
                    <div class="mt-4">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Channel') }}</label>
                        <select wire:model="systemdBulkNotifyChannelId" class="mt-1 w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30">
                            @foreach ($bulkNotifyChannelOptions ?? [] as $ch)
                                <option value="{{ $ch->id }}">{{ $ch->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mt-4 space-y-2">
                        @foreach (\App\Support\ServerSystemdServiceNotificationKeys::kindLabels() as $kind => $lbl)
                            <label class="flex items-center gap-2 text-sm text-brand-ink">
                                <input type="checkbox" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" wire:model.live="systemdBulkNotifyKinds.{{ $kind }}" />
                                {{ $lbl }}
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" wire:click="closeSystemdBulkNotifyModal" class="{{ $btnSecondary }}">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="saveSystemdBulkNotifySubscriptions" class="{{ $btnPrimary }}">{{ __('Save subscriptions') }}</button>
                    </div>
                </div>
            </div>
        @endif
        @if ($showSystemdStatusModal)
            @php
                $systemdKindLabels = \App\Support\ServerSystemdServiceNotificationKeys::kindLabels();
            @endphp
            <div
                class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center sm:p-6"
                role="dialog"
                aria-modal="true"
                aria-labelledby="systemd-status-modal-heading"
            >
                <div class="fixed inset-0 bg-brand-ink/40 backdrop-blur-[1px]" wire:click="closeSystemdStatusModal"></div>
                {{-- Single overflow-y-auto on the panel (not flex-1) so touch/trackpad scroll reaches notification events; sticky header stays visible. --}}
                <div class="relative z-10 max-h-[min(92vh,52rem)] w-full max-w-[min(96vw,72rem)] overflow-y-auto overscroll-contain rounded-2xl border border-brand-ink/10 bg-white shadow-xl [-webkit-overflow-scrolling:touch]">
                    <div class="sticky top-0 z-[1] flex items-start justify-between gap-3 border-b border-brand-ink/10 bg-white px-4 py-4 sm:px-6 sm:py-5">
                        <div class="min-w-0">
                            <h2 id="systemd-status-modal-heading" class="text-base font-semibold text-brand-ink">{{ __('Service') }}</h2>
                            <p class="mt-0.5 font-mono text-xs text-brand-moss break-all">{{ $systemdStatusModalUnit }}</p>
                            @if ($systemdEntrySnippet)
                                <p class="mt-2 rounded-lg border border-brand-sage/30 bg-brand-sand/50 px-3 py-2 text-xs text-brand-ink leading-snug">{{ $systemdEntrySnippet }}</p>
                            @endif
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
                                <span wire:loading.remove wire:target="fetchSystemdModalStatus">{{ __('Refresh status') }}</span>
                                <span wire:loading wire:target="fetchSystemdModalStatus">{{ __('Fetching…') }}</span>
                            </button>
                            <button type="button" wire:click="closeSystemdStatusModal" class="{{ $btnSecondary }} !py-2 !text-[11px]">
                                {{ __('Close') }}
                            </button>
                        </div>
                    </div>
                    <div class="px-4 py-4 sm:px-6 sm:py-5">
                        @if ($systemdStatusModalLoading)
                            <p class="text-xs font-medium text-brand-ink">{{ __('Fetching systemctl status…') }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('This can take a few seconds over SSH.') }}</p>
                        @endif
                        @if ($systemdStatusModalError)
                            <div class="mb-3 rounded-lg border border-red-200/80 bg-red-50/90 px-3 py-2 text-xs text-red-900">{{ $systemdStatusModalError }}</div>
                        @endif
                        @if ($systemdStatusModalOutput !== '')
                            <div class="rounded-xl border border-brand-ink/15 bg-zinc-50 p-3 shadow-inner">
                                <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-brand-ink">{{ __('systemctl status') }}</p>
                                <pre class="font-mono text-[11px] leading-snug whitespace-pre-wrap break-words text-zinc-900 [overflow-wrap:anywhere]">{{ $systemdStatusModalOutput }}</pre>
                            </div>
                        @elseif (! $systemdStatusModalLoading && $systemdStatusModalError === null)
                            <p class="text-xs text-brand-moss">{{ __('No output yet. Choose Refresh status.') }}</p>
                        @endif

                        <div class="mt-6 border-t border-brand-ink/10 pt-6">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Service status notifications') }}</h3>
                            <p class="mt-1.5 text-xs text-brand-moss leading-snug">
                                {{ __('When background inventory detects a change, notify the channels you choose. Tick the events you care about per channel, then save.') }}
                            </p>
                            <p class="mt-2 text-[11px] text-brand-moss leading-snug">
                                {{ __('Tip: “Restarted” can be noisy during deploys; “Stopped” and “State change” are usually enough.') }}
                            </p>
                            @if ($systemdStatusModalChannelRows === [])
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
                                            @foreach ($systemdStatusModalChannelRows as $chRow)
                                                <tr wire:key="svc-alerts-{{ $chRow['id'] }}">
                                                    <td class="px-2 py-2 font-medium text-brand-ink">{{ $chRow['label'] }}</td>
                                                    @foreach ($systemdKindLabels as $kind => $_label)
                                                        <td class="px-2 py-2 text-center align-middle">
                                                            <input
                                                                type="checkbox"
                                                                class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                                                                wire:model.live="systemdStatusModalAlertMatrix.{{ $chRow['id'] }}.{{ $kind }}"
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
                                        wire:click="saveSystemdStatusModalAlertPreferences"
                                        wire:loading.attr="disabled"
                                        @disabled($isDeployer)
                                        class="{{ $btnPrimary }} !py-2 !text-[11px]"
                                    >
                                        {{ __('Save alert routing') }}
                                    </button>
                                </div>
                            @endif
                        </div>
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
