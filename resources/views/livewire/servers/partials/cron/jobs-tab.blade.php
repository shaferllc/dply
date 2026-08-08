{{-- One dense head for the whole tab. This used to be two stacked headers — a
     "JOBS / Cron jobs" trigger card carrying the buttons and a status meta-row,
     then a second "Scheduled jobs" header directly above the same list. They
     described the same thing, so the count is the pill, the sync state is the
     note, and the two actions ride the actions slot. --}}
@php
    $cronSyncNote = match (true) {
        $unsyncedCronCount > 0 => trans_choice('{1} :count unsynced change.|[2,*] :count unsynced changes.', $unsyncedCronCount, ['count' => $unsyncedCronCount]),
        (bool) $latestCronSync => __('Synced :time.', ['time' => \Illuminate\Support\Carbon::parse($latestCronSync)->diffForHumans()]),
        default => __('Not yet synced.'),
    };

    $cronCountLabel = trans_choice('{0} no jobs|{1} :count job|[2,*] :count jobs', $cronJobCount, ['count' => $cronJobCount]);
    if ($cronJobCount > 0 && $enabledCronJobCount !== $cronJobCount) {
        $cronCountLabel .= ' · '.__(':count on', ['count' => $enabledCronJobCount]);
    }
@endphp

<div class="{{ $card }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-calendar-days"
        :title="__('Cron jobs')"
        :count="$cronCountLabel"
        :note="$cronSyncNote.' '.__('Stored in Dply, written to the server\'s crontab as a single Dply-managed block on each sync.')"
        :tone="$unsyncedCronCount > 0 ? 'amber' : null"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button
                type="button"
                x-on:click="$wire.cancelEdit(); $dispatch('open-modal', 'add-cron-job-modal')"
                class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md bg-brand-ink px-2 text-[11px] font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
            >
                <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Add cron job') }}
            </button>
            <button
                type="button"
                wire:click="syncCronJobs"
                wire:loading.attr="disabled"
                wire:target="syncCronJobs"
                class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <x-heroicon-m-arrow-path wire:loading.remove wire:target="syncCronJobs" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span wire:loading wire:target="syncCronJobs" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                    <x-spinner variant="forest" size="sm" />
                </span>
                <span wire:loading.remove wire:target="syncCronJobs">{{ __('Sync crontab') }}</span>
                <span wire:loading wire:target="syncCronJobs">{{ __('Syncing…') }}</span>
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    <div class="flex flex-col gap-2 border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <div class="min-w-0 flex-1">
                <x-input-label for="cron_job_search" value="{{ __('Search jobs') }}" class="sr-only" />
                <input
                    id="cron_job_search"
                    type="search"
                    wire:model.live.debounce.300ms="cron_job_search"
                    class="block h-8 w-full rounded-lg border border-brand-ink/15 bg-white px-3 text-xs text-brand-ink shadow-sm placeholder:text-brand-mist"
                    placeholder="{{ __('Filter by command or description…') }}"
                />
            </div>
            @if ($contextSiteModel)
                <fieldset class="flex flex-wrap items-center gap-2.5 text-xs">
                    <legend class="sr-only">{{ __('Job list scope') }}</legend>
                    <span class="text-brand-moss">{{ __('Show') }}</span>
                    <label class="inline-flex cursor-pointer items-center gap-1.5">
                        <input type="radio" wire:model.live="cron_list_scope" value="site" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                        <span class="text-brand-ink">{{ __('This site only') }}</span>
                    </label>
                    <label class="inline-flex cursor-pointer items-center gap-1.5">
                        <input type="radio" wire:model.live="cron_list_scope" value="all" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                        <span class="text-brand-ink">{{ __('All jobs on server') }}</span>
                    </label>
                </fieldset>
            @endif
        </div>
        @if ($contextSiteModel && $cron_list_scope === 'site')
            <p class="text-[11px] text-brand-moss">{{ __('Showing jobs attached to :name.', ['name' => $contextSiteModel->name]) }}</p>
        @endif
    </div>
    @if (! empty($invalidExpressionJobs))
        <div class="mx-6 mt-4 rounded-xl border border-rose-200 bg-rose-50/70 px-4 py-3 text-sm text-rose-900 sm:mx-8">
            <div class="flex items-start gap-2">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-rose-700" />
                <div class="min-w-0">
                    <p class="font-semibold">
                        {{ trans_choice(
                            '{1} :count job has an invalid cron expression|[2,*] :count jobs have invalid cron expressions',
                            count($invalidExpressionJobs),
                            ['count' => count($invalidExpressionJobs)],
                        ) }}
                    </p>
                    <p class="mt-0.5 text-xs text-rose-900/80">
                        {{ __('crontab will reject the whole Dply-managed block until these are fixed. Click "Edit" on any row to correct the expression.') }}
                    </p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($invalidExpressionJobs as $bad)
                            <li class="flex flex-wrap items-center gap-2">
                                <span class="rounded-md bg-white px-1.5 py-0.5 font-mono text-[11px] font-semibold text-rose-800 ring-1 ring-rose-200">{{ $bad['cron_expression'] === '' ? __('(empty)') : $bad['cron_expression'] }}</span>
                                <span class="truncate text-xs text-rose-900/90">
                                    {{ $bad['description'] !== '' ? $bad['description'] : \Illuminate\Support\Str::limit($bad['command'], 60) }}
                                </span>
                                <button
                                    type="button"
                                    wire:click="startEdit('{{ $bad['id'] }}')"
                                    x-on:click="$dispatch('open-modal', 'add-cron-job-modal')"
                                    class="ml-auto inline-flex items-center gap-1 rounded-md border border-rose-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-rose-800 hover:bg-rose-100"
                                >
                                    <x-heroicon-o-pencil-square class="h-3 w-3" />
                                    {{ __('Edit') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    @if ($filteredCronJobs->isEmpty())
        <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
            {{ $server->cronJobs->isEmpty()
                ? __('No custom jobs yet. Add one in the form on this tab, then sync the crontab to install the Dply-managed block.')
                : __('No jobs match your search.') }}
        </p>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @php
                $invalidIds = array_flip(array_column($invalidExpressionJobs ?? [], 'id'));
            @endphp
            @foreach ($filteredCronJobs as $cj)
                @php
                    $siteLabel = $cj->site?->name;
                    $primaryDomain = $cj->site?->domains?->sortByDesc('is_primary')->first();
                    if ($siteLabel && $primaryDomain?->hostname) {
                        $siteLabel = $primaryDomain->hostname;
                    }
                    $title = filled($cj->description) ? $cj->description : \Illuminate\Support\Str::limit($cj->command, 60);
                    $rowSpinner = 'inline-block size-4 animate-spin rounded-full border-2 border-brand-ink/25 border-t-brand-ink';
                    $iconBtn = 'inline-flex h-7 w-7 items-center justify-center rounded-md text-brand-ink/70 transition-colors hover:bg-brand-sand/60 hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-40';
                    $hasInvalidExpression = isset($invalidIds[$cj->id]);
                @endphp
                <li id="cron-{{ $cj->id }}" class="group relative flex scroll-mt-24 items-start gap-3 py-3 pl-5 pr-3 transition-colors hover:bg-brand-sand/15 sm:gap-4 sm:pl-6 sm:pr-4">
                    <span
                        @class([
                            'absolute bottom-0 left-0 top-0 w-1',
                            'bg-brand-forest' => $cj->enabled,
                            'bg-brand-mist' => ! $cj->enabled,
                        ])
                        aria-hidden="true"
                    ></span>

                    {{-- Body: title + chips on first line, command on second line --}}
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <h4 class="truncate text-sm font-semibold text-brand-ink" title="{{ $cj->description ?: '' }}">
                                {{ $title }}
                            </h4>
                            {{-- schedule chip — flips to a rose pill when crontab would reject the expression --}}
                            @if ($hasInvalidExpression)
                                <x-tooltip :label="__('crontab will reject this expression — click Edit to fix it')">
                                    <span class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-rose-800 ring-1 ring-rose-200">
                                        <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                        {{ $cj->cron_expression === '' ? __('(empty)') : $cj->cron_expression }}
                                    </span>
                                </x-tooltip>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/50 px-1.5 py-0.5 font-mono text-[11px] text-brand-ink/80 ring-1 ring-brand-ink/10">
                                    <x-heroicon-m-clock class="h-3 w-3 text-brand-moss" />
                                    {{ $cj->cron_expression }}
                                </span>
                            @endif
                            @if ($cronDesc = $cj->cronDescription())
                                <span class="text-[11px] text-brand-ink/50">{{ $cronDesc }}</span>
                            @endif
                            {{-- user chip --}}
                            <span class="inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-[11px] text-brand-ink/80 ring-1 ring-brand-ink/10">
                                <x-heroicon-m-user class="h-3 w-3 text-brand-moss" />
                                {{ $cj->user }}
                            </span>
                            @if (! $cj->enabled)
                                <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-amber-200">
                                    <x-heroicon-m-pause class="h-3 w-3" />
                                    {{ __('Paused') }}
                                </span>
                            @endif
                            @if (! $cj->is_synced && ! $cj->system_managed)
                                <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium text-sky-800 ring-1 ring-sky-200" title="{{ __('Pending changes — sync the crontab.') }}">
                                    <x-heroicon-m-arrow-path class="h-3 w-3" />
                                    {{ __('Unsynced') }}
                                </span>
                            @endif
                            @if ($cj->system_managed)
                                <span class="inline-flex items-center gap-1 rounded-md bg-brand-sage/15 px-1.5 py-0.5 text-[11px] font-medium text-brand-forest ring-1 ring-brand-sage/30" title="{{ __('Auto-installed by Dply (read-only).') }}">
                                    <x-heroicon-m-shield-check class="h-3 w-3" />
                                    {{ __('Managed') }}
                                </span>
                            @endif
                        </div>

                        <p class="mt-1 truncate font-mono text-[11px] leading-relaxed text-brand-moss" title="{{ $cj->command }}">
                            {{ $cj->command }}
                        </p>

                        @if ($siteLabel || ($cj->depends_on_job_id && $cj->dependsOn) || ($cj->last_sync_error && ! $cj->is_synced))
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-mist">
                                @if ($siteLabel)
                                    <span class="inline-flex items-center gap-1">
                                        <x-heroicon-m-globe-alt class="h-3 w-3" />
                                        {{ $siteLabel }}
                                    </span>
                                @endif
                                @if ($cj->depends_on_job_id && $cj->dependsOn)
                                    <span class="inline-flex items-center gap-1">
                                        <x-heroicon-m-link class="h-3 w-3" />
                                        {{ __('after :d', ['d' => $cj->dependsOn->description ?: \Illuminate\Support\Str::limit($cj->dependsOn->command, 28)]) }}
                                    </span>
                                @endif
                                @if ($cj->last_sync_error && ! $cj->is_synced)
                                    <span class="inline-flex items-center gap-1 text-rose-600">
                                        <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                        {{ __('Last sync issue — try syncing again.') }}
                                    </span>
                                @endif
                            </p>
                        @endif
                    </div>

                    {{-- Actions: horizontal, top-aligned, smaller targets, fades in on hover --}}
                    <div class="flex shrink-0 items-center gap-0.5 self-start pt-0.5 opacity-90 transition-opacity sm:opacity-60 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
                        @if (! $cj->system_managed)
                            <x-tooltip :label="__('Edit')">
                                <button
                                    type="button"
                                    wire:click="startEdit('{{ $cj->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="startEdit('{{ $cj->id }}')"
                                    x-on:click="$dispatch('open-modal', 'add-cron-job-modal')"
                                    class="{{ $iconBtn }}"
                                    aria-label="{{ __('Edit') }}"
                                >
                                    <span wire:loading.remove wire:target="startEdit('{{ $cj->id }}')">
                                        <x-heroicon-o-pencil-square class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="startEdit('{{ $cj->id }}')" class="{{ $rowSpinner }}" aria-hidden="true"></span>
                                </button>
                            </x-tooltip>

                            <x-tooltip :label="$cj->enabled ? __('Pause') : __('Resume')">
                                <button
                                    type="button"
                                    wire:click="toggleCronJob('{{ $cj->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleCronJob('{{ $cj->id }}')"
                                    class="{{ $iconBtn }}"
                                    aria-label="{{ $cj->enabled ? __('Pause') : __('Resume') }}"
                                >
                                    <span wire:loading.remove wire:target="toggleCronJob('{{ $cj->id }}')">
                                        @if ($cj->enabled)
                                            <x-heroicon-o-pause class="h-4 w-4" />
                                        @else
                                            <x-heroicon-o-play class="h-4 w-4" />
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="toggleCronJob('{{ $cj->id }}')" class="{{ $rowSpinner }}" aria-hidden="true"></span>
                                </button>
                            </x-tooltip>
                        @endif

                        <x-tooltip :label="$cj->enabled ? __('Run now') : __('Resume the job to run it')">
                            <button
                                type="button"
                                wire:click="runCronJobNow('{{ $cj->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="runCronJobNow('{{ $cj->id }}')"
                                class="{{ $iconBtn }}"
                                aria-label="{{ __('Run now') }}"
                                @disabled(! $cj->enabled)
                            >
                                <span wire:loading.remove wire:target="runCronJobNow('{{ $cj->id }}')">
                                    <x-heroicon-o-bolt class="h-4 w-4" />
                                </span>
                                <span wire:loading wire:target="runCronJobNow('{{ $cj->id }}')" class="{{ $rowSpinner }}" aria-hidden="true"></span>
                            </button>
                        </x-tooltip>

                        <x-tooltip :label="__('Last run output')">
                            <button
                                type="button"
                                wire:click="openLogsModal('{{ $cj->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="openLogsModal('{{ $cj->id }}')"
                                class="{{ $iconBtn }}"
                                aria-label="{{ __('Last run output') }}"
                            >
                                <span wire:loading.remove wire:target="openLogsModal('{{ $cj->id }}')">
                                    <x-heroicon-o-document-text class="h-4 w-4" />
                                </span>
                                <span wire:loading wire:target="openLogsModal('{{ $cj->id }}')" class="{{ $rowSpinner }}" aria-hidden="true"></span>
                            </button>
                        </x-tooltip>

                        @if (! $cj->system_managed)
                            <x-tooltip :label="__('Delete')">
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('deleteCronJob', ['{{ $cj->id }}'], @js(__('Delete cron job')), @js(__('Delete this cron job? Sync the crontab afterward to remove it from the server.')), @js(__('Delete cron job')), true)"
                                    wire:loading.attr="disabled"
                                    wire:target="openConfirmActionModal('deleteCronJob', ['{{ $cj->id }}'], @js(__('Delete cron job')), @js(__('Delete this cron job? Sync the crontab afterward to remove it from the server.')), @js(__('Delete cron job')), true)"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-rose-600 transition-colors hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40"
                                    aria-label="{{ __('Delete') }}"
                                >
                                    <span wire:loading.remove wire:target="openConfirmActionModal('deleteCronJob', ['{{ $cj->id }}'], @js(__('Delete cron job')), @js(__('Delete this cron job? Sync the crontab afterward to remove it from the server.')), @js(__('Delete cron job')), true)">
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                    </span>
                                    <span wire:loading wire:target="openConfirmActionModal('deleteCronJob', ['{{ $cj->id }}'], @js(__('Delete cron job')), @js(__('Delete this cron job? Sync the crontab afterward to remove it from the server.')), @js(__('Delete cron job')), true)" class="inline-block size-4 animate-spin rounded-full border-2 border-rose-200 border-t-rose-600" aria-hidden="true"></span>
                                </button>
                            </x-tooltip>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

{{-- Invisible 1s poller so the page-top console banner keeps catching
     up to streamed run output if Echo/Reverb is offline. --}}
@if ($cron_run_id)
    <div wire:poll.1s="syncCronRunFromCache" class="sr-only" aria-hidden="true"></div>
@endif
