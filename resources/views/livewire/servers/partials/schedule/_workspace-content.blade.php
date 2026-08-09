@php
    use App\Services\Servers\SchedulerHealthEvaluator;

    // Nested sections inside the merged Schedule card — not second page cards.
    $card = 'border-b border-brand-ink/10';
    $input = 'block w-full rounded-lg border border-brand-ink/20 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30';

    $chipForHealth = static function (?string $health): array {
        return match ($health) {
            SchedulerHealthEvaluator::STATE_HEALTHY => [
                'label' => __('Healthy'),
                'classes' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            ],
            SchedulerHealthEvaluator::STATE_WAITING => [
                'label' => __('Waiting'),
                'classes' => 'bg-sky-50 text-sky-800 ring-sky-200',
            ],
            SchedulerHealthEvaluator::STATE_AMBER => [
                'label' => __('Behind'),
                'classes' => 'bg-amber-50 text-amber-900 ring-amber-200',
            ],
            SchedulerHealthEvaluator::STATE_RED => [
                'label' => __('Not ticking'),
                'classes' => 'bg-red-50 text-red-800 ring-red-200',
            ],
            SchedulerHealthEvaluator::STATE_PAUSED => [
                'label' => __('Paused'),
                'classes' => 'bg-brand-sand/50 text-brand-mist ring-brand-ink/10',
            ],
            default => [
                'label' => __('Unknown'),
                'classes' => 'bg-brand-sand/50 text-brand-mist ring-brand-ink/10',
            ],
        };
    };

    $hasStale = ($scheduleStats['attention'] ?? 0) > 0;
    $siteDedicatedContext = $siteDedicatedContext ?? ($contextSiteModel !== null && ($scheduleSiteRouteLocked ?? false));
    $scheduleDescription = $siteDedicatedContext
        ? __('Framework schedulers for this site — tick health, cadence, and run-now.')
        : __('Framework schedulers on this server — tick health per site; nudges when one stops firing.');
    $scheduleTabContext = compact(
        'server',
        'cards',
        'allCards',
        'stats',
        'scheduleStats',
        'sites',
        'opsReady',
        'contextSite',
        'contextSiteModel',
        'siteDedicatedContext',
        'scheduleSiteRouteLocked',
        'card',
        'input',
        'chipForHealth',
        'hasStale',
        'enableTargetSite',
        'showLaravelSchedulerEnable',
        'showRailsSchedulerEnable',
        'showCustomSchedulerEnable',
        'preflight_results',
        'auditLogs',
        'logSchedulers',
        'logSelectedHeartbeat',
        'logTickOutputs',
    );
@endphp

@include('livewire.servers.partials.workspace-flashes')
@include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

<section class="dply-card min-w-0 overflow-hidden p-0">
    {{-- Dense head, matching the rest of the workspace (and the lazy
         placeholder, which has always painted this shape). --}}
    <x-workspace-panel-head
        dense
        icon="heroicon-o-calendar-days"
        :title="__('Schedule')"
        :note="$scheduleDescription"
        class="border-b border-brand-ink/10"
    />

    @php
        $glanceNote = $contextSiteModel && $schedulers_list_scope === 'site'
            ? __('Counts for :site\'s framework schedulers. Switch the list scope to “All schedulers on server” to see the whole block.', ['site' => $contextSiteModel->name])
            : __('Counts across every monitored framework scheduler on this server.');
    @endphp

    {{-- Dense head + stat strip, same treatment as the Workers snapshot and the
         Cron crontab-at-a-glance. The per-tile captions ("Monitored entries",
         "Recent heartbeat"…) become hover hints — they were a third line of
         prose on every tile. --}}
    <x-workspace-panel-head
        dense
        icon="heroicon-o-chart-bar"
        :title="__('Schedulers at a glance')"
        :note="$glanceNote"
        class="border-b border-brand-ink/10"
    />

    <x-workspace-stat-strip class="border-b border-brand-ink/10" :stats="[
        ['label' => __('Schedulers'), 'value' => $scheduleStats['total'], 'hint' => __('Monitored entries')],
        [
            'label' => __('Healthy'),
            'value' => $scheduleStats['healthy'],
            'tone' => $scheduleStats['healthy'] > 0 ? 'ok' : null,
            'hint' => __('Recent heartbeat'),
        ],
        [
            'label' => __('Attention'),
            'value' => $scheduleStats['attention'],
            'tone' => $scheduleStats['attention'] > 0 ? 'warn' : null,
            'hint' => __('Waiting, stale, or missing'),
        ],
        [
            'label' => __('Paused'),
            'value' => $scheduleStats['paused'],
            'hint' => __('Cron disabled in Dply'),
        ],
    ]" />

    @if ($opsReady)
        {{-- Console banner: hidden until there's something to show (avoids an empty ~30px band). --}}
        @php
            $scheduleBannerVisible = $scheduler_run_busy || $panel_event_message !== '';
        @endphp
        <div
            @class(['border-b border-brand-ink/10 px-3 py-2 sm:px-4', 'hidden' => ! $scheduleBannerVisible])
            wire:loading.class.remove="hidden"
            wire:target="enableSchedulerForSite,togglePause,saveCadence,runNow"
        >
            <div wire:loading.block wire:target="enableSchedulerForSite,togglePause,saveCadence,runNow">
                <x-workspace-console-banner status="running" :message="__('Applying scheduler change…')" :busy="true" />
            </div>
            @if ($scheduler_run_busy)
                <div wire:poll.1s="pollSchedulerRun">
                    <x-workspace-console-banner
                        :status="$panel_event_status"
                        :message="$panel_event_message ?: __('Run now queued…')"
                        :output="$panel_event_lines"
                        :busy="true"
                    />
                </div>
            @elseif ($panel_event_message !== '')
                <div wire:loading.remove wire:target="enableSchedulerForSite,togglePause,saveCadence,runNow,pollSchedulerRun">
                    <x-workspace-console-banner
                        :status="$panel_event_status"
                        :message="$panel_event_message"
                        :output="$panel_event_lines"
                        dismiss-action="dismissPanelBanner"
                    />
                </div>
            @endif
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Schedule workspace sections')" scroll bare class="!mb-0 w-full">
                <x-server-workspace-tab id="schedule-tab-schedulers" icon="heroicon-o-clock" :active="$schedule_workspace_tab === 'schedulers'" wire:click="setScheduleWorkspaceTab('schedulers')">
                    {{ __('Schedulers') }}
                    @if ($scheduleStats['total'] > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums text-brand-moss">{{ number_format($scheduleStats['total']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab id="schedule-tab-overview" icon="heroicon-o-heart" :active="$schedule_workspace_tab === 'overview'" wire:click="setScheduleWorkspaceTab('overview')">
                    {{ __('Overview') }}
                    @if ($scheduleStats['attention'] > 0)
                        <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums text-amber-900">{{ number_format($scheduleStats['attention']) }}</span>
                    @endif
                </x-server-workspace-tab>
                <x-server-workspace-tab id="schedule-tab-logs" icon="heroicon-o-document-text" :active="$schedule_workspace_tab === 'logs'" wire:click="setScheduleWorkspaceTab('logs')">
                    {{ __('Logs') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="schedule-tab-activity" icon="heroicon-o-clipboard-document-list" :active="$schedule_workspace_tab === 'activity'" wire:click="setScheduleWorkspaceTab('activity')">
                    {{ __('Activity') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        {{-- Skeleton swap, not a dim-and-lock: fading the outgoing tab to
             opacity-60 left the previous tab's rows legible while a different
             tab loaded, which reads as "this is your data".

             One wrapper per tab, each targeting the call WITH its argument —
             Livewire matches wire:target params, so only the tab actually being
             opened paints. The shared sites `_panel-skeleton` this replaced drew
             the same generic list for all four, so Logs and Overview resized on
             arrival. --}}
        @foreach (['schedulers', 'overview', 'logs', 'activity'] as $skeletonTab)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setScheduleWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @include('livewire.servers.partials.schedule._tab-skeleton', [
                    'tab' => $skeletonTab,
                    'rows' => $scheduleStats['total'],
                ])
            </div>
        @endforeach

        <div class="relative" wire:loading.class="hidden" wire:target="setScheduleWorkspaceTab">
            @if ($schedule_workspace_tab === 'overview')
                <x-server-workspace-tab-panel id="schedule-panel-overview" labelled-by="schedule-tab-overview" panel-class="min-w-0">
                    @include('livewire.servers.partials.schedule._tab-overview', $scheduleTabContext)
                </x-server-workspace-tab-panel>
            @endif

            @if ($schedule_workspace_tab === 'schedulers')
                <x-server-workspace-tab-panel id="schedule-panel-schedulers" labelled-by="schedule-tab-schedulers" panel-class="min-w-0">
                    @include('livewire.servers.partials.schedule._tab-schedulers', $scheduleTabContext)
                </x-server-workspace-tab-panel>
            @endif

            @if ($schedule_workspace_tab === 'logs')
                <x-server-workspace-tab-panel id="schedule-panel-logs" labelled-by="schedule-tab-logs" panel-class="min-w-0">
                    @include('livewire.servers.partials.schedule._tab-logs', $scheduleTabContext)
                </x-server-workspace-tab-panel>
            @endif

            @if ($schedule_workspace_tab === 'activity')
                <x-server-workspace-tab-panel id="schedule-panel-activity" labelled-by="schedule-tab-activity" panel-class="min-w-0">
                    @include('livewire.servers.partials.schedule._tab-activity', $scheduleTabContext)
                </x-server-workspace-tab-panel>
            @endif
        </div>

        {{-- Enable scheduler modal (replaces the old Enable tab; opened from the Schedulers header). --}}
        <x-modal name="schedule-enable" maxWidth="2xl">
            @include('livewire.servers.partials.schedule._enable-modal-body', $scheduleTabContext)
        </x-modal>
    @else
        <div class="px-3 py-4 sm:px-4">
            @include('livewire.servers.partials.workspace-ops-not-ready')
        </div>
    @endif

    @if ($contextSiteModel)
        @php
            $scheduleCliCommands = [
                ['label' => __('List all cron jobs (server)'), 'command' => 'dply:server:cron:list '.$server->id],
            ];
            if ($enableTargetSite?->isLaravelFrameworkDetected()) {
                $scheduleCliCommands[] = [
                    'label' => __('Add a schedule:run cron entry for this site'),
                    'command' => 'dply sites:crons:add '.$contextSiteModel->slug.' \'* * * * *\' \'php artisan schedule:run\'',
                ];
            } elseif ($enableTargetSite?->isRailsFrameworkDetected()) {
                $scheduleCliCommands[] = [
                    'label' => __('Add a whenever cron entry for this site'),
                    'command' => 'dply sites:crons:add '.$contextSiteModel->slug.' \'* * * * *\' \'bundle exec whenever --update-crontab\'',
                ];
            } else {
                $scheduleCliCommands[] = [
                    'label' => __('Add a scheduler cron entry for this site'),
                    'command' => 'dply sites:crons:add '.$contextSiteModel->slug.' \'* * * * *\' \'<command>\'',
                ];
            }
        @endphp
        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:px-4">
            <x-cli-snippet :commands="$scheduleCliCommands" />
        </div>
    @endif
</section>
