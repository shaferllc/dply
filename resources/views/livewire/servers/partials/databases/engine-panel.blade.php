                @php
                    $engineRow = $engineRows[$engine] ?? null;
                    $engineRunning = $capabilities[$engine] ?? false;
                    $isManageable = in_array($engine, ['mysql', 'postgres'], true);
                    $dbEngineInfoForTab = \App\Support\Servers\DatabaseEngineInfo::for($engine);
                    $hasDbInfo = ! empty($dbEngineInfoForTab['description']);
                @endphp

                {{-- Per-engine sub-tab strip — Overview (engine status, databases, users)
                     and Info (license, maintainer, wire protocol, links). Mirrors the
                     pattern in WorkspaceCaches + WorkspaceWebserver. Info hidden when
                     the engine has no catalog entry (sqlite). --}}
                @if ($hasDbInfo)
                    <x-server-workspace-tablist :aria-label="__(':engine workspace sections', ['engine' => $engineLabels[$engine] ?? $engine])">
                        <x-server-workspace-tab
                            :id="'db-subtab-'.$engine.'-overview'"
                            icon="heroicon-o-presentation-chart-line"
                            :active="$engine_subtab === 'overview'"
                            wire:click="setEngineSubtab('overview')"
                        >
                            {{ __('Overview') }}
                        </x-server-workspace-tab>
                        <x-server-workspace-tab
                            :id="'db-subtab-'.$engine.'-info'"
                            icon="heroicon-o-information-circle"
                            :active="$engine_subtab === 'info'"
                            wire:click="setEngineSubtab('info')"
                        >
                            {{ __('Info') }}
                        </x-server-workspace-tab>
                    </x-server-workspace-tablist>
                @endif

                {{-- Per-engine console-action banner. Surfaces create/drop SSH output via the
                     shared ConsoleAction partial — tone-coded lines, copy-output, open-in-modal,
                     stale detection, grace-window polling. Subject is the ServerDatabaseEngine
                     row (or Server for sqlite); kind filter is `db_*`. --}}
                @php $dbRun = $dbRunsByEngine[$engine] ?? null; @endphp
                @if ($dbRun)
                    <div class="mb-4">
                        @include('livewire.partials.console-action-banner-static', [
                            'run' => $dbRun,
                            'kindLabels' => [],
                        ])
                    </div>
                @endif

                @if ($engine_subtab === 'info' && $hasDbInfo)
                    {{-- Info subtab: engine description, license, links, best-for. --}}
                    @include('livewire.servers.partials.cache-engine-info-card', [
                        'info' => $dbEngineInfoForTab,
                        'row' => $engineRow,
                        'card' => $card,
                    ])

                    {{-- MySQL operational hints + processlist action. Migrated from
                         /servers/{id}/manage/data when that tab was retired. The hint form
                         persists into $server->meta and is read by RunsAllowlistedManageAction
                         (mysql_* scripts export $DPLY_DB_PASSWORD); SHOW PROCESSLIST output
                         flows through the same console-action banner partial as other
                         workspace runs. --}}
                    @if ($engine === 'mysql')
                        @php
                            $manageDbHasCreds = ! empty($server->meta['manage_internal_db_password']);
                            $processlistAction = $serviceActions['mysql_processlist'] ?? null;
                        @endphp

                        @if ($manageActionRun)
                            @include('livewire.partials.console-action-banner-static', [
                                'run' => $manageActionRun,
                                'kindLabels' => (array) config('console_actions.kinds', []),
                            ])
                        @endif

                        <div class="{{ $card }} p-6 sm:p-8">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="max-w-2xl">
                                    <h3 class="text-lg font-semibold text-brand-ink">{{ __('Live processlist') }}</h3>
                                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Runs SHOW PROCESSLIST against the engine over SSH. Output streams into the banner above.') }}</p>
                                    @if (! $manageDbHasCreds)
                                        <p class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-xs text-brand-moss">
                                            {{ __('Add a manage password below to unlock the processlist when root requires auth.') }}
                                        </p>
                                    @endif
                                </div>
                                @if ($processlistAction && $opsReady && ! $isDeployer)
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('runAllowlistedManageAction', ['mysql_processlist'], @js($processlistAction['label']), @js($processlistAction['confirm']), @js($processlistAction['label']), false)"
                                        class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-bolt class="h-4 w-4 opacity-80" aria-hidden="true" />
                                        {{ $processlistAction['label'] }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div id="db-manage-hints-{{ $engine }}" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Database connection hints') }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Optional values for Dply features such as backups, and to authenticate the processlist action above.') }}
                            </p>
                            <form wire:submit="saveManageDbHints" class="mt-6 max-w-xl space-y-4">
                                <div>
                                    <label for="manage_db_bind_host" class="block text-sm font-medium text-brand-ink">{{ __('Database bind address') }}</label>
                                    <input
                                        id="manage_db_bind_host"
                                        type="text"
                                        wire:model="manage_db_bind_host"
                                        placeholder="127.0.0.1"
                                        autocomplete="off"
                                        @disabled($isDeployer)
                                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                                    />
                                    @error('manage_db_bind_host')
                                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="manage_db_port" class="block text-sm font-medium text-brand-ink">{{ __('Database port') }}</label>
                                    <input
                                        id="manage_db_port"
                                        type="number"
                                        wire:model="manage_db_port"
                                        placeholder="3306"
                                        min="1"
                                        max="65535"
                                        autocomplete="off"
                                        @disabled($isDeployer)
                                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                                    />
                                    @error('manage_db_port')
                                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="manage_db_password" class="block text-sm font-medium text-brand-ink">{{ __('Internal database password') }}</label>
                                    <input
                                        id="manage_db_password"
                                        type="password"
                                        wire:model="manage_db_password"
                                        placeholder="{{ __('Leave blank to keep current') }}"
                                        autocomplete="new-password"
                                        @disabled($isDeployer)
                                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                                    />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Used to authenticate the processlist action. Stored in server metadata; treat as sensitive.') }}</p>
                                </div>
                                <div>
                                    <x-primary-button type="submit" class="!py-2.5" :disabled="$isDeployer">{{ __('Save connection hints') }}</x-primary-button>
                                </div>
                            </form>
                        </div>
                    @endif
                @else

                @if ($isManageable)
                    {{-- Engine status / install card. Always present for mysql/postgres so the
                         operator can install / inspect / uninstall the engine itself. SQLite
                         skips this — it's a binary that ships with the others, no engine row. --}}
                    <div class="{{ $card }} p-6 sm:p-8">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold text-brand-ink">
                                    {{ __(':engine engine', ['engine' => $engineLabels[$engine]]) }}
                                </h2>
                                @if ($engineRow)
                                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                                        <div>
                                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                            <dd class="mt-1 text-sm text-brand-ink">
                                                @switch($engineRow->status)
                                                    @case(\App\Models\ServerDatabaseEngine::STATUS_RUNNING)
                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('Running') }}</span>
                                                        @break
                                                    @case(\App\Models\ServerDatabaseEngine::STATUS_STOPPED)
                                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ __('Stopped') }}</span>
                                                        @break
                                                    @case(\App\Models\ServerDatabaseEngine::STATUS_PENDING)
                                                    @case(\App\Models\ServerDatabaseEngine::STATUS_INSTALLING)
                                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                                            <x-spinner variant="forest" />
                                                            {{ __('Installing…') }}
                                                        </span>
                                                        @break
                                                    @case(\App\Models\ServerDatabaseEngine::STATUS_UNINSTALLING)
                                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">
                                                            <x-spinner variant="forest" />
                                                            {{ __('Uninstalling…') }}
                                                        </span>
                                                        @break
                                                    @case(\App\Models\ServerDatabaseEngine::STATUS_FAILED)
                                                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700" title="{{ $engineRow->error_message }}">{{ __('Failed') }}</span>
                                                        @break
                                                    @default
                                                        <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-medium text-brand-ink">{{ ucfirst($engineRow->status) }}</span>
                                                @endswitch
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
                                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $engineRow->version ?: '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Port') }}</dt>
                                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $engineRow->port }}</dd>
                                        </div>
                                    </dl>
                                    @if ($engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_FAILED && filled($engineRow->error_message))
                                        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">
                                            {{ $engineRow->error_message }}
                                        </p>
                                    @endif
                                @else
                                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                        {{ __(':engine is not installed on this server. Click Install to apt-install it. Dply will check available memory + disk first so a too-small box doesn\'t OOM mid-install.', ['engine' => $engineLabels[$engine]]) }}
                                    </p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2 self-start">
                                @if (! $engineRow || $engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_FAILED)
                                    <button
                                        type="button"
                                        wire:click="installDatabaseEngine('{{ $engine }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="installDatabaseEngine"
                                        class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                    >
                                        <x-heroicon-o-cloud-arrow-down class="h-4 w-4" />
                                        <span wire:loading.remove wire:target="installDatabaseEngine">
                                            {{ $engineRow ? __('Retry install') : __('Install :engine', ['engine' => $engineLabels[$engine]]) }}
                                        </span>
                                        <span wire:loading wire:target="installDatabaseEngine">{{ __('Queueing…') }}</span>
                                    </button>
                                @elseif ($engineRow && in_array($engineRow->status, [
                                    \App\Models\ServerDatabaseEngine::STATUS_PENDING,
                                    \App\Models\ServerDatabaseEngine::STATUS_INSTALLING,
                                ], true))
                                    {{-- Operator escape hatch when the install has stalled.
                                         Mirrors the webserver-switch "Stop & revert" affordance:
                                         marks the row FAILED and dispatches the uninstall job
                                         to apt-purge any partial state. --}}
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('stopAndRevertDatabaseEngineInstall', ['{{ $engine }}'], @js(__('Stop and revert :engine install?', ['engine' => $engineLabels[$engine]])), @js(__('Marks the install as failed and runs apt purge on the server to clean up any partial state. Use this when the install has stalled.')), @js(__('Stop & revert')), true)"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-50"
                                    >
                                        <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                                        {{ __('Stop & revert') }}
                                    </button>
                                @elseif ($engineRow && in_array($engineRow->status, [
                                    \App\Models\ServerDatabaseEngine::STATUS_RUNNING,
                                    \App\Models\ServerDatabaseEngine::STATUS_STOPPED,
                                ], true))
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('uninstallDatabaseEngine', ['{{ $engine }}'], @js(__('Uninstall :engine', ['engine' => $engineLabels[$engine]])), @js(__('apt purge will remove the engine and its data dirs from the server. Existing tracked databases stay in Dply but won\'t have a live engine to talk to.')), @js(__('Uninstall')), true)"
                                        class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100"
                                    >
                                        {{ __('Uninstall') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                @if ($engine === 'sqlite' || $engineRunning)
                    @if ($engine !== 'sqlite')
                        <div class="{{ $card }} p-6 sm:p-8">
                            <h2 class="text-lg font-semibold text-brand-ink">
                                {{ __(':engine admin credentials', ['engine' => $engineLabels[$engine]]) }}
                            </h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Optional admin password used over SSH when passwordless sudo or socket access is not available. Stored encrypted.') }}
                            </p>
                            <form wire:submit="saveAdminCredentials" class="mt-8 space-y-6">
                                @if ($engine === 'mysql')
                                    <div class="grid gap-6 sm:grid-cols-2">
                                        <div>
                                            <x-input-label for="admin_mysql_root_username" value="{{ __('MySQL root username') }}" />
                                            <x-text-input id="admin_mysql_root_username" wire:model="admin_mysql_root_username" class="mt-1 block w-full font-mono text-sm" />
                                        </div>
                                        <div>
                                            <x-input-label for="admin_mysql_root_password" value="{{ __('MySQL root password (optional)') }}" />
                                            <x-text-input id="admin_mysql_root_password" type="password" wire:model="admin_mysql_root_password" class="mt-1 block w-full text-sm" placeholder="{{ __('Leave blank to keep existing') }}" autocomplete="new-password" />
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-3">
                                        <x-primary-button type="submit">{{ __('Save admin credentials') }}</x-primary-button>
                                        <button type="button" wire:click="clearStoredMysqlRootPassword" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Clear MySQL password') }}</button>
                                    </div>
                                @else
                                    <div class="grid gap-6 sm:grid-cols-2">
                                        <div>
                                            <x-input-label for="admin_postgres_superuser" value="{{ __('PostgreSQL superuser') }}" />
                                            <x-text-input id="admin_postgres_superuser" wire:model="admin_postgres_superuser" class="mt-1 block w-full font-mono text-sm" />
                                        </div>
                                        <div>
                                            <x-input-label for="admin_postgres_password" value="{{ __('PostgreSQL password (optional)') }}" />
                                            <x-text-input id="admin_postgres_password" type="password" wire:model="admin_postgres_password" class="mt-1 block w-full text-sm" placeholder="{{ __('Leave blank to keep existing') }}" autocomplete="new-password" />
                                        </div>
                                    </div>
                                    <label class="flex items-start gap-2 text-sm text-brand-ink">
                                        <input type="checkbox" wire:model="admin_postgres_use_sudo" class="mt-1 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                                        <span>{{ __('Use sudo -u postgres for PostgreSQL (disable to use TCP auth with password above)') }}</span>
                                    </label>
                                    <div class="flex flex-wrap gap-3">
                                        <x-primary-button type="submit">{{ __('Save admin credentials') }}</x-primary-button>
                                        <button type="button" wire:click="clearStoredPostgresPassword" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Clear PostgreSQL password') }}</button>
                                    </div>
                                @endif
                            </form>
                        </div>
                    @endif

                    @php
                        $engineDatabases = $server->serverDatabases->where('engine', $engine);
                        $engineSampleDatabase = $engineDatabases->sortBy('name')->first();
                    @endphp
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">
                            {{ __(':engine databases', ['engine' => $engineLabels[$engine]]) }}
                        </h2>
                        @if ($engineDatabases->isEmpty())
                            <p class="mt-3 text-sm text-brand-moss">
                                {{ __('No :engine databases yet. Switch to Basics to create one.', ['engine' => $engineLabels[$engine]]) }}
                            </p>
                        @else
                            <div class="mt-4">
                                @include('livewire.servers.partials.databases-list', [
                                    'databases' => $engineDatabases,
                                ])
                            </div>
                        @endif
                    </div>

                    @include('livewire.servers.partials.connection-snippet', [
                        'database' => $engineSampleDatabase,
                        'card' => $card,
                    ])

                    @if ($engine !== 'sqlite')
                        @include('livewire.servers.partials.extra-users-card', [
                            'databases' => $engineDatabases,
                            'engine' => $engine,
                            'engineLabels' => $engineLabels,
                            'card' => $card,
                        ])

                        @include('livewire.servers.partials.drift-card', [
                            'engine' => $engine,
                            'engineLabels' => $engineLabels,
                            'drift_snapshot' => $drift_snapshot,
                            'card' => $card,
                        ])

                        @include('livewire.servers.partials.share-credentials-form', [
                            'databases' => $engineDatabases,
                            'orgAllowsCredentialShares' => $orgAllowsCredentialShares,
                            'card' => $card,
                        ])
                    @endif

                    @include('livewire.servers.partials.destructive-actions', [
                        'databases' => $engineDatabases,
                        'engineLabels' => $engineLabels,
                        'card' => $card,
                    ])

                    @include('livewire.servers.partials.recent-backups-list', [
                        'backups' => ($recentBackupsByEngine[$engine] ?? collect()),
                        'card' => $card,
                    ])
                @endif
                @endif {{-- close $engine_subtab === 'info' / @else --}}
