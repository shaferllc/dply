@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;

    $manageShare = [
        'card' => $card,
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
>
    @if ($manageRemoteTaskId || ($activeMiseRuntimeOps ?? []) !== [] || ($activeToolActionOps ?? []) !== [] || ($miseReprobePending ?? false) || ($pendingToolActionKey ?? null))
        <div wire:poll.2s="pollManageWorkspace" class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="space-y-6">
        @include('livewire.partials.console-action-banner-static', [
            'run' => $manageConsoleRun,
            'kindLabels' => (array) config('console_actions.kinds', []),
        ])

        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view this page but cannot run SSH actions or install tools.') }}</p>
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
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before tool inventory and installs work.') }}</p>
                    </div>
                </div>
            </section>
        @endif

        <div class="relative space-y-6">
            @include('livewire.servers.partials.manage.group-tools', $manageShare)

            {{-- Host power: reboot + stuck-task cleanup. These ride on Tools (rather
                 than a standalone Manage > Danger tab) because they reuse the same
                 inherited action stack; reboot also remains on the Patches page. --}}
            @if ($opsReady && ! $isDeployer && (count($dangerousActions) > 0 || $manageRemoteTaskId))
                <section class="dply-card overflow-hidden border-red-200/50">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-red-50/40 px-6 py-5 sm:px-7">
                        <x-icon-badge tone="rose">
                            <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-red-800">{{ __('Power') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Host power') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Reboot the host or clear a stuck queued task. A reboot drops your SSH session and any in-flight work.') }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 px-6 py-5 sm:px-7">
                        @foreach ($dangerousActions as $actionKey => $action)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $actionKey }}'], @js($action['label'] ?? $actionKey), @js($action['confirm'] ?? __('Are you sure?')), @js($action['label'] ?? __('Run action')), true)"
                                class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-900 hover:bg-red-100 transition-colors"
                            >
                                <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ $action['label'] ?? $actionKey }}
                            </button>
                        @endforeach

                        @if ($manageRemoteTaskId)
                            <button
                                type="button"
                                wire:click="cancelQueuedManageTasks"
                                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                                {{ __('Cancel queued task') }}
                            </button>
                        @endif
                    </div>
                </section>
            @endif
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
