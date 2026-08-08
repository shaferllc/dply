<x-server-workspace-layout
    :server="$server"
    active="services"
    :title="__('Services')"
    :description="__('Running systemd units from database-backed inventory; actions use the same SSH safeguards as Manage.')"
    hide-hero
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

    @if ($opsReady)
        @include('livewire.servers.partials.services._banner')
    @endif

    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-cog-6-tooth"
            :title="__('Services')"
            :note="__('Systemd units from inventory — start, stop, restart, and sync with the same SSH safeguards as Manage.')"
            class="border-b border-brand-ink/10"
        />

        @if ($server->workspace)
            @feature('surface.projects')
                <p class="border-b border-brand-ink/10 px-5 py-3 text-xs leading-relaxed text-brand-moss sm:px-6">
                    {{ __('Service changes may affect the wider project.') }}
                    <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="ml-1 font-semibold text-brand-forest hover:underline">{{ __('Project operations') }} →</a>
                    <span class="text-brand-mist"> · </span>
                    <a href="{{ route('projects.access', $server->workspace) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Access') }} →</a>
                </p>
            @endfeature
        @endif

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Services workspace')" scroll bare class="!mb-0">
                <x-server-workspace-tab
                    id="services-tab-inventory"
                    icon="heroicon-o-cog-6-tooth"
                    :active="$services_workspace_tab === 'inventory'"
                    wire:click="setServicesWorkspaceTab('inventory')"
                >
                    {{ __('Inventory') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="services-tab-activity"
                    icon="heroicon-o-clock"
                    :active="$services_workspace_tab === 'activity'"
                    wire:click="setServicesWorkspaceTab('activity')"
                >
                    {{ __('Activity') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        {{-- One skeleton per tab, each targeting the call WITH its argument.
             A single shared stub matched neither tab: Inventory arrives as
             managed tiles + a unit table, Activity as an event feed, so the
             panel resized on arrival either way. --}}
        @foreach (['inventory', 'activity'] as $skeletonTab)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setServicesWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @include('livewire.servers.partials.services._tab-skeleton', [
                    'tab' => $skeletonTab,
                    'rows' => $skeletonTab === 'activity' ? ($activityCount ?? 6) : 6,
                ])
            </div>
        @endforeach

        <div wire:loading.remove wire:target="setServicesWorkspaceTab">
            @if ($isDeployer && ($deployerSystemdLocked ?? true))
                <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-900 ring-1 ring-amber-200">
                            <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view this page but cannot run service actions over SSH unless your organization allows deployer systemd access.') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            @if (! $opsReady)
                <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-900 ring-1 ring-amber-200">
                            <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before managing services.') }}</p>
                        </div>
                    </div>
                </div>
            @else
                @if ($services_workspace_tab === 'inventory')
                    @include('livewire.servers.partials.services.inventory-tab')
                @endif

                @if ($services_workspace_tab === 'activity')
                    @include('livewire.servers.partials.services.activity-tab')
                @endif
            @endif
        </div>
    </section>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.services._modals')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
