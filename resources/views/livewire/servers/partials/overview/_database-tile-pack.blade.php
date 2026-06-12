{{-- Database-native tile pack. server_role=database hosts replace
     the generic app-server tiles with engine / database / backup
     summaries. Values come from control-plane rows only.
     $healthValue / $healthMeta come from the parent view. --}}
@if ($isDatabaseRoleHost && $databaseTileData !== null)
    @php
        $databasesUrl = route('servers.databases', $server);
        $backupsUrl = route('servers.backups', $server);
        $engineLabel = $databaseTileData['engine_label'];
        $engineStatus = $databaseTileData['status'];
        $engineHeadline = match ($engineStatus) {
            \App\Models\ServerDatabaseEngine::STATUS_RUNNING => __('Running'),
            \App\Models\ServerDatabaseEngine::STATUS_STOPPED => __('Stopped'),
            \App\Models\ServerDatabaseEngine::STATUS_INSTALLING => __('Installing'),
            \App\Models\ServerDatabaseEngine::STATUS_PENDING => __('Pending'),
            default => __('Not installed'),
        };
        $engineMeta = $databaseTileData['version']
            ? $engineLabel.' '.$databaseTileData['version']
            : ($databaseTileData['engine'] ? $engineLabel : __('Open Database to install'));
        $backupHeadline = match (true) {
            ($databaseTileData['failed_backups_7d'] ?? 0) > 0 => trans_choice('{1} :count failed backup (7d)|[2,*] :count failed backups (7d)', $databaseTileData['failed_backups_7d'], ['count' => $databaseTileData['failed_backups_7d']]),
            ($databaseTileData['active_schedules'] ?? 0) > 0 => trans_choice('{1} :count active schedule|[2,*] :count active schedules', $databaseTileData['active_schedules'], ['count' => $databaseTileData['active_schedules']]),
            default => __('No schedules yet'),
        };
        $backupMeta = ($databaseTileData['paused_schedules'] ?? 0) > 0
            ? trans_choice('{1} :count paused schedule|[2,*] :count paused schedules', $databaseTileData['paused_schedules'], ['count' => $databaseTileData['paused_schedules']])
            : __('Database backup cron on this host');
    @endphp
    <section class="dply-card overflow-hidden">
        <div class="px-6 pt-5 pb-4 sm:px-7">
            <div class="flex items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine workspace', ['engine' => $engineLabel]) }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Engine status and databases on this host. Each tile drops you onto the full Database workspace.') }}</p>
                </div>
            </div>
        </div>
        <div class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-3">
            <a href="{{ $databasesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $engineHeadline }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $engineMeta }}</p>
                <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                    {{ __('Open Database') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </p>
            </a>

            <a href="{{ $databasesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Databases') }}</p>
                <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ number_format((int) $databaseTileData['database_count']) }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ __('User databases on this host') }}</p>
            </a>

            <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Health') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $healthValue }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $healthMeta }}</p>
            </a>

            <a href="{{ $backupsUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Backups') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $backupHeadline }}</p>
                <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $backupMeta }}</p>
            </a>
        </div>
    </section>
@endif
