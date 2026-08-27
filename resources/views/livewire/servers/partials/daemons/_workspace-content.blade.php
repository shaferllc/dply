@php
    $workersDescription = ($siteDedicatedContext ?? false)
        ? __('Supervisor-managed worker processes for this site (queue workers, websocket servers, long-running binaries).')
        : __('Supervisor-managed queue workers and background daemons — health snapshot, program CRUD, sync, and logs.');
@endphp

@include('livewire.servers.partials.workspace-flashes')
@include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

<section class="dply-card min-w-0 overflow-hidden p-0">
    {{-- Dense head, matching the rest of the workspace (and the lazy placeholder,
         which has always painted this shape). --}}
    <x-workspace-panel-head
        dense
        icon="heroicon-o-server-stack"
        :title="__('Workers')"
        :note="$workersDescription"
        class="border-b border-brand-ink/10"
    />

    @if ($contextSiteModel ?? null)
        {{-- Workers shows only what it owns. Horizon and queue:work belong to
             Queue, the scheduler to Schedule — a Set-up button has to create the
             thing on the page you will look for it on. --}}
        @php $daemonSuggestions = \App\Support\Sites\SiteDaemonAdvisor::onlyForSurface(
            \App\Support\Sites\SiteDaemonAdvisor::suggestions($contextSiteModel),
            \App\Support\Sites\SiteDaemonAdvisor::SURFACE_WORKERS,
        ); @endphp
        @php $daemonSuggestionsDismissed = \App\Support\Sites\SiteDaemonAdvisor::dismissedCount($contextSiteModel); @endphp
        @if ($daemonSuggestions !== [] || $daemonSuggestionsDismissed > 0)
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <x-site-daemon-suggestions
                    :dismissed-count="$daemonSuggestionsDismissed"
                    :suggestions="$daemonSuggestions"
                    mode="interactive"
                    :schedule-url="route('servers.cron', ['server' => $server, 'site' => $contextSiteModel])"
                />
            </div>
        @endif
    @endif

    @if ($daemonSloReport ?? null)
        @include('livewire.servers.partials.daemons._slo-overview')
    @else
        {{-- At-a-glance counts. Same dense head + stat strip the SLO branch uses,
             so the two paths don't look like different pages. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-cpu-chip"
            :title="__('Programs at a glance')"
            :note="($contextSiteModel ?? null)
                ? __('Counts for :site\'s supervisord programs. Switch the list scope to “All programs on server” to see the whole block.', ['site' => $contextSiteModel->name])
                : __('Counts across the dply-managed supervisord block on this server.')"
            class="border-b border-brand-ink/10"
        />

        <x-workspace-stat-strip class="border-b border-brand-ink/10" :stats="[
            ['label' => __('Programs'), 'value' => $daemonsStats['total'], 'hint' => __('Configured units')],
            [
                'label' => __('Active'),
                'value' => $daemonsStats['active'],
                'tone' => $daemonsStats['active'] > 0 ? 'ok' : null,
                'hint' => __('Currently supervised'),
            ],
            [
                'label' => __('Inactive'),
                'value' => $daemonsStats['inactive'],
                'tone' => $daemonsStats['inactive'] > 0 ? 'warn' : null,
                'hint' => __('Not currently running'),
            ],
            ['label' => __('Processes'), 'value' => $daemonsStats['total_processes'], 'hint' => __('Sum of numprocs')],
        ]" />
    @endif

    @if ($siteContextUnavailable)
        <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
            <div class="flex items-start gap-3">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-no-symbol class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Unavailable') }}</p>
                    <h3 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Supervisor workers are not available for this site’s runtime') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Managed SSH Supervisor applies to VM-hosted sites. For container or serverless runtimes, run workers on that platform instead.') }}
                    </p>
                    @if ($contextSiteModel)
                        <a href="{{ route('sites.show', [$server, $contextSiteModel]) }}" wire:navigate class="mt-3 inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Back to site') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($opsReady)
        <div @if ($server->supervisor_package_status === null) wire:init="refreshSupervisorInstallStatus" @endif>
            {{-- The banner is empty most of the time, but this wrapper still drew
                 its border and padding — an unexplained ~30px band between the
                 snapshot strip and the tab row. Hidden until there's something to
                 show; Livewire un-hides it for the duration of an inline SSH op
                 via the class.remove below. --}}
            @php
                $daemonBannerVisible = $supervisor_installed === null
                    || $supervisor_installed === false
                    || $daemon_op_busy
                    || $panel_event_message !== '';
            @endphp
            <div
                @class(['border-b border-brand-ink/10 px-4 py-2.5 sm:px-5', 'hidden' => ! $daemonBannerVisible])
                wire:loading.class.remove="hidden"
                wire:target="startOneProgram,stopOneProgram,restartOneProgram,supervisorServiceAction"
            >
                @include('livewire.servers.partials.daemons._banner')
            </div>

            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-server-workspace-tablist id="daemons-workspace-tablist" :aria-label="__('Workers workspace sections')" scroll bare class="!mb-0 w-full">
                    <x-server-workspace-tab id="daemons-tab-programs" icon="heroicon-o-cpu-chip" :active="$daemons_workspace_tab === 'programs'" wire:click="setDaemonsWorkspaceTab('programs')">
                        {{ __('Programs') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="daemons-tab-service" icon="heroicon-o-server" :active="$daemons_workspace_tab === 'service'" wire:click="setDaemonsWorkspaceTab('service')">
                        {{ __('Service') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="daemons-tab-sync" icon="heroicon-o-arrow-path-rounded-square" :active="$daemons_workspace_tab === 'sync'" wire:click="setDaemonsWorkspaceTab('sync')">
                        {{ __('Sync') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="daemons-tab-logs" icon="heroicon-o-document-text" :active="$daemons_workspace_tab === 'logs'" wire:click="setDaemonsWorkspaceTab('logs')">
                        {{ __('Logs') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="daemons-tab-inspect" icon="heroicon-o-magnifying-glass" :active="$daemons_workspace_tab === 'inspect'" wire:click="setDaemonsWorkspaceTab('inspect')">
                        {{ __('Inspect') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab id="daemons-tab-activity" icon="heroicon-o-clock" :active="$daemons_workspace_tab === 'activity'" wire:click="setDaemonsWorkspaceTab('activity')">
                        {{ __('Activity') }}
                    </x-server-workspace-tab>
                </x-server-workspace-tablist>
            </div>

            {{-- Tab-switch skeletons. setDaemonsWorkspaceTab() round-trips, and
                 dimming the outgoing panel to 60% (what this used to do) reads as
                 a frozen page rather than an arriving one.

                 One wrapper per tab, each targeting the call WITH its argument —
                 Livewire matches wire:target params, so only the tab actually
                 being opened paints. That beats a single shared skeleton here:
                 $daemons_workspace_tab still holds the OUTGOING tab during the
                 request, and with six tabs there's no inverse to derive.

                 wire:loading.block, not bare wire:loading, or the skeleton
                 shrink-wraps to inline-block. --}}
            @foreach (['programs', 'service', 'sync', 'logs', 'inspect', 'activity'] as $skeletonTab)
                <div wire:loading.block wire:target="setDaemonsWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                    <span class="sr-only">{{ __('Loading section…') }}</span>
                    @include('livewire.servers.partials.daemons._tab-skeleton', [
                        'tab' => $skeletonTab,
                        'rows' => max(1, min(6, $server->supervisorPrograms->count() ?: 4)),
                    ])
                </div>
            @endforeach

            <div class="relative" wire:loading.remove wire:target="setDaemonsWorkspaceTab">
                @if ($daemons_workspace_tab === 'programs')
                    <x-server-workspace-tab-panel id="daemons-panel-programs" labelled-by="daemons-tab-programs" panel-class="min-w-0">
                        @include('livewire.servers.partials.daemons.programs-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($daemons_workspace_tab === 'service')
                    <x-server-workspace-tab-panel id="daemons-panel-service" labelled-by="daemons-tab-service" panel-class="min-w-0">
                        @include('livewire.servers.partials.daemons.service-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($daemons_workspace_tab === 'sync')
                    <x-server-workspace-tab-panel id="daemons-panel-sync" labelled-by="daemons-tab-sync" panel-class="min-w-0">
                        @include('livewire.servers.partials.daemons.sync-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($daemons_workspace_tab === 'logs')
                    <x-server-workspace-tab-panel id="daemons-panel-logs" labelled-by="daemons-tab-logs" panel-class="min-w-0">
                        @include('livewire.servers.partials.daemons.logs-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($daemons_workspace_tab === 'inspect')
                    <x-server-workspace-tab-panel id="daemons-panel-inspect" labelled-by="daemons-tab-inspect" panel-class="min-w-0">
                        @include('livewire.servers.partials.daemons.inspect-tab')
                    </x-server-workspace-tab-panel>
                @endif

                @if ($daemons_workspace_tab === 'activity')
                    <x-server-workspace-tab-panel id="daemons-panel-activity" labelled-by="daemons-tab-activity" panel-class="min-w-0">
                        @include('livewire.servers.partials.daemons.activity-tab')
                    </x-server-workspace-tab-panel>
                @endif
            </div>
        </div>
    @else
        <div class="px-5 py-6 sm:px-6">
            @include('livewire.servers.partials.workspace-ops-not-ready')
        </div>
    @endif

    @if ($contextSiteModel)
        <div class="border-t border-brand-ink/10 px-5 py-5 sm:px-6">
            <x-cli-snippet :commands="[
                ['label' => __('Add or update a process'), 'command' => 'dply sites:workers:set '.$contextSiteModel->slug.' worker --type=worker --command=\'php artisan queue:work\' --scale=1'],
                ['label' => __('Remove a process'), 'command' => 'dply sites:workers:remove '.$contextSiteModel->slug.' worker'],
                ['label' => __('Restart a process'), 'command' => 'dply sites:workers:restart '.$contextSiteModel->slug.' worker'],
                ['label' => __('Show running processes'), 'command' => 'dply sites:workers:ps '.$contextSiteModel->slug],
            ]" />
        </div>
    @endif
</section>
