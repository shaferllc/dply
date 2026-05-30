{{-- Managed services — higher-level dply abstractions (caches, databases,
     webserver, PHP, daemons) surfaced alongside the systemd inventory.
     Each card links to its dedicated workspace; the unit-level view stays
     below for operators who need to drill into a specific systemd unit. --}}
@if ($managedTiles->isNotEmpty())
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Managed services') }}</h2>
                <p class="text-xs text-brand-mist">{{ __('dply-managed abstractions on this server. Jump to their dedicated workspaces.') }}</p>
            </div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($managedTiles as $tile)
                    <a href="{{ $tile['href'] }}" wire:navigate class="group flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-white p-4 transition-colors hover:border-brand-forest/30 hover:bg-brand-sand/10">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                            <x-dynamic-component :component="$tile['icon']" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-brand-ink">{{ $tile['label'] }}</p>
                            <p class="mt-0.5 break-words text-xs text-brand-moss">{{ $tile['detail'] }}</p>
                        </div>
                        <x-heroicon-o-arrow-right class="h-4 w-4 shrink-0 text-brand-mist transition-colors group-hover:text-brand-forest" />
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="{{ $card }}">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
            <div>
                <h2 class="text-base font-semibold text-brand-ink">{{ __('System services') }}</h2>
                @if ($systemdInventoryFetchedAt && ($snapHuman ?? null))
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Last inventory sync: :time', ['time' => $snapHuman]) }}</p>
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
                            $rowIsBusy = $this->systemdInventoryRowIsBusy($rowUnit);
                            $rowBusy = ($systemdRowBusyUnit ?? '') !== '' && ($systemdRowBusyUnit ?? '') === $rowUnit;
                            $otherBusy = (($systemdRowBusyUnit ?? '') !== '' && ! $rowBusy) || ($systemdBulkBusy ?? false);
                            $bootUnk = trim((string) ($row['boot_state'] ?? '')) === '';
                            $systemdRowWireTargets = $this->systemdInventoryRowWireTargets($rowUnit);
                            $busyAction = $rowIsBusy
                                ? ($systemdActiveRowAction ?? $systemdRowBusyAction ?? $row['pending_action'] ?? null)
                                : null;
                            $pendingLabel = match ($busyAction) {
                                'start' => __('Starting…'),
                                'stop' => __('Stopping…'),
                                'restart' => __('Restarting…'),
                                'reload' => __('Reloading…'),
                                'enable' => __('Enabling at boot…'),
                                'disable' => __('Disabling at boot…'),
                                default => __('Working…'),
                            };
                            $rowPending = $rowIsBusy;
                        @endphp
                        <tr
                            wire:key="systemd-svc-{{ $rowUnit }}"
                            wire:loading.class="pointer-events-none relative z-10 bg-amber-50/90 opacity-70 ring-1 ring-inset ring-amber-200/70"
                            wire:target="{{ $systemdRowWireTargets }}"
                            @class([
                                'bg-red-50/40' => $isFailed && ! $rowIsBusy,
                                'relative z-10 bg-amber-50/90 opacity-70 pointer-events-none ring-1 ring-inset ring-amber-200/70' => $rowIsBusy,
                                'transition-[background-color,opacity] duration-150' => true,
                            ])
                            @if ($rowIsBusy) aria-busy="true" @endif
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
                            <td class="relative p-4 align-top">
                                @if ($rowIsBusy)
                                    <div
                                        class="pointer-events-none absolute inset-0 z-20 flex items-center justify-center gap-2.5 bg-amber-50/95"
                                        role="status"
                                        aria-live="polite"
                                    >
                                        <x-spinner variant="forest" size="lg" />
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-brand-forest">{{ $pendingLabel }}</span>
                                    </div>
                                @endif
                                <div
                                    wire:loading.flex
                                    wire:target="{{ $systemdRowWireTargets }}"
                                    class="pointer-events-none absolute inset-0 z-20 items-center justify-center gap-2.5 bg-amber-50/95"
                                    role="status"
                                >
                                    <x-spinner variant="forest" size="lg" />
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Working…') }}</span>
                                </div>
                                <p class="font-medium text-brand-ink">{{ $nameLine }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    @if ($rowIsBusy)
                                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-200/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-950 ring-1 ring-amber-300/80">
                                            <span class="inline-block size-2.5 shrink-0 animate-spin rounded-full border-2 border-amber-600/30 border-t-amber-800" aria-hidden="true"></span>
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
                                @if (! empty($row['standby_reason']))
                                    <p class="mt-1.5 text-xs leading-snug text-amber-900/90">{{ $row['standby_reason'] }}</p>
                                @endif
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
                            <td class="relative p-4 align-top text-right">
                                @php
                                    $rowManageExtras = $mayMutate && $opsReady && ! ($deployerSystemdLocked ?? true);
                                    $rowAlerts = ! $isDeployer && $server->organization_id;
                                    $showBootEnable = (bool) ($row['boot_menu_show_enable'] ?? true);
                                    $showBootDisable = (bool) ($row['boot_menu_show_disable'] ?? true);
                                    $hasBootMenu = $rowManageExtras && ($showBootEnable || $showBootDisable);
                                    $hasMoreMenu = $rowManageExtras || $rowAlerts || (! empty($row['custom']));
                                @endphp
                                <div class="flex flex-nowrap items-center justify-end gap-2">
                                    {{-- Primary action: Start / Restart / Status. Row overlay handles loading. --}}
                                    @if ($mayMutate && ! $isActive)
                                        @php
                                            $startUsesConfirm = ! empty($row['standby_reason']);
                                        @endphp
                                        <button
                                            type="button"
                                            @if ($startUsesConfirm)
                                                wire:click="openSystemdActionConfirm('start', @js($rowUnit))"
                                            @else
                                                wire:click="runSystemdServiceAction(@js($rowUnit), 'start')"
                                            @endif
                                            wire:loading.attr="disabled"
                                            @disabled(! $opsReady || $otherBusy || $rowPending)
                                            class="{{ $btnSecondary }} !inline-flex !items-center !gap-1.5 !shrink-0 !py-2 !text-[11px]"
                                            @if ($startUsesConfirm)
                                                title="{{ $row['standby_reason'] }}"
                                            @endif
                                        >
                                            <x-heroicon-o-play class="h-3.5 w-3.5 shrink-0 text-emerald-700" aria-hidden="true" />
                                            {{ __('Start') }}
                                        </button>
                                    @elseif ($mayMutate)
                                        <button
                                            type="button"
                                            wire:click="openSystemdActionConfirm('restart', @js($rowUnit))"
                                            wire:loading.attr="disabled"
                                            wire:target="openSystemdActionConfirm('restart', @js($rowUnit))"
                                            @disabled(! $opsReady || $otherBusy || $rowPending)
                                            class="{{ $btnSecondary }} !inline-flex !items-center !gap-1.5 !shrink-0 !py-2 !text-[11px]"
                                        >
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5 shrink-0 text-brand-ink/80" wire:loading.remove wire:target="openSystemdActionConfirm('restart', @js($rowUnit))" aria-hidden="true" />
                                            <span wire:loading wire:target="openSystemdActionConfirm('restart', @js($rowUnit))" class="inline-flex h-3.5 w-3.5 shrink-0 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink" aria-hidden="true"></span>
                                            <span wire:loading.remove wire:target="openSystemdActionConfirm('restart', @js($rowUnit))">{{ __('Restart') }}</span>
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="openSystemdStatusModalForService(@js($rowUnit))"
                                            wire:loading.attr="disabled"
                                            @disabled(! $opsReady || ($deployerSystemdLocked ?? true) || $otherBusy)
                                            class="{{ $btnSecondary }} !inline-flex !items-center !gap-1.5 !shrink-0 !py-2 !text-[11px]"
                                        >
                                            <x-heroicon-o-eye class="h-3.5 w-3.5 shrink-0 text-brand-ink/80" aria-hidden="true" />
                                            {{ __('Status') }}
                                        </button>
                                    @endif
                                    <div class="relative shrink-0">
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
                                                    // and a tiny spinner while that menu action's
                                                    // wire round-trip is in flight. Long SSH work
                                                    // uses the full-row overlay on this unit only.
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
                                                        wire:click="openSystemdActionConfirm('stop', @js($rowUnit))"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openSystemdActionConfirm('stop', @js($rowUnit))"
                                                        @disabled(! $opsReady || $otherBusy || $rowBusy || $rowPending)
                                                        class="{{ $menuItemDanger }}"
                                                    >
                                                        <x-heroicon-o-stop-circle class="{{ $iconClassesDanger }}" aria-hidden="true" />
                                                        {{ __('Stop') }}
                                                    </button>
                                                @endif
                                                    @if ($rowManageExtras)
                                                        <button
                                                            type="button"
                                                            wire:click="openSystemdActionConfirm('reload', @js($rowUnit))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="openSystemdActionConfirm('reload', @js($rowUnit))"
                                                            class="{{ $menuItem }}"
                                                        >
                                                            <x-heroicon-o-arrow-path class="{{ $iconClasses }}" aria-hidden="true" />
                                                            {{ __('Reload') }}
                                                        </button>
                                                    @endif
                                                    @if ($hasBootMenu)
                                                        @if ($showBootEnable)
                                                            <button
                                                                type="button"
                                                                wire:click="openSystemdActionConfirm('enable', @js($rowUnit))"
                                                                wire:loading.attr="disabled"
                                                                wire:target="openSystemdActionConfirm('enable', @js($rowUnit))"
                                                                class="{{ $menuItem }}"
                                                            >
                                                                <x-heroicon-o-bolt class="{{ $iconClasses }}" aria-hidden="true" />
                                                                {{ __('Enable at boot') }}
                                                            </button>
                                                        @endif
                                                        @if ($showBootDisable)
                                                            <button
                                                                type="button"
                                                                wire:click="openSystemdActionConfirm('disable', @js($rowUnit))"
                                                                wire:loading.attr="disabled"
                                                                wire:target="openSystemdActionConfirm('disable', @js($rowUnit))"
                                                                class="{{ $menuItem }}"
                                                            >
                                                                <x-heroicon-o-no-symbol class="{{ $iconClasses }}" aria-hidden="true" />
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
                                                            wire:click="openSystemdActionConfirm('remove-custom', @js($rowUnit))"
                                                            wire:loading.attr="disabled"
                                                            wire:target="openSystemdActionConfirm('remove-custom', @js($rowUnit))"
                                                            @disabled($isDeployer)
                                                            class="{{ $menuItemDanger }}"
                                                        >
                                                            <x-heroicon-o-trash class="{{ $iconClassesDanger }}" aria-hidden="true" />
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
