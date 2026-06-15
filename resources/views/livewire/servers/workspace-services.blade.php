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

    @php
        $tonePalette = [
            'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
            'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
            'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        ];
    @endphp

    <x-slot:explainer>
        <p>{{ __('Inventory of the systemd units on this server, surfaced live from systemctl list-units. Restart, stop, start, and enable/disable map to the matching systemctl verbs and run as root over SSH.') }}</p>
        <p>{{ __('Custom services are systemd unit files dply tracks specifically — they show up as actionable rows. Stock units (sshd, networkd, etc.) are visible but actions are gated to the ones dply considers safe to mutate.') }}</p>
    </x-slot:explainer>

    @if ($server->workspace)
        @feature('surface.projects')
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Project') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Project operations shortcut') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Service changes here may affect the wider project. Use the project operations page to review runbooks, recent activity, and alert routing when this server is part of a larger grouped stack.') }}
                        </p>
                    </div>
                </div>
                <div class="px-6 py-6 sm:px-7">
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Project operations') }}
                        </a>
                        <a href="{{ route('projects.access', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-shield-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Project access') }}
                        </a>
                    </div>
                </div>
            </section>
        @endfeature
    @endif

    @if ($isDeployer && ($deployerSystemdLocked ?? true))
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can view this page but cannot run service actions over SSH unless your organization allows deployer systemd access.') }}</p>
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
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before managing services.') }}</p>
                </div>
            </div>
        </section>
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
