<x-server-workspace-layout
    :server="$server"
    active="cron"
    :title="__('Cron jobs')"
    :description="__('Schedule commands in the Dply-managed crontab block for this server.')"
    :context-site="$contextSiteModel ?? null"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4">
        <p>{{ __('Cron jobs scheduled here are written into a dply-managed block in the server\'s crontab. The block is rewritten in full on every change — nothing else in the crontab is touched. Use the existing crontab outside the block for things you don\'t want dply to manage.') }}</p>
        <p>{{ __('"Run now" queues an immediate execution of a job, streams output back over SSH, and records the result. The job\'s schedule keeps firing on its normal cadence in parallel; "Run now" is independent.') }}</p>
    </x-explainer>

    @if ($opsReady && $server->organization?->cron_maintenance_until && now()->lt($server->organization->cron_maintenance_until))
        <div class="mb-4 rounded-2xl border border-amber-400/90 bg-amber-50 px-5 py-4 text-sm text-amber-950">
            <p class="font-semibold">{{ __('Cron maintenance window active') }}</p>
            <p class="mt-1 text-amber-900/90">
                {{ __('Managed cron lines are not installed on servers until :time.', ['time' => $server->organization->cron_maintenance_until->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
                @if (filled($server->organization->cron_maintenance_note))
                    {{ $server->organization->cron_maintenance_note }}
                @endif
            </p>
        </div>
    @endif

    @if ($siteContextUnavailable)
        <div class="rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-6 text-sm text-amber-950">
            <p class="font-semibold">{{ __('Cron jobs are not available for this site’s runtime') }}</p>
            <p class="mt-2 leading-relaxed text-amber-900/90">
                {{ __('Managed SSH crontab applies to VM-hosted sites. For container or serverless runtimes, use that platform’s scheduler or workers instead.') }}
            </p>
            @if ($contextSiteModel)
                <a href="{{ route('sites.show', [$server, $contextSiteModel]) }}" wire:navigate class="mt-4 inline-flex font-medium text-amber-950 underline">{{ __('Back to site') }}</a>
            @endif
        </div>
    @elseif ($opsReady)
        <div
            id="dply-server-cron-run-context"
            class="hidden"
            aria-hidden="true"
            data-server-id="{{ $server->id }}"
            data-subscribe="{{ $cronRunEchoSubscribable ? '1' : '0' }}"
        ></div>

        <div class="space-y-6">
            @include('livewire.servers.partials.cron._banner')

            <section class="grid gap-3 sm:grid-cols-4">
                <div class="dply-card p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cron jobs') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $cronJobCount }}</p>
                </div>
                <div class="dply-card p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Enabled') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-brand-forest">{{ $enabledCronJobCount }}</p>
                </div>
                <div class="dply-card p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Disabled') }}</p>
                    <p class="mt-1 text-2xl font-semibold {{ $disabledCronJobCount > 0 ? 'text-amber-700' : 'text-brand-ink' }}">{{ $disabledCronJobCount }}</p>
                </div>
                <div class="dply-card p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Unsynced') }}</p>
                    <p class="mt-1 text-2xl font-semibold {{ $unsyncedCronCount > 0 ? 'text-red-700' : 'text-brand-ink' }}">{{ $unsyncedCronCount }}</p>
                </div>
            </section>

            <x-server-workspace-tablist :aria-label="__('Cron workspace sections')">
                <x-server-workspace-tab id="cron-tab-jobs" :active="$cron_workspace_tab === 'jobs'" wire:click="setCronWorkspaceTab('jobs')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-list-bullet class="h-4 w-4" aria-hidden="true" />
                        {{ __('Jobs') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-history" :active="$cron_workspace_tab === 'history'" wire:click="setCronWorkspaceTab('history')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                        {{ __('History') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-inspect" :active="$cron_workspace_tab === 'inspect'" wire:click="setCronWorkspaceTab('inspect')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-command-line class="h-4 w-4" aria-hidden="true" />
                        {{ __('Inspect') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-templates" :active="$cron_workspace_tab === 'templates'" wire:click="setCronWorkspaceTab('templates')">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-document-duplicate class="h-4 w-4" aria-hidden="true" />
                        {{ __('Templates') }}
                    </span>
                </x-server-workspace-tab>
                @if ($canUpdateOrg)
                    <x-server-workspace-tab id="cron-tab-maintenance" :active="$cron_workspace_tab === 'maintenance'" wire:click="setCronWorkspaceTab('maintenance')">
                        <span class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-wrench class="h-4 w-4" aria-hidden="true" />
                            {{ __('Maintenance') }}
                        </span>
                    </x-server-workspace-tab>
                @endif
            </x-server-workspace-tablist>

            <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setCronWorkspaceTab">

            @if ($cron_workspace_tab === 'jobs')
                <x-server-workspace-tab-panel
                    id="cron-panel-jobs"
                    labelled-by="cron-tab-jobs"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.jobs-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'history')
                <x-server-workspace-tab-panel
                    id="cron-panel-history"
                    labelled-by="cron-tab-history"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.history-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'inspect')
                <x-server-workspace-tab-panel
                    id="cron-panel-inspect"
                    labelled-by="cron-tab-inspect"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.inspect-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'templates')
                <x-server-workspace-tab-panel
                    id="cron-panel-templates"
                    labelled-by="cron-tab-templates"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.templates-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($canUpdateOrg && $cron_workspace_tab === 'maintenance')
                <x-server-workspace-tab-panel
                    id="cron-panel-maintenance"
                    labelled-by="cron-tab-maintenance"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.cron.maintenance-tab')
                </x-server-workspace-tab-panel>
            @endif

            </div>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    @if ($contextSiteModel)
        <x-cli-snippet tone="stub" />
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.cron._modals')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
