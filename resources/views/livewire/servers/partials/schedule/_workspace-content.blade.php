@php
    use App\Services\Servers\SchedulerHealthEvaluator;

    $card = 'dply-card overflow-hidden';
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
    $siteDedicatedContext = $contextSiteModel !== null;
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
        'card',
        'input',
        'chipForHealth',
        'hasStale',
        'enableTargetSite',
        'showLaravelSchedulerEnable',
        'showRailsSchedulerEnable',
        'showCustomSchedulerEnable',
        'preflight_results',
    );
@endphp

@include('livewire.servers.partials.workspace-flashes')
@include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

<x-explainer>
    <p>{{ __('Dply wraps framework schedulers in a heartbeat script so we can tell when schedule:run stops firing. Enable monitoring here; use Cron jobs for arbitrary crontab entries and Workers for long-running schedule:work daemons.') }}</p>
    <p class="mt-2 text-xs"><a href="{{ route('servers.activity', $server) }}?category=background" wire:navigate class="font-semibold text-brand-ink underline">{{ __('View background activity →') }}</a></p>
</x-explainer>

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Scheduler') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Schedulers at a glance') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                @if ($contextSiteModel && $schedulers_list_scope === 'site')
                    {{ __('Counts for :site\'s framework schedulers. Switch the list scope to “All schedulers on server” to see the whole block.', ['site' => $contextSiteModel->name]) }}
                @else
                    {{ __('Counts across every monitored framework scheduler on this server.') }}
                @endif
            </p>
        </div>
    </div>
    <dl class="grid grid-cols-2 gap-2 p-6 sm:grid-cols-4 sm:p-7">
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-brand-sage/30 bg-brand-sage/8' => $scheduleStats['total'] > 0,
            'border-brand-ink/10 bg-white' => $scheduleStats['total'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Schedulers') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $scheduleStats['total'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $scheduleStats['total']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Monitored entries') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-emerald-200 bg-emerald-50/60' => $scheduleStats['healthy'] > 0,
            'border-brand-ink/10 bg-white' => $scheduleStats['healthy'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Healthy') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $scheduleStats['healthy'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('ticking|ticking', $scheduleStats['healthy']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Recent heartbeat') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-amber-200 bg-amber-50/60' => $scheduleStats['attention'] > 0,
            'border-brand-ink/10 bg-white' => $scheduleStats['attention'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Attention') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $scheduleStats['attention'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('item|items', $scheduleStats['attention']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Waiting, stale, or missing') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-brand-sand/80 bg-brand-sand/30' => $scheduleStats['paused'] > 0,
            'border-brand-ink/10 bg-white' => $scheduleStats['paused'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Paused') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $scheduleStats['paused'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('stopped|stopped', $scheduleStats['paused']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Cron disabled in Dply') }}</p>
        </div>
    </dl>
</section>

@if ($opsReady)
    <x-server-workspace-tablist :aria-label="__('Schedule workspace sections')">
        <x-server-workspace-tab id="schedule-tab-overview" icon="heroicon-o-heart" :active="$schedule_workspace_tab === 'overview'" wire:click="setScheduleWorkspaceTab('overview')">
            {{ __('Overview') }}
            @if ($scheduleStats['attention'] > 0)
                <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums text-amber-900">{{ number_format($scheduleStats['attention']) }}</span>
            @endif
        </x-server-workspace-tab>
        <x-server-workspace-tab id="schedule-tab-schedulers" icon="heroicon-o-clock" :active="$schedule_workspace_tab === 'schedulers'" wire:click="setScheduleWorkspaceTab('schedulers')">
            {{ __('Schedulers') }}
            @if ($scheduleStats['total'] > 0)
                <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[10px] font-semibold leading-none tabular-nums text-brand-moss">{{ number_format($scheduleStats['total']) }}</span>
            @endif
        </x-server-workspace-tab>
        <x-server-workspace-tab id="schedule-tab-enable" icon="heroicon-o-plus-circle" :active="$schedule_workspace_tab === 'enable'" wire:click="setScheduleWorkspaceTab('enable')">
            {{ __('Enable') }}
        </x-server-workspace-tab>
    </x-server-workspace-tablist>

    <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setScheduleWorkspaceTab">

        @if ($schedule_workspace_tab === 'overview')
            <x-server-workspace-tab-panel id="schedule-panel-overview" labelled-by="schedule-tab-overview" panel-class="space-y-8">
                @include('livewire.servers.partials.schedule._tab-overview', $scheduleTabContext)
            </x-server-workspace-tab-panel>
        @endif

        @if ($schedule_workspace_tab === 'schedulers')
            <x-server-workspace-tab-panel id="schedule-panel-schedulers" labelled-by="schedule-tab-schedulers" panel-class="space-y-8">
                @include('livewire.servers.partials.schedule._tab-schedulers', $scheduleTabContext)
            </x-server-workspace-tab-panel>
        @endif

        @if ($schedule_workspace_tab === 'enable')
            <x-server-workspace-tab-panel id="schedule-panel-enable" labelled-by="schedule-tab-enable" panel-class="space-y-8">
                @include('livewire.servers.partials.schedule._tab-enable', $scheduleTabContext)
            </x-server-workspace-tab-panel>
        @endif

    </div>
@else
    @include('livewire.servers.partials.workspace-ops-not-ready')
@endif

@if ($contextSiteModel)
    <x-cli-snippet :commands="[
        ['label' => __('List all cron jobs (server)'), 'command' => 'dply:server:cron:list '.$server->id],
        ['label' => __('Add a schedule:run cron entry for a site'), 'command' => 'dply sites:crons:add '.$contextSiteModel->slug.' \'* * * * *\' \'php artisan schedule:run\''],
    ]" />
@endif
