@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
    $inventoryCheckedAt = is_array($server->meta ?? null)
        ? ($server->meta['inventory_checked_at'] ?? null)
        : null;
    $needsInventoryProbePoll = (bool) config('server_manage.inventory_probe_refresh_on_load', true)
        && ! $isDeployer
        && (
            ! $opsReady
            || ! is_string($inventoryCheckedAt)
            || trim($inventoryCheckedAt) === ''
        );
    $inventoryProbePollSeconds = max(3, (int) config('server_manage.inventory_probe_poll_seconds', 5));

    $manageShare = [
        'card' => $card,
        'server' => $server,
        'opsReady' => $opsReady,
        'isDeployer' => $isDeployer,
        'configPreviews' => $configPreviews,
        'serviceActions' => $serviceActions,
        'dangerousActions' => $dangerousActions,
        'autoUpdateIntervals' => $autoUpdateIntervals,
        'recentActions' => $recentActions ?? collect(),
        'toolsReport' => $toolsReport ?? null,
        'activeMiseRuntimeOps' => $activeMiseRuntimeOps ?? [],
        'activeToolActionOps' => $activeToolActionOps ?? [],
        'pendingToolActionKey' => $pendingToolActionKey ?? null,
        'miseReprobePending' => $miseReprobePending ?? false,
        'toolsPanel' => $toolsPanel ?? 'tools',
    ];

    // Workspace-scoped console-actions banner. Surfaces the in-flight + most-recent
    // run for any kind Manage dispatches — manage_action (Quick actions / service
    // controls / config previews / Tools installs / mise runtime ops),
    // webserver_switch (Web tab cascade), edge_proxy (add/remove traefik|haproxy),
    // and inventory_probe (Refresh probe). The banner partial self-polls while
    // in-flight and replaces the legacy "Command output" panel.
    $manageConsoleRun = \App\Models\ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->whereIn('kind', ['manage_action', 'webserver_switch', 'edge_proxy', 'inventory_probe', 'clone_server'])
        ->whereNull('dismissed_at')
        ->orderByDesc('created_at')
        ->first();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="manage"
    :title="__('Manage')"
    :description="__('Live state and actions for the server stack. Each tab is scoped to one subsystem.')"
>
    @if ($needsInventoryProbePoll)
        <div
            wire:init="maybeRefreshInventoryProbeOnLoad"
            wire:poll.{{ $inventoryProbePollSeconds }}s="pollManageInventoryState"
            class="hidden"
            aria-hidden="true"
        ></div>
    @endif
    @if ($manageRemoteTaskId || ($section === 'tools' && (($activeMiseRuntimeOps ?? []) !== [] || ($activeToolActionOps ?? []) !== [] || ($miseReprobePending ?? false) || ($pendingToolActionKey ?? null))))
        <div wire:poll.2s="pollManageWorkspace" class="hidden" aria-hidden="true"></div>
    @endif
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @php
        $tonePalette = [
            'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
            'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
            'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        ];
    @endphp

    <div class="space-y-6">
        @include('livewire.partials.console-action-banner-static', [
            'run' => $manageConsoleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])

        <div class="relative space-y-6">
        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view this page but cannot run SSH actions or change manage settings.') }}</p>
                    </div>
                </div>
            </section>
        @endif

        @if (! $opsReady)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before previews and service actions work.') }}</p>
                    </div>
                </div>
            </section>
        @endif

        {{-- The Manage workspace was dissolved; this base component is now only
             reachable as the flag-off fallback for /manage/updates (when the
             patch advisor feature is off, so there is no Patches page to absorb
             it). Every other former section redirects in mount(). --}}
        @include('livewire.servers.partials.manage.group-updates', $manageShare)
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
