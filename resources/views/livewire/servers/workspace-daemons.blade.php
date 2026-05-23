<x-server-workspace-layout
    :server="$server"
    active="daemons"
    :title="__('Daemons')"
    :description="__('Supervisor is installed during server provisioning by default. If it is missing on this machine, install it here, then Dply can write configs under /etc/supervisor/conf.d and run supervisorctl reread/update.')"
    :context-site="$contextSiteModel ?? null"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4">
        <p>{{ __('This workspace manages long-running supervisord-supervised processes for this server (queue workers, websocket servers, custom long-running PHP/Node binaries). Each daemon is a config file in /etc/supervisor/conf.d that dply rewrites in full on every change; supervisorctl reread + update applies the change.') }}</p>
        <p>{{ __('State (running / stopped / fatal) is read live via supervisorctl status. Restart, stop, and start map to the matching supervisorctl verbs. The audit log records every change.') }}</p>
    </x-explainer>

    {{-- At-a-glance counts. Match the Background-group convention used by Backups,
         Schedule, and Queue workers. Numbers reflect the visible (filtered) program set. --}}
    <section class="mb-4 grid gap-3 sm:grid-cols-4">
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Programs') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $daemonsStats['total'] }}</p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Active') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-forest">{{ $daemonsStats['active'] }}</p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inactive') }}</p>
            <p class="mt-1 text-2xl font-semibold {{ $daemonsStats['inactive'] > 0 ? 'text-amber-700' : 'text-brand-ink' }}">{{ $daemonsStats['inactive'] }}</p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Total processes') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $daemonsStats['total_processes'] }}</p>
        </div>
    </section>

    @if ($siteContextUnavailable)
        <div class="rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-6 text-sm text-amber-950">
            <p class="font-semibold">{{ __('Supervisor workers are not available for this site’s runtime') }}</p>
            <p class="mt-2 leading-relaxed text-amber-900/90">
                {{ __('Managed SSH Supervisor applies to VM-hosted sites. For container or serverless runtimes, run workers on that platform instead.') }}
            </p>
            @if ($contextSiteModel)
                <a href="{{ route('sites.show', [$server, $contextSiteModel]) }}" wire:navigate class="mt-4 inline-flex font-medium text-amber-950 underline">{{ __('Back to site') }}</a>
            @endif
        </div>
    @elseif ($opsReady)
        <div @if ($server->supervisor_package_status === null) wire:init="refreshSupervisorInstallStatus" @endif>
            @include('livewire.servers.partials.daemons._banner')

            <x-server-workspace-tablist :aria-label="__('Daemons workspace sections')">
                <x-server-workspace-tab id="daemons-tab-programs" :active="$daemons_workspace_tab === 'programs'" wire:click="setDaemonsWorkspaceTab('programs')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-cpu-chip class="h-4 w-4" aria-hidden="true" />
                        {{ __('Programs') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="daemons-tab-service" :active="$daemons_workspace_tab === 'service'" wire:click="setDaemonsWorkspaceTab('service')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-server class="h-4 w-4" aria-hidden="true" />
                        {{ __('Service') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="daemons-tab-sync" :active="$daemons_workspace_tab === 'sync'" wire:click="setDaemonsWorkspaceTab('sync')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4" aria-hidden="true" />
                        {{ __('Sync') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="daemons-tab-logs" :active="$daemons_workspace_tab === 'logs'" wire:click="setDaemonsWorkspaceTab('logs')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-document-text class="h-4 w-4" aria-hidden="true" />
                        {{ __('Logs') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="daemons-tab-inspect" :active="$daemons_workspace_tab === 'inspect'" wire:click="setDaemonsWorkspaceTab('inspect')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                        {{ __('Inspect') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="daemons-tab-activity" :active="$daemons_workspace_tab === 'activity'" wire:click="setDaemonsWorkspaceTab('activity')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                        {{ __('Activity') }}
                    </span>
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setDaemonsWorkspaceTab">

            @if ($daemons_workspace_tab === 'programs')
                <x-server-workspace-tab-panel
                    id="daemons-panel-programs"
                    labelled-by="daemons-tab-programs"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.daemons.programs-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($daemons_workspace_tab === 'service')
                <x-server-workspace-tab-panel
                    id="daemons-panel-service"
                    labelled-by="daemons-tab-service"
                >
                    @include('livewire.servers.partials.daemons.service-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($daemons_workspace_tab === 'sync')
                <x-server-workspace-tab-panel
                    id="daemons-panel-sync"
                    labelled-by="daemons-tab-sync"
                    panel-class="space-y-4"
                >
                    @include('livewire.servers.partials.daemons.sync-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($daemons_workspace_tab === 'logs')
                <x-server-workspace-tab-panel
                    id="daemons-panel-logs"
                    labelled-by="daemons-tab-logs"
                >
                    @include('livewire.servers.partials.daemons.logs-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($daemons_workspace_tab === 'inspect')
                <x-server-workspace-tab-panel
                    id="daemons-panel-inspect"
                    labelled-by="daemons-tab-inspect"
                >
                    @include('livewire.servers.partials.daemons.inspect-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($daemons_workspace_tab === 'activity')
                <x-server-workspace-tab-panel
                    id="daemons-panel-activity"
                    labelled-by="daemons-tab-activity"
                >
                    @include('livewire.servers.partials.daemons.activity-tab')
                </x-server-workspace-tab-panel>
            @endif

            </div>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    @if ($contextSiteModel)
        <x-cli-snippet :commands="[
            ['label' => __('Add or update a process'), 'command' => 'dply:site:process-set '.$contextSiteModel->slug.' worker --type=worker --command=\'php artisan queue:work\' --scale=1'],
            ['label' => __('Remove a process'), 'command' => 'dply:site:process-remove '.$contextSiteModel->slug.' worker'],
            ['label' => __('Restart a process'), 'command' => 'dply:site:restart-process '.$contextSiteModel->slug.' worker'],
            ['label' => __('Show running processes'), 'command' => 'dply:site:ps '.$contextSiteModel->slug],
        ]" />
    @endif

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
