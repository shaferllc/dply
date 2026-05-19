@php
    $card = 'dply-card overflow-hidden';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $btnDangerSm = 'inline-flex items-center justify-center gap-1 rounded-md bg-red-50 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-red-700 hover:bg-red-100';
    $input = 'block w-full rounded-lg border border-brand-ink/20 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30';
    $statusClass = fn (string $status) => match ($status) {
        'completed' => 'text-brand-forest',
        'failed' => 'text-red-700',
        default => 'text-brand-moss',
    };
    $formatBytes = function (?int $bytes): string {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return number_format($value, $i === 0 ? 0 : 1).' '.$units[$i];
    };
@endphp

<x-server-workspace-layout
    :server="$server"
    active="backups"
    :title="__('Backups')"
    :description="__('Recent database and site-files backup runs for this server, plus recurring schedules. Backups write to the destination configured in your account Settings → Backup configurations.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($contextSite)
        <div class="mb-4 flex items-center justify-between rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm">
            <p class="text-brand-ink">
                <span class="font-semibold">{{ __('Filtered to site:') }}</span>
                {{ $contextSite->name }}
                <span class="text-brand-mist">·</span>
                <span class="text-brand-moss">{{ __('database backups are hidden because databases are server-scoped, not site-scoped.') }}</span>
            </p>
            <a href="{{ route('servers.backups', $server) }}" wire:navigate class="text-xs font-semibold text-brand-ink underline">{{ __('Clear filter') }}</a>
        </div>
    @endif

    <x-explainer class="mb-4">
        <p>{{ __('“Run now” creates a pending backup row and queues the export job — progress shows up in the lists below as the job completes. Schedules add a managed cron entry that fires the same job on the cadence you set.') }}</p>
        @if ($backupConfigurations->isEmpty())
            <p class="mt-2">
                {{ __('No backup destinations yet. Add an S3 bucket / Dropbox / Google Drive / SFTP target to send backups somewhere you own — ') }}
                <button type="button" wire:click="openDestinationModal" class="font-semibold text-brand-ink underline hover:no-underline">{{ __('add one now') }}</button>{{ __('.') }}
            </p>
        @endif
        <p class="mt-2 text-xs"><a href="{{ route('servers.activity', $server) }}?category=background" wire:navigate class="font-semibold text-brand-ink underline">{{ __('View background activity →') }}</a></p>
    </x-explainer>

    {{-- At-a-glance health strip — last 7 days for completed/failed counts. --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Database backups (7d)') }}</p>
            <p class="mt-1 flex items-baseline gap-2">
                <span class="text-2xl font-semibold text-brand-forest">{{ $stats['db_completed_7d'] }}</span>
                @if ($stats['db_failed_7d'] > 0)
                    <span class="text-sm font-semibold text-red-700">{{ $stats['db_failed_7d'] }} {{ __('failed') }}</span>
                @endif
            </p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Site file backups (7d)') }}</p>
            <p class="mt-1 flex items-baseline gap-2">
                <span class="text-2xl font-semibold text-brand-forest">{{ $stats['files_completed_7d'] }}</span>
                @if ($stats['files_failed_7d'] > 0)
                    <span class="text-sm font-semibold text-red-700">{{ $stats['files_failed_7d'] }} {{ __('failed') }}</span>
                @endif
            </p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Total stored') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $formatBytes($stats['total_bytes']) }}</p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Active schedules') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $schedules->where('is_active', true)->count() }}</p>
        </div>
    </section>

    {{-- Run now -------------------------------------------------------------------- --}}
    <section class="grid gap-4 lg:grid-cols-2">
        <div class="{{ $card }}">
            <header class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Run database backup') }}</h2>
            </header>
            <div class="space-y-3 p-5">
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
                        {{ __('Run database backup now') }}
                    </button>
                @endif
            </div>
        </div>

        <div class="{{ $card }}">
            <header class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Run site files backup') }}</h2>
            </header>
            <div class="space-y-3 p-5">
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
                        {{ __('Run files backup now') }}
                    </button>
                @endif
            </div>
        </div>
    </section>

    {{-- Schedules ------------------------------------------------------------------ --}}
    <section class="{{ $card }}">
        <header class="border-b border-brand-ink/10 px-5 py-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Recurring schedules') }}</h2>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Each schedule materializes a managed cron entry that calls dply:run-backup-schedule {id}.') }}</p>
        </header>

        <div class="space-y-4 p-5">
            @if ($backupConfigurations->isEmpty())
                {{-- No destinations means no schedule can actually push anywhere, so
                     skip the cadence/target/cron form entirely until the operator
                     adds a destination. Showing it under "No destinations yet" was
                     noise — the only meaningful action is "Add destination". --}}
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center">
                    <x-heroicon-o-cloud-arrow-up class="mx-auto h-8 w-8 text-brand-mist" />
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __('Add a backup destination to start scheduling') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Schedules push backups to a destination you own (S3, B2, R2, Spaces, SFTP, Dropbox, Google Drive, or rclone). Add one to unlock the recurring-schedule form.') }}</p>
                    <button
                        type="button"
                        wire:click="openDestinationModal"
                        class="mt-4 inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4" />
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
                            class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add destination') }}
                        </button>
                    </div>
                </form>
            @endif

            @if ($schedules->isEmpty())
                <p class="text-center text-sm text-brand-moss">{{ __('No recurring backups scheduled yet.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                    @foreach ($schedules as $schedule)
                        @php
                            $isEditing = array_key_exists($schedule->id, $editing_schedules);
                            $meta = $scheduleMeta[$schedule->id] ?? ['next_run_at' => null, 'latest_status' => null, 'recent_runs' => collect()];
                            $recentRuns = $meta['recent_runs'] ?? collect();
                        @endphp
                        <li x-data="{ historyOpen: false }" class="flex flex-col gap-2 px-4 py-3">
                          <div class="flex items-center gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $schedule->targetLabel() }}</p>
                                    @if ($meta['latest_status'] === 'completed')
                                        <span class="inline-flex items-center rounded-full bg-brand-forest/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('healthy') }}</span>
                                    @elseif ($meta['latest_status'] === 'failed')
                                        <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700">{{ __('last failed') }}</span>
                                    @elseif ($meta['latest_status'] === 'pending')
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">{{ __('running') }}</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] uppercase tracking-wide text-brand-mist">
                                    <span>{{ $schedule->target_type }}</span>
                                    <span>·</span>
                                    @if ($isEditing)
                                        <input
                                            type="text"
                                            wire:model="editing_schedules.{{ $schedule->id }}"
                                            wire:keydown.enter="saveScheduleCadence('{{ $schedule->id }}')"
                                            class="rounded border border-brand-ink/20 bg-white px-1.5 py-0.5 font-mono text-[11px] normal-case tracking-normal text-brand-ink"
                                            placeholder="0 3 * * *"
                                        />
                                    @else
                                        <span class="font-mono normal-case tracking-normal">{{ $schedule->cron_expression }}</span>
                                    @endif
                                    @if (! $schedule->is_active)
                                        <span>·</span>
                                        <span class="text-amber-700">{{ __('paused') }}</span>
                                    @endif
                                    <span>·</span>
                                    <button type="button" wire:click="toggleNotifyOnFailure('{{ $schedule->id }}')"
                                        title="{{ $schedule->notify_on_failure ? __('Email alerts on failure are ON. Click to disable.') : __('Email alerts on failure are OFF. Click to enable.') }}"
                                        class="inline-flex items-center gap-1 normal-case tracking-normal {{ $schedule->notify_on_failure ? 'text-brand-ink' : 'text-brand-mist line-through' }}">
                                        @if ($schedule->notify_on_failure)
                                            <x-heroicon-m-bell class="h-3 w-3" />
                                        @else
                                            <x-heroicon-m-bell-slash class="h-3 w-3" />
                                        @endif
                                        {{ __('alerts') }}
                                    </button>
                                    @if ($schedule->notify_on_failure)
                                        <button type="button" wire:click="sendTestAlert('{{ $schedule->id }}')"
                                            wire:loading.attr="disabled" wire:target="sendTestAlert"
                                            title="{{ __('Send a test alert email to org admins right now.') }}"
                                            class="text-brand-mist underline-offset-2 hover:text-brand-ink hover:underline normal-case tracking-normal">
                                            {{ __('test') }}
                                        </button>
                                    @endif
                                    @if ($schedule->last_run_at)
                                        <span>·</span>
                                        <span>{{ __('last :ts', ['ts' => $schedule->last_run_at->diffForHumans()]) }}</span>
                                    @endif
                                    @if ($meta['next_run_at'] !== null)
                                        <span>·</span>
                                        <span>{{ __('next :ts', ['ts' => \Illuminate\Support\Carbon::instance($meta['next_run_at'])->diffForHumans()]) }}</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($isEditing)
                                    <button type="button" wire:click="saveScheduleCadence('{{ $schedule->id }}')" class="{{ $btnPrimary }}">
                                        {{ __('Save') }}
                                    </button>
                                    <button type="button" wire:click="cancelEditSchedule('{{ $schedule->id }}')" class="{{ $btnSecondary }}">
                                        {{ __('Cancel') }}
                                    </button>
                                @else
                                    <button type="button" wire:click="runScheduleNow('{{ $schedule->id }}')" wire:loading.attr="disabled" wire:target="runScheduleNow" class="{{ $btnSecondary }}">
                                        {{ __('Run now') }}
                                    </button>
                                    <button type="button" @click="historyOpen = !historyOpen" class="{{ $btnSecondary }}">
                                        <span x-text="historyOpen ? '{{ __('Hide history') }}' : '{{ __('History') }}'"></span>
                                    </button>
                                    <button type="button" wire:click="startEditSchedule('{{ $schedule->id }}')" class="{{ $btnSecondary }}">
                                        {{ __('Edit') }}
                                    </button>
                                    <button type="button" wire:click="toggleSchedule('{{ $schedule->id }}')" class="{{ $btnSecondary }}">
                                        {{ $schedule->is_active ? __('Pause') : __('Resume') }}
                                    </button>
                                    <button type="button" wire:click="deleteSchedule('{{ $schedule->id }}')" wire:confirm="{{ __('Remove this backup schedule?') }}" class="{{ $btnDangerSm }}">
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
                                              <span class="w-20 {{ $statusClass($run->status) }}">{{ $run->status }}</span>
                                              <span class="w-20 text-brand-mist">{{ $run->bytes ? $formatBytes((int) $run->bytes) : '—' }}</span>
                                              <span class="flex-1 truncate text-brand-mist">
                                                  @if ($run->error_message)
                                                      <span class="text-red-700">{{ $run->error_message }}</span>
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

    {{-- Recent runs ---------------------------------------------------------------- --}}
    <section class="grid gap-4 lg:grid-cols-2">
        <div class="{{ $card }}">
            <header class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Recent database backups') }}</h2>
            </header>
            @if ($databaseBackups->isEmpty())
                <div class="px-5 py-8 text-center text-sm text-brand-moss">{{ __('No database backups yet.') }}</div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($databaseBackups as $backup)
                        <li class="flex items-center gap-4 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-brand-ink">{{ optional($backup->serverDatabase)->name ?? '(deleted)' }}</p>
                                <p class="mt-0.5 flex items-center gap-2 text-[11px] uppercase tracking-wide">
                                    <span class="{{ $statusClass($backup->status) }}">{{ $backup->status }}</span>
                                    @if ($backup->bytes)
                                        <span class="text-brand-mist">·</span>
                                        <span class="text-brand-mist">{{ $formatBytes((int) $backup->bytes) }}</span>
                                    @endif
                                </p>
                                @if ($backup->error_message)
                                    <p class="mt-1 truncate text-xs text-red-700">{{ $backup->error_message }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <p class="text-xs text-brand-mist">{{ $backup->created_at?->diffForHumans() }}</p>
                                @if ($backup->status === 'completed' && ! empty($backup->disk_path))
                                    <button type="button" wire:click="downloadDatabaseBackup('{{ $backup->id }}')" class="{{ $btnSecondary }}">
                                        {{ __('Download') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="deleteDatabaseBackup('{{ $backup->id }}')" wire:confirm="{{ __('Delete this backup?') }}" class="{{ $btnDangerSm }}">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="{{ $card }}">
            <header class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Recent site file backups') }}</h2>
            </header>
            @if ($fileBackups->isEmpty())
                <div class="px-5 py-8 text-center text-sm text-brand-moss">{{ __('No site file backups yet.') }}</div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($fileBackups as $backup)
                        <li class="flex items-center gap-4 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-brand-ink">{{ optional($backup->site)->name ?? '(deleted)' }}</p>
                                <p class="mt-0.5 flex items-center gap-2 text-[11px] uppercase tracking-wide">
                                    <span class="{{ $statusClass($backup->status) }}">{{ $backup->status }}</span>
                                    @if ($backup->bytes)
                                        <span class="text-brand-mist">·</span>
                                        <span class="text-brand-mist">{{ $formatBytes((int) $backup->bytes) }}</span>
                                    @endif
                                </p>
                                @if ($backup->error_message)
                                    <p class="mt-1 truncate text-xs text-red-700">{{ $backup->error_message }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <p class="text-xs text-brand-mist">{{ $backup->created_at?->diffForHumans() }}</p>
                                @if ($backup->status === 'completed' && ! empty($backup->disk_path))
                                    <button type="button" wire:click="downloadFileBackup('{{ $backup->id }}')" class="{{ $btnSecondary }}">
                                        {{ __('Download') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="deleteFileBackup('{{ $backup->id }}')" wire:confirm="{{ __('Delete this backup?') }}" class="{{ $btnDangerSm }}">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

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

    {{-- Add backup destination modal. Reuses the same provider-fields partial
         as the org-wide settings page so the form shape is identical in both
         surfaces (matches AuthorsBackupDestinations trait's validation and
         extract helpers). --}}
    @if ($showDestinationModal)
        <div
            class="fixed inset-0 z-50 overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="add-destination-title"
            x-data
            x-on:keydown.escape.window="$wire.closeDestinationModal()"
        >
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeDestinationModal"></div>
            <div class="relative flex min-h-full items-start justify-center px-4 py-10 sm:px-6">
                <div class="relative w-full max-w-2xl dply-modal-panel" wire:click.stop>
                    <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                        <div class="min-w-0">
                            <h2 id="add-destination-title" class="text-base font-semibold text-brand-ink">{{ __('Add backup destination') }}</h2>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Shared with every server in your organization. Credentials are encrypted at rest.') }}</p>
                        </div>
                        <button type="button" wire:click="closeDestinationModal" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                            <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="space-y-5 px-6 py-5 sm:px-7">
                        <div>
                            <x-input-label for="dest_name" :value="__('Name')" />
                            <x-text-input id="dest_name" wire:model="destinationForm.name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production S3') }}" autocomplete="off" />
                            <x-input-error :messages="$errors->get('destinationForm.name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="dest_provider" :value="__('Storage provider')" />
                            <select id="dest_provider" wire:model.live="destinationForm.provider" class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                    <option value="{{ $p }}">{{ \App\Models\BackupConfiguration::labelForProvider($p) }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('destinationForm.provider')" class="mt-2" />
                        </div>

                        @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'destinationForm', 'form' => $destinationForm])
                    </div>
                    <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                        <x-secondary-button type="button" wire:click="closeDestinationModal">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button type="button" wire:click="saveDestination" wire:loading.attr="disabled" wire:target="saveDestination">
                            <span wire:loading.remove wire:target="saveDestination">{{ __('Save destination') }}</span>
                            <span wire:loading wire:target="saveDestination" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-server-workspace-layout>
