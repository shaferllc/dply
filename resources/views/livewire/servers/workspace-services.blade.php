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
        @feature('surface.projects')
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
        @endfeature
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
            @include('livewire.servers.partials.services._banner')

            <x-server-workspace-tablist :aria-label="__('Services workspace')">
                <x-server-workspace-tab id="services-tab-inventory" icon="heroicon-o-cog-6-tooth" :active="$services_workspace_tab === 'inventory'" wire:click="setServicesWorkspaceTab('inventory')">
                    {{ __('Inventory') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="services-tab-activity" icon="heroicon-o-clock" :active="$services_workspace_tab === 'activity'" wire:click="setServicesWorkspaceTab('activity')">
                    {{ __('Activity') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setServicesWorkspaceTab">

            @if ($services_workspace_tab === 'inventory')
                <x-server-workspace-tab-panel
                    id="services-panel-inventory"
                    labelled-by="services-tab-inventory"
                    panel-class="space-y-6"
                >
                    @include('livewire.servers.partials.services.inventory-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($services_workspace_tab === 'activity')
                <x-server-workspace-tab-panel
                    id="services-panel-activity"
                    labelled-by="services-tab-activity"
                    panel-class="space-y-6"
                >
                    @include('livewire.servers.partials.services.activity-tab')
                </x-server-workspace-tab-panel>
            @endif

            </div>
        </div>
    @endif

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
