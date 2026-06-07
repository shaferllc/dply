@php
    $schedulersTotal = count($allCards ?? $cards);
    $schedulersFiltered = count($cards);
@endphp

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $contextSiteModel && $schedulers_list_scope === 'site' ? __('Schedulers for this site') : __('Schedulers on this server') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Monitor tick health, run schedule:run once, pause/resume, or change cadence. Enable monitoring to wrap bare cron entries with heartbeat tracking.') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($schedulersTotal > 0)
                    <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $schedulersFiltered }}</span>
                @endif
                <button
                    type="button"
                    wire:click="setScheduleWorkspaceTab('enable')"
                    class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Enable scheduler') }}
                </button>
            </div>
        </div>
    </div>

    @if ($contextSiteModel)
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-3 sm:px-7">
            <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Show') }}</span>
            <div class="inline-flex items-center gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm" role="group" aria-label="{{ __('Scheduler list scope') }}">
                <button type="button" wire:click="$set('schedulers_list_scope', 'site')" @class([
                    'rounded-lg px-3 py-1 text-xs font-semibold transition',
                    'bg-brand-ink text-brand-cream shadow-sm' => $schedulers_list_scope === 'site',
                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $schedulers_list_scope !== 'site',
                ])>{{ __('This site only') }}</button>
                <button type="button" wire:click="$set('schedulers_list_scope', 'all')" @class([
                    'rounded-lg px-3 py-1 text-xs font-semibold transition',
                    'bg-brand-ink text-brand-cream shadow-sm' => $schedulers_list_scope === 'all',
                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $schedulers_list_scope !== 'all',
                ])>{{ __('All schedulers on server') }}</button>
            </div>
        </div>
    @endif

    @if (empty($cards))
        <div class="px-6 py-12 text-center sm:px-7">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                <x-heroicon-o-calendar-days class="h-6 w-6" aria-hidden="true" />
            </span>
            <p class="mt-4 text-sm font-semibold text-brand-ink">
                @if ($contextSiteModel && $schedulers_list_scope === 'site')
                    {{ __('No scheduler for this site yet') }}
                @else
                    {{ __('No schedulers yet') }}
                @endif
            </p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                {{ __('Enable monitoring to wrap schedule:run in a heartbeat script and track tick health.') }}
            </p>
            @if ($sites->isNotEmpty())
                <button type="button" wire:click="setScheduleWorkspaceTab('enable')" class="mt-5 inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest">
                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Enable scheduler') }}
                </button>
            @endif
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($cards as $cardData)
                @php
                    $site = $cardData['site'];
                    $state = $cardData['state'];
                    $chip = $chipForHealth($cardData['health']);
                    $isActive = $state === 'tracked';
                    $heartbeatId = ($cardData['heartbeat'] ?? null)?->id;
                    $isEditing = $heartbeatId !== null && array_key_exists($heartbeatId, $editing_cadence);
                    $isPaused = $state === 'paused';
                    $runInFlight = $heartbeatId !== null && in_array($heartbeatId, $run_now_in_flight, true);
                @endphp
                <li id="scheduler-{{ $site->id }}-{{ $cardData['kind'] ?? 'none' }}" class="relative flex flex-col scroll-mt-24 sm:flex-row" wire:key="card-{{ $site->id }}-{{ $cardData['kind'] ?? 'none' }}">
                    <span
                        @class([
                            'absolute bottom-0 left-0 top-0 w-1',
                            'bg-brand-forest' => $isActive,
                            'bg-amber-500' => in_array($state, ['detected_unmonitored', 'no_scheduler'], true) || in_array($cardData['health'] ?? null, [\App\Services\Servers\SchedulerHealthEvaluator::STATE_AMBER, \App\Services\Servers\SchedulerHealthEvaluator::STATE_RED], true),
                            'bg-brand-mist' => $isPaused || $state === 'no_scheduler',
                        ])
                        aria-hidden="true"
                    ></span>
                    <div class="min-w-0 flex-1 py-4 pl-5 pr-4 sm:py-5 sm:pl-6 sm:pr-6">
                        <div class="flex flex-wrap items-center gap-2">
                            @if (! $siteDedicatedContext || $schedulers_list_scope === 'all')
                                <p class="text-sm font-semibold text-brand-ink">{{ $site->name }}</p>
                            @endif
                            @if ($cardData['kind'])
                                <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $cardData['kind'] }}</span>
                            @endif
                            @if ($cardData['health'] !== null)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $chip['classes'] }}">{{ $chip['label'] }}</span>
                            @endif
                        </div>

                        @if ($state === 'no_scheduler')
                            <p class="mt-1 text-xs text-brand-mist italic">{{ __('No scheduler enabled.') }}</p>
                        @elseif ($state === 'detected_unmonitored')
                            <p class="mt-1 text-xs text-amber-900">{{ __('Detected in crontab but not monitored — enable monitoring to wrap it.') }}</p>
                        @else
                            <p class="mt-1 text-xs text-brand-moss">
                                <span class="font-mono">{{ $cardData['cron_expression'] }}</span>
                                @if ($cardData['last_tick_at'])
                                    · {{ __('last tick :ago', ['ago' => $cardData['last_tick_at']->diffForHumans()]) }}
                                @endif
                                @if ($cardData['next_run_at'] && ! $isPaused)
                                    · {{ __('next :time', ['time' => $cardData['next_run_at']->diffForHumans()]) }}
                                @endif
                            </p>
                        @endif

                        @if ($isEditing && $heartbeatId !== null)
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <input
                                    type="text"
                                    wire:model="editing_cadence.{{ $heartbeatId }}"
                                    wire:keydown.enter="saveCadence('{{ $heartbeatId }}')"
                                    class="block rounded-lg border border-brand-ink/20 bg-white px-2 py-1 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30"
                                    size="11"
                                    placeholder="* * * * *"
                                />
                                <x-secondary-button size="sm" type="button" wire:click="saveCadence('{{ $heartbeatId }}')">{{ __('Save') }}</x-secondary-button>
                                <button type="button" wire:click="cancelEditCadence('{{ $heartbeatId }}')" class="text-xs text-brand-moss hover:underline">{{ __('Cancel') }}</button>
                            </div>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-1 border-t border-brand-ink/5 bg-brand-sand/10 px-2 py-3 sm:border-l sm:border-t-0 sm:px-3">
                        @if ($state === 'tracked' || $state === 'paused')
                            <button
                                type="button"
                                wire:click="runNow('{{ $heartbeatId }}')"
                                wire:loading.attr="disabled"
                                wire:target="runNow('{{ $heartbeatId }}')"
                                @disabled($runInFlight || $isPaused)
                                class="rounded-lg p-2 text-brand-forest hover:bg-emerald-50 disabled:opacity-40"
                                title="{{ $isPaused ? __('Resume first') : __('Run now') }}"
                            >
                                <x-heroicon-o-play class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                wire:click="togglePause('{{ $heartbeatId }}')"
                                wire:loading.attr="disabled"
                                wire:target="togglePause('{{ $heartbeatId }}')"
                                class="rounded-lg p-2 text-amber-800 hover:bg-amber-50"
                                title="{{ $isPaused ? __('Resume') : __('Pause') }}"
                            >
                                @if ($isPaused)
                                    <x-heroicon-o-play class="h-5 w-5" />
                                @else
                                    <x-heroicon-o-pause class="h-5 w-5" />
                                @endif
                            </button>
                            <button
                                type="button"
                                wire:click="startEditCadence('{{ $heartbeatId }}')"
                                class="rounded-lg p-2 text-brand-ink hover:bg-white"
                                title="{{ __('Edit cadence') }}"
                            >
                                <x-heroicon-o-pencil-square class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                wire:click="openDisableMonitoringModal('{{ $heartbeatId }}')"
                                class="rounded-lg p-2 text-red-600 hover:bg-red-50"
                                title="{{ __('Stop monitoring') }}"
                            >
                                <x-heroicon-o-eye-slash class="h-5 w-5" />
                            </button>
                        @elseif ($state === 'detected_unmonitored' || $state === 'no_scheduler')
                            <button
                                type="button"
                                wire:click="setScheduleWorkspaceTab('enable')"
                                class="rounded-lg p-2 text-brand-forest hover:bg-emerald-50"
                                title="{{ __('Enable scheduler') }}"
                            >
                                <x-heroicon-o-plus-circle class="h-5 w-5" />
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-moss sm:px-7">
            {{ __('Stop monitoring keeps the cron entry on the server but removes heartbeat tracking. Pause disables the cron line until you resume.') }}
        </p>
    @endif
</section>
