@include('livewire.servers.partials.workspace-flashes')
@include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

<x-explainer>
    <p>{{ __('This workspace manages long-running supervisord-supervised processes for this server (queue workers, websocket servers, custom long-running PHP/Node binaries). Each daemon is a config file in /etc/supervisor/conf.d that dply rewrites in full on every change; supervisorctl reread + update applies the change.') }}</p>
    <p>{{ __('State (running / stopped / fatal) is read live via supervisorctl status. The worker health block above rolls up the scheduled health snapshot — refresh it before restarting workers or syncing config when drift is detected.') }}</p>
</x-explainer>

@if ($contextSiteModel ?? null)
    @php $daemonSuggestions = \App\Support\Sites\SiteDaemonAdvisor::suggestions($contextSiteModel); @endphp
    @if ($daemonSuggestions !== [])
        <div class="mt-4">
            <x-site-daemon-suggestions
                :suggestions="$daemonSuggestions"
                mode="interactive"
                :schedule-url="route('sites.cron', ['server' => $server, 'site' => $contextSiteModel])"
            />
        </div>
    @endif
@endif

@if ($daemonSloReport ?? null)
    @include('livewire.servers.partials.daemons._slo-overview')
@else
{{-- At-a-glance counts. Match the Background-group convention used by Backups,
     Schedule. Numbers reflect the visible (filtered) program set. --}}
<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Supervisor') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Programs at a glance') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    @if ($contextSiteModel ?? null)
                        {{ __('Counts for :site\'s supervisord programs. Switch the list scope to “All programs on server” to see the whole block.', ['site' => $contextSiteModel->name]) }}
                    @else
                        {{ __('Counts across the dply-managed supervisord block on this server.') }}
                    @endif
                </p>
            </div>
    </div>
    <dl class="grid grid-cols-2 gap-2 p-6 sm:grid-cols-4 sm:p-7">
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-brand-sage/30 bg-brand-sage/8' => $daemonsStats['total'] > 0,
            'border-brand-ink/10 bg-white' => $daemonsStats['total'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Programs') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $daemonsStats['total'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $daemonsStats['total']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Configured units') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-emerald-200 bg-emerald-50/60' => $daemonsStats['active'] > 0,
            'border-brand-ink/10 bg-white' => $daemonsStats['active'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Active') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $daemonsStats['active'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('running|running', $daemonsStats['active']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Currently supervised') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-amber-200 bg-amber-50/60' => $daemonsStats['inactive'] > 0,
            'border-brand-ink/10 bg-white' => $daemonsStats['inactive'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inactive') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $daemonsStats['inactive'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('stopped|stopped', $daemonsStats['inactive']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Not currently running') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-brand-sage/30 bg-brand-sage/8' => $daemonsStats['total_processes'] > 0,
            'border-brand-ink/10 bg-white' => $daemonsStats['total_processes'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Processes') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $daemonsStats['total_processes'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('worker|workers', $daemonsStats['total_processes']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Sum of numprocs') }}</p>
        </div>
    </dl>
</section>
@endif

@if ($siteContextUnavailable)
    <section class="dply-card overflow-hidden border-amber-200">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-no-symbol class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Unavailable') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Supervisor workers are not available for this site’s runtime') }}</h3>
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
    </section>
@elseif ($opsReady)
    <div @if ($server->supervisor_package_status === null) wire:init="refreshSupervisorInstallStatus" @endif>
        @include('livewire.servers.partials.daemons._banner')

        <x-server-workspace-tablist id="daemons-workspace-tablist" :aria-label="__('Workers workspace sections')">
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
        ['label' => __('Add or update a process'), 'command' => 'dply sites:workers:set '.$contextSiteModel->slug.' worker --type=worker --command=\'php artisan queue:work\' --scale=1'],
        ['label' => __('Remove a process'), 'command' => 'dply sites:workers:remove '.$contextSiteModel->slug.' worker'],
        ['label' => __('Restart a process'), 'command' => 'dply sites:workers:restart '.$contextSiteModel->slug.' worker'],
        ['label' => __('Show running processes'), 'command' => 'dply sites:workers:ps '.$contextSiteModel->slug],
    ]" />
@endif
