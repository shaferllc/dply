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
    @php
        // Joined hairline tiles, same construction as the identity hero's fact
        // grid: one rounded container, tint showing through 1px gaps, white
        // cells that tint on hover. Replaces four floating rounded-2xl cards
        // with their own borders and shadows.
        $packCell = 'block bg-white px-3 py-2 transition-colors hover:bg-brand-sand/[0.15] sm:px-4';
        $packLabel = 'text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist';
        $packValue = 'mt-1 truncate text-sm font-semibold text-brand-ink';
        $packMeta = 'mt-0.5 truncate text-xs text-brand-moss';
    @endphp
    <section class="dply-card overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-circle-stack"
            :title="__(':engine workspace', ['engine' => $engineLabel])"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <a href="{{ $databasesUrl }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage transition hover:text-brand-ink">
                    {{ __('Open Database') }}
                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            </x-slot:actions>
        </x-workspace-panel-head>

        {{-- Four columns, not three: with four tiles a 3-col grid orphaned
             "Backups" onto a row of its own. --}}
        <div class="px-3 py-3 sm:px-4">
            <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.07] shadow-sm sm:grid-cols-2 lg:grid-cols-4">
                <a href="{{ $databasesUrl }}" wire:navigate class="{{ $packCell }}">
                    <dt class="{{ $packLabel }}">{{ __('Engine') }}</dt>
                    <dd class="{{ $packValue }}">{{ $engineHeadline }}</dd>
                    <dd class="{{ $packMeta }}">{{ $engineMeta }}</dd>
                </a>

                <a href="{{ $databasesUrl }}" wire:navigate class="{{ $packCell }}">
                    <dt class="{{ $packLabel }}">{{ __('Databases') }}</dt>
                    <dd class="mt-1 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ number_format((int) $databaseTileData['database_count']) }}</dd>
                    <dd class="{{ $packMeta }}">{{ __('User databases on this host') }}</dd>
                </a>

                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="{{ $packCell }}">
                    <dt class="{{ $packLabel }}">{{ __('Health') }}</dt>
                    <dd class="{{ $packValue }}">{{ $healthValue }}</dd>
                    <dd class="{{ $packMeta }}">{{ $healthMeta }}</dd>
                </a>

                <a href="{{ $backupsUrl }}" wire:navigate class="{{ $packCell }}">
                    <dt class="{{ $packLabel }}">{{ __('Backups') }}</dt>
                    <dd class="{{ $packValue }}">{{ $backupHeadline }}</dd>
                    <dd class="{{ $packMeta }}">{{ $backupMeta }}</dd>
                </a>
            </dl>
        </div>
    </section>
@endif
