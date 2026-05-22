@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $presets = [
        'every_minute' => [__('Every minute'), '* * * * *'],
        'hourly' => [__('Hourly'), '0 * * * *'],
        'nightly' => [__('Nightly (2:00)'), '0 2 * * *'],
        'weekly' => [__('Weekly (Sun 2:00)'), '0 2 * * 0'],
        'monthly' => [__('Monthly (1st 2:00)'), '0 2 1 * *'],
        'custom' => [__('Custom'), ''],
    ];
@endphp

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

        {{-- Workspace-level console banner. Surfaces panel events from add/edit/delete plus
             the queued crontab sync output. --}}
        <div wire:loading.block wire:target="syncCronJobs" class="w-full">
            <x-workspace-console-banner
                status="running"
                :message="__('Syncing crontab to :host …', ['host' => $server->getSshConnectionString()])"
                :subtitle="__('Writing the Dply-managed crontab block over SSH.')"
                :output="[]"
                :busy="true"
                :default-expanded="false"
                :dismiss-action="null"
            />
        </div>

        @if ($panel_event_message !== '')
            <div wire:loading.remove wire:target="syncCronJobs" class="w-full">
                @php
                    $cronPanelBusy = $panel_event_status === 'running' || $cron_run_id !== null;
                    // Subtitle copy differs by what the banner represents:
                    // - completed/failed during a run-now → result of the run.
                    // - running with cron_run_id → live streaming output.
                    // - completed/failed from add/edit/sync → panel-change prompt.
                    $cronPanelSubtitle = match (true) {
                        $cron_run_id !== null => __('Output streams here as the worker writes it.'),
                        $panel_event_status === 'completed' && count($panel_event_lines) > 0 && ! empty($cron_run_output) => null,
                        $panel_event_status === 'failed' => null,
                        default => __('The panel was updated. Sync the crontab to install the changes on the server.'),
                    };
                @endphp
                <x-workspace-console-banner
                    :status="$panel_event_status"
                    :message="$panel_event_message"
                    :subtitle="$cronPanelSubtitle"
                    :output="$panel_event_lines"
                    :busy="$cronPanelBusy"
                    dismiss-action="dismissPanelBanner"
                    :default-expanded="true"
                />
            </div>
        @endif

        <div class="space-y-8">
        @php
            $cronJobCount = $server->cronJobs->count();
            $enabledCronJobCount = $server->cronJobs->where('enabled', true)->count();
            $disabledCronJobCount = $cronJobCount - $enabledCronJobCount;
            $unsyncedCronCount = $server->cronJobs->where('is_synced', false)->count();
            $latestCronSync = $server->cronJobs->where('synced_at')->max('synced_at');
        @endphp

        {{-- At-a-glance counts. Pure addition above the existing cron card; no behaviour change. --}}
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

        <x-server-workspace-tab-panel
            id="cron-panel-jobs"
            labelled-by="cron-tab-jobs"
            :hidden="$cron_workspace_tab !== 'jobs'"
            panel-class="space-y-8"
        >
        {{-- Slim trigger card — primary "Add cron job" + "Sync crontab" actions, status meta-row.
             The big add/edit form is now in a modal triggered by the button below. --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-calendar-days class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Cron jobs') }}</h2>
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
                        <x-heroicon-o-plus class="h-3.5 w-3.5" />
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
                        <x-heroicon-o-arrow-path wire:loading.remove wire:target="syncCronJobs" class="h-3.5 w-3.5" />
                        <span wire:loading wire:target="syncCronJobs" class="inline-flex h-3.5 w-3.5 items-center justify-center">
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
        <x-modal name="add-cron-job-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Cron job') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">
                    @if ($editing_job_id)
                        {{ __('Edit cron job') }}
                    @else
                        {{ __('New cron job') }}
                    @endif
                </h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('What should run, which user runs it, and how often. Advanced toggles tuck below when you need them.') }}
                </p>
            </div>
            <div class="p-6 sm:p-8">
                <form id="cron-job-form" wire:submit="saveCronJob" class="space-y-6">
                    <div>
                        <x-input-label for="new_cron_command" value="{{ __('Command') }}" />
                        <x-text-input
                            id="new_cron_command"
                            wire:model="new_cron_command"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="{{ __('e.g. php /home/deploy/app/artisan schedule:run') }}"
                        />
                        <p class="mt-1.5 text-xs text-brand-moss">
                            {{ __('Use an explicit PHP binary if needed (for example') }}
                            <span class="font-mono text-brand-ink/80">php8.2</span>).
                        </p>
                        @if ($schedulerSiteIsLaravel)
                            <button
                                type="button"
                                wire:click="fillLaravelSchedulerCommand"
                                class="mt-3 inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                            >
                                {{ __('Use Laravel scheduler for this site (schedule:run)') }}
                            </button>
                        @endif
                        <x-input-error :messages="$errors->get('new_cron_command')" class="mt-1" />
                    </div>

                    <div>
                        <div class="flex flex-wrap items-end justify-between gap-2">
                            <x-input-label for="new_cron_user" value="{{ __('Run as user') }}" class="min-w-0 flex-1" />
                            <button
                                type="button"
                                wire:click="refreshRunAsUserChoices"
                                wire:loading.attr="disabled"
                                class="shrink-0 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="refreshRunAsUserChoices">{{ __('Refresh list') }}</span>
                                <span wire:loading wire:target="refreshRunAsUserChoices" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Refreshing…') }}
                                </span>
                            </button>
                        </div>
                        <input
                            id="new_cron_user"
                            type="text"
                            wire:model="new_cron_user"
                            list="cron-run-as-user-suggestions"
                            spellcheck="false"
                            autocorrect="off"
                            autocomplete="off"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            placeholder="{{ $server->ssh_user }}"
                        />
                        <datalist id="cron-run-as-user-suggestions">
                            @foreach ($runAsUserDatalistChoices as $u)
                                <option value="{{ $u }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-1.5 text-xs text-brand-moss">
                            {{ __('Pick from the list or type any user. Names come from /etc/passwd on the server (cached a few minutes).') }}
                        </p>
                        <x-input-error :messages="$errors->get('new_cron_user')" class="mt-1" />
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-brand-ink">
                            {{ __('Frequency') }}
                            <span class="font-normal text-brand-moss">({{ trim($new_cron_expression) }})</span>
                        </legend>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($presets as $key => [$label, $expr])
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 has-[:checked]:border-brand-sage has-[:checked]:bg-brand-sage/10">
                                    <input
                                        type="radio"
                                        wire:model.live="frequency_preset"
                                        value="{{ $key }}"
                                        class="mt-0.5 rounded-full border-brand-mist text-brand-ink focus:ring-brand-sage"
                                    />
                                    <span class="text-sm text-brand-ink">
                                        {{ $label }}
                                        @if ($key !== 'custom' && $expr !== '')
                                            <span class="block font-mono text-xs text-brand-moss">({{ $expr }})</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @if ($frequency_preset === 'custom')
                            <div class="mt-4">
                                <x-input-label for="new_cron_expression" value="{{ __('Cron expression') }}" />
                                <x-text-input
                                    id="new_cron_expression"
                                    wire:model.blur="new_cron_expression"
                                    class="mt-1 block w-full font-mono text-sm"
                                    placeholder="*/5 * * * *"
                                />
                                <x-input-error :messages="$errors->get('new_cron_expression')" class="mt-1" />
                            </div>
                        @endif
                    </fieldset>

                    <div>
                        <x-input-label for="new_description" value="{{ __('Description (optional)') }}" />
                        <textarea
                            id="new_description"
                            wire:model="new_description"
                            rows="2"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            placeholder="{{ __('e.g. Laravel scheduler') }}"
                        ></textarea>
                        <x-input-error :messages="$errors->get('new_description')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="new_site_id" value="{{ __('Attach to site (optional)') }}" />
                        <select
                            id="new_site_id"
                            wire:model="new_site_id"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        >
                            <option value="">{{ __('None') }}</option>
                            @foreach ($server->sites as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('new_site_id')" class="mt-1" />
                    </div>

                    <details class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/15 open:shadow-sm">
                        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-brand-ink">{{ __('Advanced options') }}</summary>
                        <div class="space-y-4 border-t border-brand-ink/10 px-4 py-4">
                            <div>
                                <x-input-label for="command_preset" value="{{ __('Starter command') }}" />
                                <select
                                    id="command_preset"
                                    wire:model.live="command_preset"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                >
                                    <option value="custom">{{ __('Custom — type your own command') }}</option>
                                    @foreach ($commandInstallPresets as $presetKey => $preset)
                                        <option value="{{ $presetKey }}">{{ $preset['label'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1.5 text-xs text-brand-moss">
                                    {{ __('Fill the form with a common starter, then adjust paths, domains, and users before saving.') }}
                                </p>
                            </div>
                            <div>
                                <x-input-label for="new_schedule_timezone" value="{{ __('Schedule timezone (export TZ before command)') }}" />
                                <x-text-input id="new_schedule_timezone" wire:model="new_schedule_timezone" class="mt-1 block w-full font-mono text-sm" placeholder="UTC" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Crontab fires on the server clock; this prepends export TZ for the shell (Run now and synced lines).') }}</p>
                            </div>
                            <div>
                                <x-input-label for="new_overlap_policy" value="{{ __('Overlap') }}" />
                                <select id="new_overlap_policy" wire:model="new_overlap_policy" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm">
                                    <option value="allow">{{ __('Allow overlapping runs') }}</option>
                                    <option value="skip_if_running">{{ __('Skip if still running (flock)') }}</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <label class="inline-flex items-center gap-2 text-sm text-brand-ink">
                                    <input type="checkbox" wire:model="new_alert_on_failure" class="rounded border-brand-mist text-brand-forest" />
                                    {{ __('Alert org owners/admins on non-zero exit') }}
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-brand-ink">
                                    <input type="checkbox" wire:model="new_alert_on_pattern_match" class="rounded border-brand-mist text-brand-forest" />
                                    {{ __('Alert when output matches regex') }}
                                </label>
                            </div>
                            <div>
                                <x-input-label for="new_alert_pattern" value="{{ __('Alert PCRE pattern (e.g. /error/i)') }}" />
                                <x-text-input id="new_alert_pattern" wire:model="new_alert_pattern" class="mt-1 block w-full font-mono text-sm" />
                            </div>
                            <div>
                                <x-input-label for="new_env_prefix" value="{{ __('Env / exports before command (optional)') }}" />
                                <textarea id="new_env_prefix" wire:model="new_env_prefix" rows="3" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink" placeholder="export MY_TOKEN=…"></textarea>
                            </div>
                            <div>
                                <x-input-label for="new_depends_on_job_id" value="{{ __('Run after job (manual “Run now” only)') }}" />
                                <select id="new_depends_on_job_id" wire:model="new_depends_on_job_id" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($dependsJobChoices as $dj)
                                        <option value="{{ $dj->id }}">{{ $dj->description ?: Str::limit($dj->command, 48) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="new_maintenance_tag" value="{{ __('Maintenance tag (optional, for your notes)') }}" />
                                <x-text-input id="new_maintenance_tag" wire:model="new_maintenance_tag" class="mt-1 block w-full text-sm" />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="validateCronExpressionField" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    {{ __('Validate expression') }}
                                </button>
                                <button type="button" wire:click="dryRunFormCommand" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    {{ __('Dry run (preview shell)') }}
                                </button>
                            </div>
                        </div>
                    </details>
                </form>

                @if ($canUpdateOrg)
                    <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Save as organization template') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Stores the expression, command, user, and description above as a named template your team can apply from the Templates tab.') }}</p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                            <div class="min-w-0 flex-1">
                                <x-input-label for="template_save_name" value="{{ __('Template name') }}" class="sr-only" />
                                <x-text-input
                                    id="template_save_name"
                                    wire:model="template_save_name"
                                    class="block w-full text-sm"
                                    placeholder="{{ __('e.g. Laravel scheduler') }}"
                                    maxlength="120"
                                />
                                <x-input-error :messages="$errors->get('template_save_name')" class="mt-1" />
                            </div>
                            <button
                                type="button"
                                wire:click="saveOrgCronTemplate"
                                wire:loading.attr="disabled"
                                wire:target="saveOrgCronTemplate"
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="saveOrgCronTemplate" class="inline-flex items-center gap-1.5">
                                    <x-heroicon-o-bookmark-square class="h-4 w-4" />
                                    {{ __('Save as template') }}
                                </span>
                                <span wire:loading wire:target="saveOrgCronTemplate" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="forest" size="sm" />
                                    {{ __('Saving…') }}
                                </span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4">
                @if ($editing_job_id)
                    <x-secondary-button type="button" wire:click="cancelEdit" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                @else
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                @endif
                <x-primary-button type="submit" form="cron-job-form" wire:loading.attr="disabled" wire:target="saveCronJob">
                    <span wire:loading.remove wire:target="saveCronJob">
                        @if ($editing_job_id)
                            {{ __('Save changes') }}
                        @else
                            {{ __('Add cron job') }}
                        @endif
                    </span>
                    <span wire:loading wire:target="saveCronJob">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </x-modal>

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
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="cron-panel-history"
            labelled-by="cron-tab-history"
            :hidden="$cron_workspace_tab !== 'history'"
            panel-class="space-y-8"
        >
        <div class="{{ $card }}">
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/60 text-brand-ink ring-1 ring-brand-ink/10">
                        <x-heroicon-o-clock class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Recent run history') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Recent manual and queued runs — retention :days days.', ['days' => config('cron_workspace.run_retention_days', 90)]) }}</p>
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                @if ($recentCronRuns->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">{{ __('No recorded runs yet. Use the per-job “Run now” to create history.') }}</p>
                @else
                    <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ __('When') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Job') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Exit') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('Duration') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                            @foreach ($recentCronRuns as $run)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-[11px]">{{ $run->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                    <td class="max-w-xs truncate px-4 py-2">{{ $run->cronJob?->description ?: \Illuminate\Support\Str::limit($run->cronJob?->command ?? '—', 48) }}</td>
                                    <td class="px-4 py-2">{{ $run->status }}</td>
                                    <td class="px-4 py-2 font-mono">{{ $run->exit_code ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $run->duration_ms !== null ? $run->duration_ms.' ms' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="cron-panel-inspect"
            labelled-by="cron-tab-inspect"
            :hidden="$cron_workspace_tab !== 'inspect'"
            panel-class="space-y-8"
        >
        <div class="{{ $card }}">
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-ink/5 text-brand-ink ring-1 ring-brand-ink/10">
                        <x-heroicon-o-command-line class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Inspect crontab') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss leading-relaxed">
                            {{ __('Read-only: shows the real crontab file for that Linux user. Dply uses the SSH login user for “crontab -l”; other users need “sudo crontab -u … -l” (passwordless sudo).') }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="space-y-4 p-6 sm:p-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <x-input-label for="inspect_crontab_user" value="{{ __('Linux user') }}" />
                        <input
                            id="inspect_crontab_user"
                            type="text"
                            wire:model="inspect_crontab_user"
                            autocomplete="off"
                            list="crontab-user-suggestions"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            placeholder="{{ __('e.g. deploy, root') }}"
                        />
                        <datalist id="crontab-user-suggestions">
                            @foreach ($crontabInspectUserChoices as $u)
                                <option value="{{ $u }}"></option>
                            @endforeach
                        </datalist>
                        <x-input-error :messages="$errors->get('inspect_crontab_user')" class="mt-1" />
                    </div>
                    <button
                        type="button"
                        wire:click="loadInspectCrontab"
                        wire:loading.attr="disabled"
                        class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="loadInspectCrontab">{{ __('Load crontab') }}</span>
                        <span wire:loading wire:target="loadInspectCrontab" class="inline-flex items-center gap-2">
                            <x-spinner variant="forest" />
                            {{ __('Loading…') }}
                        </span>
                    </button>
                </div>
                @if ($inspect_crontab_exit_code !== null)
                    <p class="text-xs text-brand-moss">
                        {{ __('Exit code: :code', ['code' => $inspect_crontab_exit_code]) }}
                    </p>
                @endif
                <div class="max-h-[min(55vh,28rem)] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950">
                    <pre class="whitespace-pre-wrap break-words p-4 font-mono text-xs leading-relaxed text-zinc-100">@if ($inspect_crontab_body !== null){{ $inspect_crontab_body }}@else{{ __('Choose a user and click “Load crontab”.') }}@endif</pre>
                </div>
            </div>
        </div>
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="cron-panel-templates"
            labelled-by="cron-tab-templates"
            :hidden="$cron_workspace_tab !== 'templates'"
            panel-class="space-y-8"
        >
            @include('livewire.servers.partials.cron-templates-tab', [
                'bundledCronJobs' => $bundledCronJobs,
                'cronJobCount' => $cronJobCount,
                'card' => $card,
                'orgCronTemplates' => $orgCronTemplates,
                'canUpdateOrg' => $canUpdateOrg,
            ])
        </x-server-workspace-tab-panel>

        @if ($canUpdateOrg)
            <x-server-workspace-tab-panel
                id="cron-panel-maintenance"
                labelled-by="cron-tab-maintenance"
                :hidden="$cron_workspace_tab !== 'maintenance'"
                panel-class="space-y-8"
            >
                @include('livewire.servers.partials.cron-maintenance-tab', [
                    'card' => $card,
                    'server' => $server,
                ])
            </x-server-workspace-tab-panel>
        @endif
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    @if ($contextSiteModel)
        <x-cli-snippet tone="stub" />
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])

        @if ($viewingLogJob)
            <div
                class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cron-logs-title"
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeLogsModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div
                        class="my-auto w-full max-w-2xl dply-modal-panel"
                        @click.stop
                        wire:key="cron-logs-dialog"
                    >
                        <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                            <div>
                                <h2 id="cron-logs-title" class="text-lg font-semibold text-brand-ink">{{ __('Last run output') }}</h2>
                                <p class="mt-1 font-mono text-xs text-brand-moss break-all">{{ \Illuminate\Support\Str::limit($viewingLogJob->command, 120) }}</p>
                                @if ($viewingLogJob->last_run_at)
                                    <p class="mt-1 text-xs text-brand-moss">{{ $viewingLogJob->last_run_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') }}</p>
                                @else
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('No run recorded yet. Use “Run now” to capture output here.') }}</p>
                                @endif
                            </div>
                            <button
                                type="button"
                                wire:click="closeLogsModal"
                                class="rounded-lg p-2 text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink"
                            >
                                <x-heroicon-o-x-mark class="h-5 w-5" />
                            </button>
                        </div>
                        <div class="max-h-[min(60vh,28rem)] overflow-auto px-6 py-5 sm:px-8">
                            <pre class="whitespace-pre-wrap break-words rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100">{{ $viewingLogJob->last_run_output ?: __('(empty)') }}</pre>
                        </div>
                        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 text-right sm:px-8">
                            <button
                                type="button"
                                wire:click="closeLogsModal"
                                class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Close') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-slot>
</x-server-workspace-layout>
