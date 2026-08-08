@include('livewire.servers.partials.workspace-flashes')
@include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

<section class="dply-card min-w-0 overflow-hidden p-0">
    {{-- Dense head, matching the rest of the workspace (and the lazy
         placeholder, which has always painted this shape). --}}
    <x-workspace-panel-head
        dense
        icon="heroicon-o-clock"
        :title="__('Cron jobs')"
        :note="__('Schedule commands in the Dply-managed crontab block for this server.')"
        class="border-b border-brand-ink/10"
    />

    @if ($opsReady && $server->organization?->cron_maintenance_until && now()->lt($server->organization->cron_maintenance_until))
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-[11px] text-amber-900 sm:px-5">
            <x-heroicon-m-wrench class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span class="font-semibold">{{ __('Cron pause active.') }}</span>
            {{ __('Managed cron lines are not installed on servers until :time.', ['time' => $server->organization->cron_maintenance_until->timezone(config('app.timezone'))->format('Y-m-d H:i T')]) }}
            @if (filled($server->organization->cron_maintenance_note))
                {{ $server->organization->cron_maintenance_note }}
            @endif
        </p>
    @endif

    @if ($siteContextUnavailable)
        <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
            <div class="flex items-start gap-3">
                <x-icon-badge tone="amber">
                    <x-heroicon-o-no-symbol class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Unavailable') }}</p>
                    <h3 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Cron jobs are not available for this site’s runtime') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Managed SSH crontab applies to VM-hosted sites. For container or serverless runtimes, use that platform’s scheduler or workers instead.') }}
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
        <div
            id="dply-server-cron-run-context"
            class="hidden"
            aria-hidden="true"
            data-server-id="{{ $server->id }}"
            data-subscribe="{{ $cronRunEchoSubscribable ? '1' : '0' }}"
        ></div>

        {{-- The banner is empty most of the time, but this wrapper still drew its
             border and padding — an unexplained ~30px band between two heads.
             Hidden until there's something to show; Livewire un-hides it for the
             duration of a sync via the class.remove below. --}}
        <div
            @class(['border-b border-brand-ink/10 px-4 py-2.5 sm:px-5', 'hidden' => $panel_event_message === ''])
            wire:loading.class.remove="hidden"
            wire:target="syncCronJobs"
        >
            @include('livewire.servers.partials.cron._banner')
        </div>

        {{-- Crontab at a glance: total / enabled / disabled / unsynced. Dense
             head + stat strip, same treatment as the Workers snapshot. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-chart-bar"
            :title="__('Crontab at a glance')"
            :note="(! empty($cronSummaryScopedToSite) && ($contextSiteModel ?? null))
                ? __('Counts for :site\'s jobs in the dply-managed block. Switch the list scope to “All jobs on server” to see the whole crontab.', ['site' => $contextSiteModel->name])
                : __('Counts across the dply-managed block on this server.')"
            class="border-b border-brand-ink/10"
        />

        <x-workspace-stat-strip class="border-b border-brand-ink/10" :stats="[
            ['label' => __('Cron jobs'), 'value' => $cronJobCount, 'hint' => __('Managed by dply')],
            [
                'label' => __('Enabled'),
                'value' => $enabledCronJobCount,
                'tone' => $enabledCronJobCount > 0 ? 'ok' : null,
                'hint' => __('Will fire on schedule'),
            ],
            [
                'label' => __('Disabled'),
                'value' => $disabledCronJobCount,
                'tone' => $disabledCronJobCount > 0 ? 'warn' : null,
                'hint' => __('Held — won’t fire'),
            ],
            [
                'label' => __('Unsynced'),
                'value' => $unsyncedCronCount,
                'tone' => $unsyncedCronCount > 0 ? 'bad' : null,
                'hint' => __('Dply ↔ server mismatch'),
            ],
        ]" />

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Cron workspace sections')" scroll bare class="!mb-0 w-full">
                <x-server-workspace-tab id="cron-tab-jobs" :active="$cron_workspace_tab === 'jobs'" wire:click="setCronWorkspaceTab('jobs')" icon="heroicon-o-list-bullet">
                    {{ __('Jobs') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-history" :active="$cron_workspace_tab === 'history'" wire:click="setCronWorkspaceTab('history')" icon="heroicon-o-clock">
                    {{ __('History') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-inspect" :active="$cron_workspace_tab === 'inspect'" wire:click="setCronWorkspaceTab('inspect')" icon="heroicon-o-command-line">
                    {{ __('Inspect') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="cron-tab-templates" :active="$cron_workspace_tab === 'templates'" wire:click="setCronWorkspaceTab('templates')" icon="heroicon-o-document-duplicate">
                    {{ __('Templates') }}
                </x-server-workspace-tab>
                @if ($canUpdateOrg)
                    <x-server-workspace-tab id="cron-tab-maintenance" :active="$cron_workspace_tab === 'maintenance'" wire:click="setCronWorkspaceTab('maintenance')" icon="heroicon-o-wrench">
                        {{ __('Cron pause') }}
                    </x-server-workspace-tab>
                @endif
            </x-server-workspace-tablist>
        </div>

        {{-- Tab-switch skeletons. setCronWorkspaceTab() round-trips, and dimming
             the outgoing panel to 60% (what this used to do) reads as a frozen
             page rather than an arriving one.

             One wrapper per tab, each targeting the call WITH its argument —
             Livewire matches wire:target params, so only the tab actually being
             opened paints. $cron_workspace_tab still holds the OUTGOING tab
             during the request, so it can't shape a single shared skeleton.

             wire:loading.block, not bare wire:loading, or the skeleton
             shrink-wraps to inline-block. --}}
        @foreach (['jobs', 'history', 'inspect', 'templates', 'maintenance'] as $skeletonTab)
            @continue($skeletonTab === 'maintenance' && ! $canUpdateOrg)
            <div wire:loading.block wire:target="setCronWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @include('livewire.servers.partials.cron._tab-skeleton', [
                    'tab' => $skeletonTab,
                    'rows' => $cronJobCount,
                ])
            </div>
        @endforeach

        <div class="relative" wire:loading.remove wire:target="setCronWorkspaceTab">
            @if ($cron_workspace_tab === 'jobs')
                <x-server-workspace-tab-panel id="cron-panel-jobs" labelled-by="cron-tab-jobs" panel-class="min-w-0">
                    @include('livewire.servers.partials.cron.jobs-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'history')
                <x-server-workspace-tab-panel id="cron-panel-history" labelled-by="cron-tab-history" panel-class="min-w-0">
                    @include('livewire.servers.partials.cron.history-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'inspect')
                <x-server-workspace-tab-panel id="cron-panel-inspect" labelled-by="cron-tab-inspect" panel-class="min-w-0">
                    @include('livewire.servers.partials.cron.inspect-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($cron_workspace_tab === 'templates')
                <x-server-workspace-tab-panel id="cron-panel-templates" labelled-by="cron-tab-templates" panel-class="min-w-0">
                    @include('livewire.servers.partials.cron.templates-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($canUpdateOrg && $cron_workspace_tab === 'maintenance')
                <x-server-workspace-tab-panel id="cron-panel-maintenance" labelled-by="cron-tab-maintenance" panel-class="min-w-0">
                    @include('livewire.servers.partials.cron.maintenance-tab')
                </x-server-workspace-tab-panel>
            @endif
        </div>
    @else
        <div class="px-5 py-6 sm:px-6">
            @include('livewire.servers.partials.workspace-ops-not-ready')
        </div>
    @endif

    @if ($contextSiteModel)
        <div class="border-t border-brand-ink/10 px-5 py-5 sm:px-6">
            <x-cli-snippet tone="stub" />
        </div>
    @endif
</section>
