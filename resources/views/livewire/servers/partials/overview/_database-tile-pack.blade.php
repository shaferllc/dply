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
        {{-- Compact header, matching the onboarding checklist card above it: one
             row, no description paragraph. What the description said ("each tile
             drops you onto the full Database workspace") is already obvious from
             the tiles being links, so it was costing a line of vertical space to
             restate the affordance. --}}
        <div class="flex items-center gap-3 px-6 py-4 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <h3 class="min-w-0 flex-1 text-base font-semibold text-brand-ink">{{ __(':engine workspace', ['engine' => $engineLabel]) }}</h3>
            <a href="{{ $databasesUrl }}" wire:navigate class="hidden shrink-0 items-center gap-1 text-xs font-semibold text-brand-sage transition hover:text-brand-ink sm:inline-flex">
                {{ __('Open Database') }}
                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </a>
        </div>
        {{-- Four columns, not three: with four tiles a 3-col grid orphaned
             "Backups" onto a row of its own. --}}
        <div class="grid gap-3 px-6 pb-5 sm:grid-cols-2 sm:px-7 lg:grid-cols-4">
            <a href="{{ $databasesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $engineHeadline }}</p>
                {{-- The hover-reveal "Open Database" hint that used to sit here was
                     opacity-0, not hidden, so it still took a line and left this
                     tile visibly taller than its three siblings. The header link
                     now says it once, always. --}}
                <p class="mt-0.5 truncate text-xs text-brand-moss">{{ $engineMeta }}</p>
            </a>

            <a href="{{ $databasesUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Databases') }}</p>
                <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ number_format((int) $databaseTileData['database_count']) }}</p>
                <p class="mt-0.5 truncate text-xs text-brand-moss">{{ __('User databases on this host') }}</p>
            </a>

            <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Health') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $healthValue }}</p>
                <p class="mt-0.5 truncate text-xs text-brand-moss">{{ $healthMeta }}</p>
            </a>

            <a href="{{ $backupsUrl }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Backups') }}</p>
                <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $backupHeadline }}</p>
                <p class="mt-0.5 truncate text-xs text-brand-moss">{{ $backupMeta }}</p>
            </a>
        </div>
    </section>
@endif
