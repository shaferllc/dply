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
        ? __('Framework schedulers for this site (schedule:run tick health, cadence, run-now).')
        : __('Framework schedulers running on this server. Tracks tick health for each scheduler; nudges you when one stops firing.');
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
    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Schedule') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $scheduleDescription }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="border-b border-brand-ink/10">
        {{-- The "SCHEDULER" eyebrow restated the page hero directly above it. --}}
        @php
            $glanceNote = $contextSiteModel && $schedulers_list_scope === 'site'
                ? __('Counts for :site\'s framework schedulers. Switch the list scope to “All schedulers on server” to see the whole block.', ['site' => $contextSiteModel->name])
                : __('Counts across every monitored framework scheduler on this server.');
        @endphp
        <x-workspace-panel-head
            icon="heroicon-o-chart-bar"
            :title="__('Schedulers at a glance')"
            :note="$glanceNote"
            class="border-b border-brand-ink/10"
        />
        <dl class="grid grid-cols-2 gap-2 px-5 py-3 sm:grid-cols-4 sm:px-6">
            {{-- Two lines per tile, not three: the caption ("Monitored entries",
                 "Recent heartbeat"…) now rides beside the number instead of on
                 its own row under it. --}}
            @foreach ([
                ['label' => __('Schedulers'), 'value' => $scheduleStats['total'], 'unit' => __('total'), 'caption' => __('Monitored entries'), 'tone' => 'border-brand-sage/30 bg-brand-sage/8'],
                ['label' => __('Healthy'), 'value' => $scheduleStats['healthy'], 'unit' => __('ticking'), 'caption' => __('Recent heartbeat'), 'tone' => 'border-emerald-200 bg-emerald-50/60'],
                ['label' => __('Attention'), 'value' => $scheduleStats['attention'], 'unit' => trans_choice('item|items', $scheduleStats['attention']), 'caption' => __('Waiting, stale, or missing'), 'tone' => 'border-amber-200 bg-amber-50/60'],
                ['label' => __('Paused'), 'value' => $scheduleStats['paused'], 'unit' => __('stopped'), 'caption' => __('Cron disabled in Dply'), 'tone' => 'border-brand-sand/80 bg-brand-sand/30'],
            ] as $stat)
                <div @class([
                    'rounded-xl border px-3 py-2',
                    $stat['tone'] => $stat['value'] > 0,
                    'border-brand-ink/10 bg-brand-sand/15' => $stat['value'] === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $stat['label'] }}</dt>
                    <dd class="mt-0.5 flex flex-wrap items-baseline gap-x-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</span>
                        <span class="text-[11px] text-brand-moss">{{ $stat['unit'] }}</span>
                        <span class="text-[11px] text-brand-mist">· {{ $stat['caption'] }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    @if ($opsReady)
        {{-- Console banner for scheduler actions (enable, pause, run-now, cadence save) --}}
        <div wire:loading wire:target="enableSchedulerForSite,togglePause,saveCadence,runNow" class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            <x-workspace-console-banner status="running" :message="__('Applying scheduler change…')" :busy="true" />
        </div>
        @if ($panel_event_message !== '')
            <div wire:loading.remove wire:target="enableSchedulerForSite,togglePause,saveCadence,runNow,pollSchedulerRun" class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                <x-workspace-console-banner
                    :status="$panel_event_status"
                    :message="$panel_event_message"
                    :output="$panel_event_lines"
                    dismiss-action="dismissPanelBanner"
                />
            </div>
        @endif

        {{-- Run-now live streaming — poll the job's cached output until it finishes --}}
        @if ($scheduler_run_busy)
            <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6" wire:poll.1s="pollSchedulerRun">
                <x-workspace-console-banner
                    :status="$panel_event_status"
                    :message="$panel_event_message ?: __('Run now queued…')"
                    :output="$panel_event_lines"
                    :busy="true"
                />
            </div>
        @endif

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Schedule workspace sections')" scroll class="!mb-0 w-full border-0 bg-transparent p-0 shadow-none">
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
             tab loaded, which reads as "this is your data". Matches the
             Repository / Deployments / Laravel / Monitor / Notifications / Logs
             tab strips. --}}
        <div class="hidden" wire:loading.class.remove="hidden" wire:target="setScheduleWorkspaceTab">
            @include('livewire.sites.partials._panel-skeleton')
        </div>

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
        <div class="px-5 py-6 sm:px-6">
            @include('livewire.servers.partials.workspace-ops-not-ready')
        </div>
    @endif

    @if ($contextSiteModel)
        <div class="border-t border-brand-ink/10 px-5 py-2.5 sm:px-6">
            <x-cli-snippet :commands="[
                ['label' => __('List all cron jobs (server)'), 'command' => 'dply:server:cron:list '.$server->id],
                ['label' => __('Add a schedule:run cron entry for a site'), 'command' => 'dply sites:crons:add '.$contextSiteModel->slug.' \'* * * * *\' \'php artisan schedule:run\''],
            ]" />
        </div>
    @endif
</section>
