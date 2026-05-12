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

    <x-explainer class="mb-4">
        <p>{{ __('“Run now” creates a pending backup row and queues the export job — progress shows up in the lists below as the job completes. Schedules add a managed cron entry that fires the same job on the cadence you set.') }}</p>
        @if ($backupConfigurations->isEmpty())
            <p class="mt-2">{{ __('Backups currently write to the local disk. To send them to S3 / Dropbox / Google Drive / SFTP, ') }}<a href="{{ route('profile.backup-configurations') }}" wire:navigate class="font-semibold text-brand-ink underline">{{ __('add a backup destination') }}</a>{{ __(' first.') }}</p>
        @endif
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
                @if ($backupConfigurations->isNotEmpty())
                    <select wire:model="new_backup_configuration_id" class="{{ $input }} sm:col-span-5">
                        <option value="">{{ __('Default backup destination') }}</option>
                        @foreach ($backupConfigurations as $cfg)
                            <option value="{{ $cfg->id }}">{{ $cfg->name }} ({{ \App\Models\BackupConfiguration::labelForProvider($cfg->provider) }})</option>
                        @endforeach
                    </select>
                @endif
            </form>

            @if ($schedules->isEmpty())
                <p class="text-center text-sm text-brand-moss">{{ __('No recurring backups scheduled yet.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                    @foreach ($schedules as $schedule)
                        @php
                            $isEditing = array_key_exists($schedule->id, $editing_schedules);
                            $meta = $scheduleMeta[$schedule->id] ?? ['next_run_at' => null, 'latest_status' => null];
                        @endphp
                        <li class="flex items-center gap-4 px-4 py-3">
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
</x-server-workspace-layout>
