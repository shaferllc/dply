{{-- Slim trigger card — primary "Add cron job" + "Sync crontab" actions, status meta-row.
     The big add/edit form is now in a modal triggered by the button below. --}}
<div class="{{ $card }} overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sage/15 text-brand-forest ring-brand-sage/25">
                <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Jobs') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cron jobs') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('Stored in Dply, written to the server\'s crontab as a single Dply-managed block on each sync.') }}
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ trans_choice('{0} no jobs tracked|{1} :count job tracked|[2,*] :count jobs tracked', $cronJobCount, ['count' => $cronJobCount]) }}
                        @if ($enabledCronJobCount !== $cronJobCount && $cronJobCount > 0)
                            ({{ __(':count enabled', ['count' => $enabledCronJobCount]) }})
                        @endif
                    </span>
                    @if ($unsyncedCronCount > 0)
                        <span class="text-brand-mist/60">·</span>
                        <span class="inline-flex items-center gap-1 text-amber-700">
                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                            {{ trans_choice('{1} :count unsynced change|[2,*] :count unsynced changes', $unsyncedCronCount, ['count' => $unsyncedCronCount]) }}
                        </span>
                    @elseif ($latestCronSync)
                        <span class="text-brand-mist/60">·</span>
                        <span class="inline-flex items-center gap-1">
                            <x-heroicon-o-check-circle class="h-3 w-3 text-emerald-600" />
                            {{ __('synced :time', ['time' => \Illuminate\Support\Carbon::parse($latestCronSync)->diffForHumans()]) }}
                        </span>
                    @else
                        <span class="text-brand-mist/60">·</span>
                        <span>{{ __('not yet synced') }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <button
                type="button"
                x-on:click="$wire.cancelEdit(); $dispatch('open-modal', 'add-cron-job-modal')"
                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
            >
                <x-heroicon-o-plus class="h-4 w-4" />
                {{ __('Add cron job') }}
            </button>
            <span class="hidden h-5 w-px bg-brand-ink/10 sm:block" aria-hidden="true"></span>
            <button
                type="button"
                wire:click="syncCronJobs"
                wire:loading.attr="disabled"
                wire:target="syncCronJobs"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <x-heroicon-o-arrow-path wire:loading.remove wire:target="syncCronJobs" class="h-4 w-4" />
                <span wire:loading wire:target="syncCronJobs" class="inline-flex h-4 w-4 items-center justify-center">
                    <x-spinner variant="forest" size="sm" />
                </span>
                <span wire:loading.remove wire:target="syncCronJobs">{{ __('Sync crontab') }}</span>
                <span wire:loading wire:target="syncCronJobs">{{ __('Syncing…') }}</span>
            </button>
        </div>
    </div>
</div>

{{-- Add / Edit cron job modal. Triggered by the "Add cron job" button on the trigger
     card and by the per-row Edit button (which sets editing_job_id first, then opens
     this modal). Closes on successful saveCronJob. --}}
<div class="{{ $card }}">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/30">
                <x-heroicon-o-list-bullet class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Scheduled jobs') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss">
                    {{ __('Sync after changes so the Dply-managed crontab block is updated on the server.') }}
                </p>
            </div>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-0 flex-1">
                <x-input-label for="cron_job_search" value="{{ __('Search jobs') }}" class="sr-only" />
                <input
                    id="cron_job_search"
                    type="search"
                    wire:model.live.debounce.300ms="cron_job_search"
                    class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist"
                    placeholder="{{ __('Filter by command or description…') }}"
                />
            </div>
            @if ($contextSiteModel)
                <fieldset class="flex flex-wrap items-center gap-3 text-sm">
                    <legend class="sr-only">{{ __('Job list scope') }}</legend>
                    <span class="text-brand-moss">{{ __('Show') }}</span>
                    <label class="inline-flex cursor-pointer items-center gap-2">
                        <input type="radio" wire:model.live="cron_list_scope" value="site" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                        <span class="text-brand-ink">{{ __('This site only') }}</span>
                    </label>
                    <label class="inline-flex cursor-pointer items-center gap-2">
                        <input type="radio" wire:model.live="cron_list_scope" value="all" class="rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage" />
                        <span class="text-brand-ink">{{ __('All jobs on server') }}</span>
                    </label>
                </fieldset>
            @endif
        </div>
        @if ($contextSiteModel && $cron_list_scope === 'site')
            <p class="mt-2 text-xs text-brand-moss">{{ __('Showing jobs attached to :name.', ['name' => $contextSiteModel->name]) }}</p>
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
