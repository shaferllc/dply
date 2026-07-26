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
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-cog-6-tooth class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Services') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Systemd units from inventory — start, stop, restart, and sync with the same SSH safeguards as Manage.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

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

        <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Services workspace')" scroll class="!mb-0 border-0 bg-transparent p-0 shadow-none">
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

        <div wire:loading.block wire:target="setServicesWorkspaceTab" class="px-5 py-6 sm:px-6" aria-busy="true">
            <span class="sr-only">{{ __('Loading…') }}</span>
            <div class="space-y-3" aria-hidden="true">
                <div class="flex items-start gap-3">
                    <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                @foreach (range(1, 4) as $row)
                    <div class="flex items-start gap-3 border-t border-brand-ink/10 pt-3">
                        <span class="mt-1 h-5 w-14 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-3/4 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

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
