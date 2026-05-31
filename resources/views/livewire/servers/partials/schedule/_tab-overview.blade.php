@php
    use App\Services\Servers\SchedulerHealthEvaluator;

    $attentionCards = array_values(array_filter(
        $cards,
        static function (array $cardData): bool {
            if (in_array($cardData['state'], ['detected_unmonitored', 'no_scheduler'], true)) {
                return true;
            }

            return in_array($cardData['health'], [
                SchedulerHealthEvaluator::STATE_AMBER,
                SchedulerHealthEvaluator::STATE_RED,
                SchedulerHealthEvaluator::STATE_WAITING,
            ], true);
        },
    ));
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-heart class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Status') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                @if ($hasStale)
                    {{ __('Schedulers need attention') }}
                @elseif ($scheduleStats['total'] === 0 && $scheduleStats['attention'] === 0)
                    {{ __('No schedulers yet') }}
                @else
                    {{ __('All schedulers look good') }}
                @endif
            </h3>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                @if ($hasStale)
                    {{ __('One or more schedulers haven\'t ticked recently. Open the Schedulers tab to investigate or use Run now to verify.') }}
                @elseif ($scheduleStats['total'] === 0 && $scheduleStats['attention'] === 0)
                    {{ __('Enable a framework scheduler for a site to start tracking tick health.') }}
                @else
                    {{ __('Every tracked scheduler is healthy or waiting for its first tick.') }}
                @endif
            </p>
        </div>
    </div>

    @if ($attentionCards !== [])
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($attentionCards as $cardData)
                @php
                    $site = $cardData['site'];
                    $chip = $chipForHealth($cardData['health']);
                @endphp
                <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            @if (! $siteDedicatedContext || $schedulers_list_scope === 'all')
                                <p class="text-sm font-semibold text-brand-ink">{{ $site->name }}</p>
                            @endif
                            @if ($cardData['kind'])
                                <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $cardData['kind'] }}</span>
                            @endif
                            @if ($cardData['health'] !== null)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $chip['classes'] }}">{{ $chip['label'] }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-brand-moss">
                            @if ($cardData['state'] === 'no_scheduler')
                                {{ __('No scheduler enabled for this site.') }}
                            @elseif ($cardData['state'] === 'detected_unmonitored')
                                {{ __('Scheduler detected in crontab but not monitored by Dply.') }}
                            @elseif ($cardData['last_tick_at'])
                                {{ __('Last tick :ago', ['ago' => $cardData['last_tick_at']->diffForHumans()]) }}
                            @else
                                {{ __('Waiting for the first heartbeat tick.') }}
                            @endif
                        </p>
                    </div>
                    <button type="button" wire:click="setScheduleWorkspaceTab('schedulers')" class="shrink-0 text-xs font-semibold text-brand-forest hover:underline">
                        {{ __('View scheduler') }}
                    </button>
                </li>
            @endforeach
        </ul>
    @else
        <div class="px-6 py-5 sm:px-7">
            <p class="text-sm text-brand-moss">{{ __('No schedulers need attention right now.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="setScheduleWorkspaceTab('schedulers')" class="{{ $btnSecondary }}">
                    {{ __('View all schedulers') }}
                </button>
                @if ($sites->isNotEmpty())
                    <button type="button" wire:click="setScheduleWorkspaceTab('enable')" class="{{ $btnSecondary }}">
                        {{ __('Enable scheduler') }}
                    </button>
                @endif
            </div>
        </div>
    @endif
</section>

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
        </span>
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Related') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Background jobs on this server') }}</h3>
        </div>
    </div>
    <ul class="divide-y divide-brand-ink/10 text-sm">
        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
            <div>
                <p class="font-semibold text-brand-ink">{{ __('Cron jobs') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Server and site crontab entries in the dply-managed block.') }}</p>
            </div>
            <a href="{{ route('servers.cron', $server) }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Open cron jobs') }}</a>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
            <div>
                <p class="font-semibold text-brand-ink">{{ __('Workers') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Supervisor programs including schedule:work daemons.') }}</p>
            </div>
            <a href="{{ route('servers.daemons', $server) }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Open workers') }}</a>
        </li>
        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
            <div>
                <p class="font-semibold text-brand-ink">{{ __('Activity') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Run-now output and background job history.') }}</p>
            </div>
            <a href="{{ route('servers.activity', $server) }}?category=background" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('View activity') }}</a>
        </li>
    </ul>
</section>
