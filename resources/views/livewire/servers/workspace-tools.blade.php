@php
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;

    $manageShare = [
        'server' => $server,
        'opsReady' => $opsReady,
        'isDeployer' => $isDeployer,
        'serviceActions' => $serviceActions,
        'dangerousActions' => $dangerousActions,
        'recentActions' => $recentActions ?? collect(),
        'toolsReport' => $toolsReport ?? null,
        'activeMiseRuntimeOps' => $activeMiseRuntimeOps ?? [],
        'activeToolActionOps' => $activeToolActionOps ?? [],
        'pendingToolActionKey' => $pendingToolActionKey ?? null,
        'miseReprobePending' => $miseReprobePending ?? false,
        'toolsPanel' => $toolsPanel ?? 'tools',
        // Nested sections inside the merged Tools card — not second page cards.
        'card' => 'border-b border-brand-ink/10',
    ];

    // Same workspace-scoped console-actions banner WorkspaceManage surfaced: the
    // in-flight + most-recent run for any manage dispatch (tool installs, mise
    // runtime ops, allowlisted actions, inventory probe).
    $manageConsoleRun = \App\Models\ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->whereIn('kind', ['manage_action', 'inventory_probe'])
        ->whereNull('dismissed_at')
        ->orderByDesc('created_at')
        ->first();

@endphp

<x-server-workspace-layout
    :server="$server"
    active="tools"
    :title="__('Tools')"
    :description="__('Installed CLIs, version managers, and language runtimes for this host.')"
    hide-hero
>
    @if ($manageRemoteTaskId || ($activeMiseRuntimeOps ?? []) !== [] || ($activeToolActionOps ?? []) !== [] || ($miseReprobePending ?? false) || ($pendingToolActionKey ?? null))
        <div wire:poll.2s="pollManageWorkspace" class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @include('livewire.partials.console-action-banner-static', [
        'run' => $manageConsoleRun,
        'kindLabels' => (array) config('console_actions.kinds', []),
    ])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        {{-- Service-only boxes (database / cache / load balancer) have no
             installable CLIs, so promising "install, upgrade, or repair" would be
             describing a list that isn't there. --}}
        @php $hasToolCatalog = ($toolsReport['summary']['catalog_count'] ?? 0) > 0; @endphp
        <x-workspace-panel-head
            dense
            icon="heroicon-o-wrench-screwdriver"
            :title="__('Tools')"
            :note="$hasToolCatalog
                ? __('Installed CLIs and version managers from the inventory probe — install, upgrade, or repair from here.')
                : __('Host-level actions for this server. This server type has no installable CLIs.')"
            class="border-b border-brand-ink/10"
        />

        @if ($isDeployer)
            <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-3.5 text-sm text-amber-900 sm:px-6">
                <span class="font-semibold">{{ __('Deployer role.') }}</span>
                {{ __('Deployers can view this page but cannot run SSH actions or install tools.') }}
            </div>
        @endif

        @if (! $opsReady)
            <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-3.5 text-sm text-amber-900 sm:px-6">
                {{ __('Provisioning and SSH must be ready before tool inventory and installs work.') }}
            </div>
        @endif

        @include('livewire.servers.partials.manage.group-tools', $manageShare)

        @if ($opsReady && ! $isDeployer && (count($dangerousActions) > 0 || $manageRemoteTaskId))
            {{-- Compact: one row, actions inline on the right. The "POWER" eyebrow
                 restated the "Host power" title directly under it, and the button
                 sat on its own line below a full-width paragraph — three stacked
                 blocks for what is really one action. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-3 border-t border-red-200/60 bg-red-50/30 px-5 py-4 sm:px-6">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-800 ring-1 ring-rose-200">
                    <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Host power') }}</h3>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Reboot the host or clear a stuck queued task. A reboot drops your SSH session and any in-flight work.') }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    @foreach ($dangerousActions as $actionKey => $action)
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label'] ?? $actionKey), @js($action['confirm'] ?? __('Are you sure?')), @js($action['label'] ?? __('Run action')), true)"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-900 transition-colors hover:bg-red-100"
                        >
                            <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $action['label'] ?? $actionKey }}
                        </button>
                    @endforeach

                    @if ($manageRemoteTaskId)
                        <button
                            type="button"
                            wire:click="cancelQueuedManageTasks"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Cancel queued task') }}
                        </button>
                    @endif
                </div>
            </div>
        @endif
    </section>

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
