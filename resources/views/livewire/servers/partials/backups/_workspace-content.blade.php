    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($contextSite)
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sky'] }}">
                            <x-heroicon-o-funnel class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-700">{{ __('Filter') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Filtered to :site', ['site' => $contextSite->name]) }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Database backups are hidden because databases are server-scoped, not site-scoped.') }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('servers.backups', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        <x-heroicon-m-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Clear filter') }}
                    </a>
                </div>
            </div>
        </section>
    @endif

    <x-explainer>
        <p>{{ __('"Run now" creates a pending backup row and queues the export job — progress shows up in the lists below as the job completes. Schedules add a managed cron entry that fires the same job on the cadence you set.') }}</p>
        @if ($backupConfigurations->isEmpty())
            <p class="mt-2">
                {{ __('No backup destinations yet. Add an S3 bucket / Dropbox / Google Drive / SFTP target to send backups somewhere you own — ') }}
                <button type="button" wire:click="openDestinationModal" class="font-semibold text-brand-ink underline hover:no-underline">{{ __('add one now') }}</button>{{ __('.') }}
            </p>
        @endif
        <p class="mt-2 text-xs"><a href="{{ route('servers.activity', $server) }}?category=background" wire:navigate class="font-semibold text-brand-ink underline">{{ __('View background activity →') }}</a></p>
    </x-explainer>

    {{-- At-a-glance health strip — last 7 days for completed/failed counts. --}}
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['violet'] }}">
                    <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent activity') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Backups at a glance') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Counts from the last 7 days plus current state.') }}</p>
                </div>
            </div>
        </div>
        <dl class="grid grid-cols-1 gap-2 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-4">
            <div @class([
                'rounded-2xl border px-4 py-3 shadow-sm',
                'border-rose-200 bg-rose-50/60' => $stats['db_failed_7d'] > 0,
                'border-emerald-200 bg-emerald-50/60' => $stats['db_failed_7d'] === 0 && $stats['db_completed_7d'] > 0,
                'border-brand-ink/10 bg-white' => $stats['db_failed_7d'] === 0 && $stats['db_completed_7d'] === 0,
            ])>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Database backups') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $stats['db_completed_7d'] }}</span>
                    <span class="text-[11px] text-brand-moss">{{ __('completed (7d)') }}</span>
                </dd>
                @if ($stats['db_failed_7d'] > 0)
                    <p class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700">
                        <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ trans_choice(':n failed|:n failed', $stats['db_failed_7d'], ['n' => $stats['db_failed_7d']]) }}
                    </p>
                @else
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('No failures') }}</p>
                @endif
            </div>
            <div @class([
                'rounded-2xl border px-4 py-3 shadow-sm',
                'border-rose-200 bg-rose-50/60' => $stats['files_failed_7d'] > 0,
                'border-emerald-200 bg-emerald-50/60' => $stats['files_failed_7d'] === 0 && $stats['files_completed_7d'] > 0,
                'border-brand-ink/10 bg-white' => $stats['files_failed_7d'] === 0 && $stats['files_completed_7d'] === 0,
            ])>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Site file backups') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $stats['files_completed_7d'] }}</span>
                    <span class="text-[11px] text-brand-moss">{{ __('completed (7d)') }}</span>
                </dd>
                @if ($stats['files_failed_7d'] > 0)
                    <p class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-rose-700">
                        <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ trans_choice(':n failed|:n failed', $stats['files_failed_7d'], ['n' => $stats['files_failed_7d']]) }}
                    </p>
                @else
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('No failures') }}</p>
                @endif
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Total stored') }}</dt>
                <dd class="mt-1 truncate font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $formatBytes($stats['total_bytes']) }}</dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Across all targets') }}</p>
            </div>
            <div @class([
                'rounded-2xl border px-4 py-3 shadow-sm',
                'border-brand-sage/30 bg-brand-sage/8' => $activeScheduleCount > 0,
                'border-brand-ink/10 bg-white' => $activeScheduleCount === 0,
            ])>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Schedules') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $activeScheduleCount }}</span>
                    <span class="text-[11px] text-brand-moss">{{ trans_choice('active|active', $activeScheduleCount) }}</span>
                </dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Recurring jobs') }}</p>
            </div>
        </dl>
    </section>

    <x-server-workspace-tablist :aria-label="__('Backups sections')">
        <x-server-workspace-tab id="backups-tab-overview" :active="$backups_workspace_tab === 'overview'" wire:click="setBackupsWorkspaceTab('overview')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                {{ __('Overview') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab id="backups-tab-schedules" :active="$backups_workspace_tab === 'schedules'" wire:click="setBackupsWorkspaceTab('schedules')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-clock class="h-4 w-4" aria-hidden="true" />
                {{ __('Schedules') }}
            </span>
        </x-server-workspace-tab>
        <x-server-workspace-tab id="backups-tab-history" :active="$backups_workspace_tab === 'history'" wire:click="setBackupsWorkspaceTab('history')">
            <span class="inline-flex items-center gap-1.5">
                <x-heroicon-o-archive-box class="h-4 w-4" aria-hidden="true" />
                {{ __('History') }}
            </span>
        </x-server-workspace-tab>
    </x-server-workspace-tablist>

    <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setBackupsWorkspaceTab">

    @if ($backups_workspace_tab === 'overview')
    <x-server-workspace-tab-panel id="backups-panel-overview" labelled-by="backups-tab-overview" panel-class="space-y-6">
    {{-- Run now -------------------------------------------------------------------- --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                        <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('On demand') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Run database backup') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Queue an export job for one database now.') }}</p>
                    </div>
                </div>
            </div>
            <div class="space-y-3 p-6 sm:p-7">
                @if ($databases->isEmpty())
                    <p class="text-sm text-brand-moss">{{ __('No databases on this server yet.') }}</p>
                @else
                    <select wire:model="run_database_id" class="{{ $input }}">
                        <option value="">{{ __('Pick a database…') }}</option>
                        @foreach ($databases as $db)
                            <option value="{{ $db->id }}">{{ $db->name }} ({{ $db->engine }})</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="runDatabaseBackup" wire:loading.attr="disabled" wire:target="runDatabaseBackup" class="{{ $btnPrimary }}" @disabled(! $opsReady || $run_database_id === '')>
                        <span wire:loading.remove wire:target="runDatabaseBackup" class="inline-flex items-center gap-2">
                            <x-heroicon-o-play class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Run database backup now') }}
                        </span>
                        <span wire:loading wire:target="runDatabaseBackup" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Queueing…') }}
                        </span>
                    </button>
                @endif
            </div>
        </section>

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sand'] }}">
                        <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('On demand') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Run site files backup') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Archive and ship a site’s files to its destination.') }}</p>
                    </div>
                </div>
            </div>
            <div class="space-y-3 p-6 sm:p-7">
                @if ($sites->isEmpty())
                    <p class="text-sm text-brand-moss">{{ __('No sites on this server yet.') }}</p>
                @else
                    <select wire:model="run_site_id" class="{{ $input }}">
                        <option value="">{{ __('Pick a site…') }}</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="runSiteFilesBackup" wire:loading.attr="disabled" wire:target="runSiteFilesBackup" class="{{ $btnPrimary }}" @disabled(! $opsReady || $run_site_id === '')>
                        <span wire:loading.remove wire:target="runSiteFilesBackup" class="inline-flex items-center gap-2">
                            <x-heroicon-o-play class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Run files backup now') }}
                        </span>
                        <span wire:loading wire:target="runSiteFilesBackup" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Queueing…') }}
                        </span>
                    </button>
                @endif
            </div>
        </section>
    </div>

    </x-server-workspace-tab-panel>
    @endif

    @if ($backups_workspace_tab === 'schedules')
    <x-server-workspace-tab-panel id="backups-panel-schedules" labelled-by="backups-tab-schedules" panel-class="space-y-6">
    {{-- Schedules ------------------------------------------------------------------ --}}
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                    <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Schedule') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recurring schedules') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Each schedule materializes a managed cron entry that calls dply:run-backup-schedule {id}.') }}</p>
                </div>
                @if ($schedules->isNotEmpty())
                    <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $schedules->count() }}</span>
                @endif
            </div>
        </div>

        <div class="space-y-4 p-6 sm:p-7">
            @if ($backupConfigurations->isEmpty())
                {{-- No destinations means no schedule can actually push anywhere, so
                     skip the cadence/target/cron form entirely until the operator
                     adds a destination. --}}
                <div class="px-2 py-8 text-center">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-cloud-arrow-up class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('Add a backup destination to start scheduling') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ __('Schedules push backups to a destination you own (S3, B2, R2, Spaces, SFTP, Dropbox, Google Drive, or rclone). Add one to unlock the recurring-schedule form.') }}</p>
                    <button
                        type="button"
                        wire:click="openDestinationModal"
                        class="mt-5 inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add backup destination') }}
                    </button>
                </div>
            @else
                {{-- Cadence presets — fill the cron field with one click. --}}
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-semibold uppercase tracking-wide text-brand-mist">{{ __('Quick presets:') }}</span>
                    <button type="button" wire:click="$set('new_cron_expression', '0 3 * * *')" class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-brand-ink hover:bg-brand-sand/40">{{ __('Nightly 3am') }}</button>
                    <button type="button" wire:click="$set('new_cron_expression', '0 4 * * 0')" class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-brand-ink hover:bg-brand-sand/40">{{ __('Weekly Sun 4am') }}</button>
                    <button type="button" wire:click="$set('new_cron_expression', '0 * * * *')" class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-brand-ink hover:bg-brand-sand/40">{{ __('Hourly') }}</button>
                    <button type="button" wire:click="$set('new_cron_expression', '*/15 * * * *')" class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-brand-ink hover:bg-brand-sand/40">{{ __('Every 15 min') }}</button>
                </div>
                <form wire:submit="addSchedule" class="grid gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:grid-cols-5">
                    <select wire:model.live="new_target_type" class="{{ $input }} sm:col-span-1">
                        <option value="database">{{ __('Database') }}</option>
                        <option value="site_files">{{ __('Site files') }}</option>
                    </select>
                    <select wire:model="new_target_id" class="{{ $input }} sm:col-span-2">
                        <option value="">{{ __('Pick target…') }}</option>
                        @if ($new_target_type === 'database')
                            @foreach ($databases as $db)
                                <option value="{{ $db->id }}">{{ $db->name }}</option>
                            @endforeach
                        @else
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}">{{ $site->name }}</option>
                            @endforeach
                        @endif
                    </select>
                    <input type="text" wire:model="new_cron_expression" class="{{ $input }} font-mono sm:col-span-1" placeholder="0 3 * * *" />
                    <button type="submit" class="{{ $btnPrimary }} sm:col-span-1" @disabled(! $opsReady)>
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add schedule') }}
                    </button>
                    <div class="flex flex-col gap-2 sm:col-span-5 sm:flex-row sm:items-center">
                        <select wire:model="new_backup_configuration_id" class="{{ $input }} sm:flex-1">
                            <option value="">{{ __('Pick a backup destination…') }}</option>
                            @foreach ($backupConfigurations as $cfg)
                                <option value="{{ $cfg->id }}">{{ $cfg->name }} ({{ \App\Models\BackupConfiguration::labelForProvider($cfg->provider) }})</option>
                            @endforeach
                        </select>
                        <button
                            type="button"
                            wire:click="openDestinationModal"
                            class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Add destination') }}
                        </button>
                    </div>
                </form>
            @endif

            @if ($schedules->isEmpty())
                <p class="rounded-xl border border-dashed border-brand-ink/15 bg-white px-4 py-8 text-center text-sm text-brand-moss">{{ __('No recurring backups scheduled yet.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                    @foreach ($schedules as $schedule)
                        @php
                            $isEditing = array_key_exists($schedule->id, $editing_schedules);
                            $meta = $scheduleMeta[$schedule->id] ?? ['next_run_at' => null, 'latest_status' => null, 'recent_runs' => collect()];
                            $recentRuns = $meta['recent_runs'] ?? collect();
                            $latestStatusTone = match ($meta['latest_status']) {
                                'completed' => ['border-emerald-200 bg-emerald-50 text-emerald-700', 'm-check-circle', __('Healthy')],
                                'failed' => ['border-rose-200 bg-rose-50 text-rose-700', 'm-x-circle', __('Last failed')],
                                'pending', 'running' => ['border-amber-200 bg-amber-50 text-amber-800', 'm-clock', __('Running')],
                                default => null,
                            };
                        @endphp
                        <li wire:key="sched-{{ $schedule->id }}" x-data="{ historyOpen: false }" class="flex flex-col gap-3 bg-white px-4 py-3 transition-colors hover:bg-brand-sand/15">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <p class="truncate text-sm font-semibold text-brand-ink">{{ $schedule->targetLabel() }}</p>
                                        @if ($latestStatusTone)
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $latestStatusTone[0] }}">
                                                @if ($latestStatusTone[1] === 'm-check-circle')
                                                    <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                @elseif ($latestStatusTone[1] === 'm-x-circle')
                                                    <x-heroicon-m-x-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                @else
                                                    <x-heroicon-m-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                @endif
                                                {{ $latestStatusTone[2] }}
                                            </span>
                                        @endif
                                        @if (! $schedule->is_active)
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">
                                                <x-heroicon-m-pause class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Paused') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-brand-mist">
                                        <span class="inline-flex items-center gap-1">
                                            <span class="text-[10px] uppercase tracking-wide">{{ __('Type') }}</span>
                                            <span class="text-brand-ink">{{ $schedule->target_type }}</span>
                                        </span>
                                        <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                        @if ($isEditing)
                                            <input
                                                type="text"
                                                wire:model="editing_schedules.{{ $schedule->id }}"
                                                wire:keydown.enter="saveScheduleCadence('{{ $schedule->id }}')"
                                                class="rounded border border-brand-ink/20 bg-white px-1.5 py-0.5 font-mono text-[11px] text-brand-ink"
                                                placeholder="0 3 * * *"
                                            />
                                        @else
                                            <span class="font-mono text-brand-ink">{{ $schedule->cron_expression }}</span>
                                        @endif
                                        <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                        <button type="button" wire:click="toggleNotifyOnFailure('{{ $schedule->id }}')"
                                            title="{{ $schedule->notify_on_failure ? __('Email alerts on failure are ON. Click to disable.') : __('Email alerts on failure are OFF. Click to enable.') }}"
                                            class="inline-flex items-center gap-1 {{ $schedule->notify_on_failure ? 'text-brand-ink' : 'text-brand-mist line-through' }}">
                                            @if ($schedule->notify_on_failure)
                                                <x-heroicon-m-bell class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            @else
                                                <x-heroicon-m-bell-slash class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            @endif
                                            {{ __('alerts') }}
                                        </button>
                                        @if ($schedule->notify_on_failure)
                                            <button type="button" wire:click="sendTestAlert('{{ $schedule->id }}')"
                                                wire:loading.attr="disabled" wire:target="sendTestAlert"
                                                title="{{ __('Send a test alert email to org admins right now.') }}"
                                                class="text-brand-mist underline-offset-2 hover:text-brand-ink hover:underline">
                                                {{ __('test') }}
                                            </button>
                                        @endif
                                        @if ($schedule->last_run_at)
                                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                            <span>{{ __('last :ts', ['ts' => $schedule->last_run_at->diffForHumans()]) }}</span>
                                        @endif
                                        @if ($meta['next_run_at'] !== null)
                                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                            <span>{{ __('next :ts', ['ts' => \Illuminate\Support\Carbon::instance($meta['next_run_at'])->diffForHumans()]) }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                    @if ($isEditing)
                                        <button type="button" wire:click="saveScheduleCadence('{{ $schedule->id }}')" class="{{ $btnPrimary }}">
                                            <x-heroicon-m-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Save') }}
                                        </button>
                                        <button type="button" wire:click="cancelEditSchedule('{{ $schedule->id }}')" class="{{ $btnOutline }}">
                                            {{ __('Cancel') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="runScheduleNow('{{ $schedule->id }}')" wire:loading.attr="disabled" wire:target="runScheduleNow" class="{{ $btnOutline }}">
                                            <x-heroicon-m-play class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Run now') }}
                                        </button>
                                        <button type="button" @click="historyOpen = !historyOpen" class="{{ $btnOutline }}">
                                            <x-heroicon-m-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            <span x-text="historyOpen ? '{{ __('Hide history') }}' : '{{ __('History') }}'"></span>
                                        </button>
                                        <button type="button" wire:click="startEditSchedule('{{ $schedule->id }}')" class="{{ $btnOutline }}">
                                            <x-heroicon-m-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Edit') }}
                                        </button>
                                        <button type="button" wire:click="toggleSchedule('{{ $schedule->id }}')" class="{{ $btnOutline }}">
                                            @if ($schedule->is_active)
                                                <x-heroicon-m-pause class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Pause') }}
                                            @else
                                                <x-heroicon-m-play class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Resume') }}
                                            @endif
                                        </button>
                                        <button type="button" wire:click="deleteSchedule('{{ $schedule->id }}')" wire:confirm="{{ __('Remove this backup schedule?') }}" class="{{ $btnDanger }}">
                                            <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                            {{-- Inline history panel — last 5 backup runs against the same target. --}}
                            <div x-show="historyOpen" x-cloak class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3">
                                @if ($recentRuns->isEmpty())
                                    <p class="text-center text-xs text-brand-moss">{{ __('No runs yet for this target.') }}</p>
                                @else
                                    <ul class="divide-y divide-brand-ink/10">
                                        @foreach ($recentRuns as $run)
                                            <li class="flex items-center gap-3 py-2 text-xs">
                                                <span class="inline-flex w-24 items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusChip($run->status) }}">{{ $run->status }}</span>
                                                <span class="w-20 font-mono tabular-nums text-brand-mist">{{ $run->bytes ? $formatBytes((int) $run->bytes) : '—' }}</span>
                                                <span class="flex-1 truncate text-brand-mist">
                                                    @if ($run->error_message)
                                                        <span class="text-rose-700">{{ $run->error_message }}</span>
                                                    @endif
                                                </span>
                                                <span class="text-brand-mist">{{ $run->created_at?->diffForHumans() }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    </x-server-workspace-tab-panel>
    @endif

    @if ($backups_workspace_tab === 'history')
    <x-server-workspace-tab-panel id="backups-panel-history" labelled-by="backups-tab-history" panel-class="space-y-6">
    {{-- Recent runs ---------------------------------------------------------------- --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sand'] }}">
                        <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('History') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent database backups') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Latest exports by database, with size and status.') }}</p>
                    </div>
                    @if ($databaseBackups->isNotEmpty())
                        <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $databaseBackups->count() }}</span>
                    @endif
                </div>
            </div>
            @if ($databaseBackups->isEmpty())
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No database backups yet.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($databaseBackups as $backup)
                        <li wire:key="db-bk-{{ $backup->id }}" class="flex items-center gap-4 px-6 py-3 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ optional($backup->serverDatabase)->name ?? '(deleted)' }}</p>
                                    <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusChip($backup->status) }}">{{ $backup->status }}</span>
                                </div>
                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                    @if ($backup->bytes)
                                        <span class="font-mono tabular-nums text-brand-ink">{{ $formatBytes((int) $backup->bytes) }}</span>
                                        <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                    @endif
                                    {{ $backup->created_at?->diffForHumans() }}
                                </p>
                                @if ($backup->error_message)
                                    <p class="mt-1 truncate text-xs text-rose-700">{{ $backup->error_message }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if ($backup->status === 'completed' && ! empty($backup->disk_path))
                                    <button type="button" wire:click="downloadDatabaseBackup('{{ $backup->id }}')" class="{{ $btnOutline }}">
                                        <x-heroicon-m-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Download') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="deleteDatabaseBackup('{{ $backup->id }}')" wire:confirm="{{ __('Delete this backup?') }}" class="{{ $btnDanger }}">
                                    <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sand'] }}">
                        <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('History') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent site file backups') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Latest archives by site, with size and status.') }}</p>
                    </div>
                    @if ($fileBackups->isNotEmpty())
                        <span class="shrink-0 rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $fileBackups->count() }}</span>
                    @endif
                </div>
            </div>
            @if ($fileBackups->isEmpty())
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No site file backups yet.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($fileBackups as $backup)
                        <li wire:key="fb-{{ $backup->id }}" class="flex items-center gap-4 px-6 py-3 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ optional($backup->site)->name ?? '(deleted)' }}</p>
                                    <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusChip($backup->status) }}">{{ $backup->status }}</span>
                                </div>
                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                    @if ($backup->bytes)
                                        <span class="font-mono tabular-nums text-brand-ink">{{ $formatBytes((int) $backup->bytes) }}</span>
                                        <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                    @endif
                                    {{ $backup->created_at?->diffForHumans() }}
                                </p>
                                @if ($backup->error_message)
                                    <p class="mt-1 truncate text-xs text-rose-700">{{ $backup->error_message }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if ($backup->status === 'completed' && ! empty($backup->disk_path))
                                    <button type="button" wire:click="downloadFileBackup('{{ $backup->id }}')" class="{{ $btnOutline }}">
                                        <x-heroicon-m-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Download') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="deleteFileBackup('{{ $backup->id }}')" wire:confirm="{{ __('Delete this backup?') }}" class="{{ $btnDanger }}">
                                    <x-heroicon-m-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
    </x-server-workspace-tab-panel>
    @endif

    </div>{{-- /tab container --}}

    {{-- Lightweight refresh so pending → completed transitions show without manual reload. --}}
    @if ($databaseBackups->where('status', 'pending')->isNotEmpty() || $fileBackups->where('status', 'pending')->isNotEmpty())
        <div wire:poll.10s="$refresh" class="hidden" aria-hidden="true"></div>
    @endif

    {{-- CLI equivalents — same idea as Cron / Daemons pages. Lets operators script the same flows. --}}
    <x-cli-snippet :commands="[
        ['label' => __('Run all due backup schedules now'), 'command' => 'dply:run-backup-schedule {schedule_id}'],
        ['label' => __('Prune old backups (dry-run)'), 'command' => 'dply:prune-backups --dry-run'],
        ['label' => __('Prune old backups for real'), 'command' => 'dply:prune-backups'],
    ]" />

    {{-- Add backup destination modal. --}}
    @if ($showDestinationModal)
        <div
            class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
            role="dialog"
            aria-modal="true"
            aria-labelledby="add-destination-title"
            x-data
            x-on:keydown.escape.window="$wire.closeDestinationModal()"
        >
            <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeDestinationModal"></div>
            <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                <div class="my-auto flex w-full max-w-2xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-cloud-arrow-up class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Storage') }}</p>
                            <h2 id="add-destination-title" class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Add backup destination') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Shared with every server in your organization. Credentials are encrypted at rest.') }}</p>
                        </div>
                        <button type="button" wire:click="closeDestinationModal" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                            <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="dest_name" :value="__('Name')" />
                                <x-text-input id="dest_name" wire:model="destinationForm.name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production S3') }}" autocomplete="off" />
                                <x-input-error :messages="$errors->get('destinationForm.name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="dest_provider" :value="__('Storage provider')" />
                                <select id="dest_provider" wire:model.live="destinationForm.provider" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                    @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                        <option value="{{ $p }}">{{ \App\Models\BackupConfiguration::labelForProvider($p) }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('destinationForm.provider')" class="mt-2" />
                            </div>
                        </div>

                        @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'destinationForm', 'form' => $destinationForm])
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeDestinationModal">{{ __('Cancel') }}</x-secondary-button>
                        <button
                            type="button"
                            wire:click="saveDestination"
                            wire:loading.attr="disabled"
                            wire:target="saveDestination"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="saveDestination" class="inline-flex items-center gap-2">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Save destination') }}
                            </span>
                            <span wire:loading wire:target="saveDestination" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
