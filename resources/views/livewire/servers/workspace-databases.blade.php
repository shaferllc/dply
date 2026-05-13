@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $localMysql = $server->serverDatabases->where('engine', 'mysql')->pluck('name')->all();
    $localPg = $server->serverDatabases->where('engine', 'postgres')->pluck('name')->all();
    $mysqlOnlyOnServer = array_values(array_diff($remote_mysql_databases, $localMysql));
    $pgOnlyOnServer = array_values(array_diff($remote_postgres_databases, $localPg));
    $engineLabels = ['mysql' => 'MySQL', 'postgres' => 'PostgreSQL', 'sqlite' => 'SQLite'];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="databases"
    :title="__('Databases')"
    :description="__('Create databases on this server, then reveal credentials and copy connection details for your apps.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4">
        <p>{{ __('This workspace manages relational databases on this server — MySQL, MariaDB, and PostgreSQL — and the per-app credentials that grant access to them. Engines are installed via apt + systemd; databases live inside whatever engines are running.') }}</p>
        <p>{{ __('Engine state is read live via SSH; database + credential rows live in the dply database. The "Discovered on server" panel reconciles both directions: databases the engine knows about that dply hasn\'t recorded yet.') }}</p>
    </x-explainer>

    @if ($opsReady)
        @php
            $engineWorking = collect($engineRows ?? [])->contains(fn ($row) => in_array($row->status, [
                \App\Models\ServerDatabaseEngine::STATUS_PENDING,
                \App\Models\ServerDatabaseEngine::STATUS_INSTALLING,
                \App\Models\ServerDatabaseEngine::STATUS_UNINSTALLING,
            ], true));
        @endphp
        @if ($engineWorking)
            {{-- Conditional poll: only fires while a queued engine job is in flight. The element
                 disappears the moment all rows settle, so polling stops on its own. --}}
            <div wire:poll.4s class="hidden" aria-hidden="true"></div>
        @endif

        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <x-server-workspace-tablist :aria-label="__('Database workspace sections')" class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab
                    id="db-tab-basics"
                    :active="$workspace_tab === 'databases'"
                    wire:click="setWorkspaceTab('databases')"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-circle-stack class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Basics') }}
                    </span>
                </x-server-workspace-tab>
                @foreach (['mysql', 'postgres', 'sqlite'] as $engine)
                    @php
                        $engineRow = $engineRows[$engine] ?? null;
                        $engineInstalledOrInflight = ($capabilities[$engine] ?? false)
                            || ($engineRow && in_array($engineRow->status, [
                                \App\Models\ServerDatabaseEngine::STATUS_PENDING,
                                \App\Models\ServerDatabaseEngine::STATUS_INSTALLING,
                                \App\Models\ServerDatabaseEngine::STATUS_RUNNING,
                                \App\Models\ServerDatabaseEngine::STATUS_FAILED,
                                \App\Models\ServerDatabaseEngine::STATUS_UNINSTALLING,
                            ], true));
                    @endphp
                    {{-- Engine tabs are always reachable now: even when an engine isn't installed
                         the operator clicks through to find the Install button. The badge tells
                         them at a glance whether anything's running on it. --}}
                    <x-server-workspace-tab
                        :id="'db-tab-'.$engine"
                        :active="$workspace_tab === $engine"
                        wire:click="setWorkspaceTab('{{ $engine }}')"
                    >
                        <span class="inline-flex items-center gap-2">
                            @if ($engine === 'sqlite')
                                <x-heroicon-o-archive-box class="h-4 w-4 shrink-0" aria-hidden="true" />
                            @else
                                <x-heroicon-o-circle-stack class="h-4 w-4 shrink-0" aria-hidden="true" />
                            @endif
                            {{ $engineLabels[$engine] ?? ucfirst($engine) }}
                            @if ($engineRow && in_array($engineRow->status, [
                                \App\Models\ServerDatabaseEngine::STATUS_PENDING,
                                \App\Models\ServerDatabaseEngine::STATUS_INSTALLING,
                                \App\Models\ServerDatabaseEngine::STATUS_UNINSTALLING,
                            ], true))
                                <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">
                                    <x-spinner variant="forest" />
                                    {{ __('Working') }}
                                </span>
                            @elseif ($engineRow && $engineRow->status === \App\Models\ServerDatabaseEngine::STATUS_FAILED)
                                <span class="inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">{{ __('Failed') }}</span>
                            @elseif ($capabilities[$engine] ?? false)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('Active') }}</span>
                            @endif
                        </span>
                    </x-server-workspace-tab>
                @endforeach
                <x-server-workspace-tab
                    id="db-tab-advanced"
                    :active="$workspace_tab === 'advanced'"
                    wire:click="setWorkspaceTab('advanced')"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Advanced') }}
                    </span>
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="db-tab-notifications"
                    :active="$workspace_tab === 'notifications'"
                    wire:click="setWorkspaceTab('notifications')"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-bell class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Notifications') }}
                    </span>
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:pb-0.5">
                <x-dropdown align="right" width="w-56" contentClasses="py-1.5">
                    <x-slot name="trigger">
                        <button
                            type="button"
                            aria-label="{{ __('Workspace actions') }}"
                            aria-haspopup="true"
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                        >
                            <span wire:loading.remove wire:target="refreshDatabaseCapabilities,synchronizeDatabases">{{ __('Actions') }}</span>
                            <span wire:loading wire:target="refreshDatabaseCapabilities,synchronizeDatabases" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" />
                                {{ __('Working…') }}
                            </span>
                            <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-ink/70" />
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <button
                            type="button"
                            wire:click="refreshDatabaseCapabilities"
                            wire:loading.attr="disabled"
                            wire:target="refreshDatabaseCapabilities"
                            title="{{ __('Re-run engine detection (cached for a few minutes)') }}"
                            class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="refreshDatabaseCapabilities">{{ __('Recheck engines') }}</span>
                            <span wire:loading wire:target="refreshDatabaseCapabilities">{{ __('Rechecking…') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="synchronizeDatabases"
                            wire:loading.attr="disabled"
                            wire:target="synchronizeDatabases"
                            class="block w-full px-4 py-2 text-left text-sm text-brand-ink hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="synchronizeDatabases">{{ __('Synchronize databases') }}</span>
                            <span wire:loading wire:target="synchronizeDatabases">{{ __('Scanning…') }}</span>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>

        <div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setWorkspaceTab">
            <div
                class="pointer-events-none absolute inset-x-0 top-0 z-10 hidden items-center justify-center pt-12"
                wire:loading.delay.shortest.flex
                wire:target="setWorkspaceTab"
                aria-live="polite"
            >
                <div class="dply-card flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-brand-ink shadow-lg">
                    <x-spinner variant="forest" />
                    <span>{{ __('Loading…') }}</span>
                </div>
            </div>

        <x-server-workspace-tab-panel
            id="db-panel-basics"
            labelled-by="db-tab-basics"
            :hidden="$workspace_tab !== 'databases'"
            panel-class="space-y-8"
        >
            @if ($generated_database_credentials)
                <div class="{{ $card }} p-6 sm:p-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('New database credentials') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Save these now. Dply generated credentials for :name and shows them here right after creation.', ['name' => $generated_database_credentials['name']]) }}
                            </p>
                        </div>
                        <button type="button" wire:click="dismissGeneratedDatabaseCredentials" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                            {{ __('Dismiss') }}
                        </button>
                    </div>
                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Database') }}</dt>
                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $generated_database_credentials['name'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</dt>
                            <dd class="mt-1 text-sm text-brand-ink">{{ $engineLabels[$generated_database_credentials['engine']] ?? ucfirst((string) $generated_database_credentials['engine']) }}</dd>
                        </div>
                        @if (filled($generated_database_credentials['username']))
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</dt>
                                <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $generated_database_credentials['username'] }}</dd>
                                @if ($generated_database_credentials['username_generated'])
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Generated for you.') }}</p>
                                @endif
                            </div>
                        @endif
                        @if (filled($generated_database_credentials['password']))
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</dt>
                                <dd class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $generated_database_credentials['password'] }}</dd>
                                @if ($generated_database_credentials['password_generated'])
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('Generated for you.') }}</p>
                                @endif
                            </div>
                        @endif
                        @if ($generated_database_credentials['engine'] === 'sqlite' && filled($generated_database_credentials['host'] ?? null))
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('File path') }}</dt>
                                <dd class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $generated_database_credentials['host'] }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            @php
                $anyEngine = ($capabilities['mysql'] ?? false) || ($capabilities['postgres'] ?? false) || ($capabilities['sqlite'] ?? false);
            @endphp
            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('New database') }}</h2>
                @if (! $anyEngine)
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('No database engine detected on this server. Install MySQL, PostgreSQL, or SQLite, then use Recheck engines on the Advanced tab.') }}
                    </p>
                @else
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Creates the database and a user on the server. Leave user and password empty to generate values automatically.') }}</p>
                <x-explainer class="mt-3">
                    <p>{{ __('Picks an engine, runs CREATE DATABASE, then creates a per-database user (defaulting to the same name) and grants it full access on that database only. The credentials are stored encrypted in the dply database — reveal + copy them from the Credentials column on each row.') }}</p>
                    <p>{{ __('Auto-generation: an empty user defaults to the database name. An empty password generates a 32-character symbol-free string. Both are good defaults for app-only use.') }}</p>
                </x-explainer>
                <form wire:submit="createDatabase" class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="new_db_name" value="{{ __('Name') }}" />
                        <x-text-input id="new_db_name" wire:model.live.debounce.250ms="new_db_name" class="mt-1 block w-full font-mono text-sm" wire:loading.attr="disabled" wire:target="createDatabase" required />
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Lowercase letters, digits, and underscores only. Spaces, dashes, and dots auto-convert to underscores; everything else is dropped. Max 64 characters.') }}
                        </p>
                        <x-input-error :messages="$errors->get('new_db_name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="new_db_engine" value="{{ __('Engine') }}" />
                        <select id="new_db_engine" wire:model.live="new_db_engine" wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:bg-brand-sand/40">
                            @if ($capabilities['mysql'] ?? false)
                                <option value="mysql">{{ __('MySQL / MariaDB') }}</option>
                            @endif
                            @if ($capabilities['postgres'] ?? false)
                                <option value="postgres">{{ __('PostgreSQL') }}</option>
                            @endif
                            @if ($capabilities['sqlite'] ?? false)
                                <option value="sqlite">{{ __('SQLite (file-based)') }}</option>
                            @endif
                        </select>
                        <x-input-error :messages="$errors->get('new_db_engine')" class="mt-1" />
                    </div>
                    @if ($new_db_engine !== 'sqlite')
                    <div>
                        <x-input-label for="new_db_user_mode" value="{{ __('Database user') }}" />
                        <select id="new_db_user_mode" wire:model.live="new_db_user_mode" @disabled($new_db_engine !== 'mysql') wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:bg-brand-sand/40">
                            <option value="new">{{ __('Create a new user') }}</option>
                            <option value="existing">{{ __('Use an existing MySQL user') }}</option>
                        </select>
                        @if ($new_db_engine !== 'mysql')
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Existing-user reuse is currently available for MySQL only.') }}</p>
                        @endif
                        <x-input-error :messages="$errors->get('new_db_user_mode')" class="mt-1" />
                    </div>
                    @if (! ($new_db_engine === 'mysql' && $new_db_user_mode === 'existing'))
                        <div>
                            <x-input-label for="new_db_username" value="{{ __('User (optional)') }}" />
                            <x-text-input id="new_db_username" wire:model="new_db_username" autocomplete="off" class="mt-1 block w-full font-mono text-sm" wire:loading.attr="disabled" wire:target="createDatabase" placeholder="{{ __('Auto-generated if empty') }}" />
                            <x-input-error :messages="$errors->get('new_db_username')" class="mt-1" />
                        </div>
                    @endif
                    @if ($new_db_engine === 'mysql' && $new_db_user_mode === 'existing')
                        <div class="sm:col-span-2">
                            <x-input-label for="new_db_existing_user_reference" value="{{ __('Existing MySQL user') }}" />
                            <select id="new_db_existing_user_reference" wire:model="new_db_existing_user_reference" wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                                <option value="">{{ __('Select an existing user…') }}</option>
                                @foreach ($existingMysqlUserOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Dply will create the database and grant this existing user access instead of creating another user.') }}</p>
                            <x-input-error :messages="$errors->get('new_db_existing_user_reference')" class="mt-1" />
                        </div>
                    @endif
                    @if (! ($new_db_engine === 'mysql' && $new_db_user_mode === 'existing'))
                        <div>
                            <div class="flex items-end justify-between gap-2">
                                <x-input-label for="new_db_password" value="{{ __('Password (optional)') }}" class="mb-0" />
                                <button type="button" wire:click="generateNewDbPassword" wire:loading.attr="disabled" wire:target="createDatabase,generateNewDbPassword" class="mb-1 text-xs font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                            </div>
                            <x-text-input id="new_db_password" type="password" wire:model="new_db_password" autocomplete="new-password" class="mt-1 block w-full text-sm" wire:loading.attr="disabled" wire:target="createDatabase" placeholder="••••••••" />
                            <x-input-error :messages="$errors->get('new_db_password')" class="mt-1" />
                        </div>
                    @endif
                    @else
                        @php
                            $sqliteRoot = rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');
                            $sqlitePreview = $sqliteRoot.'/'.$server->id.'/'.($new_db_name ?: 'database').'.db';
                        @endphp
                        <div class="sm:col-span-2 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                            <p class="font-medium text-brand-ink">{{ __('SQLite is file-based — no user or password needed.') }}</p>
                            <p class="mt-1">{{ __('Dply creates the file at a fixed location under :root and chowns it to the deploy user.', ['root' => $sqliteRoot]) }}</p>
                            <p class="mt-2 break-all font-mono text-xs text-brand-ink/80">{{ $sqlitePreview }}</p>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <x-input-label for="new_db_description" value="{{ __('Description (optional)') }}" />
                        <textarea
                            id="new_db_description"
                            wire:model="new_db_description"
                            rows="3"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:bg-brand-sand/40"
                            wire:loading.attr="disabled"
                            wire:target="createDatabase"
                        ></textarea>
                        <x-input-error :messages="$errors->get('new_db_description')" class="mt-1" />
                    </div>
                    @if ($new_db_engine === 'mysql')
                        <div class="sm:col-span-2">
                            <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 open:shadow-sm">
                                <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-brand-ink">{{ __('Advanced MySQL options') }}</summary>
                                <div class="grid gap-5 border-t border-brand-ink/10 px-4 py-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="new_mysql_charset" value="{{ __('MySQL charset (optional)') }}" />
                                        <x-text-input id="new_mysql_charset" wire:model="new_mysql_charset" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4" wire:loading.attr="disabled" wire:target="createDatabase" />
                                        <x-input-error :messages="$errors->get('new_mysql_charset')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="new_mysql_collation" value="{{ __('MySQL collation (optional)') }}" />
                                        <x-text-input id="new_mysql_collation" wire:model="new_mysql_collation" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4_unicode_ci" wire:loading.attr="disabled" wire:target="createDatabase" />
                                        <x-input-error :messages="$errors->get('new_mysql_collation')" class="mt-1" />
                                    </div>
                                </div>
                            </details>
                        </div>
                    @endif
                    <div class="sm:col-span-2 flex justify-end">
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createDatabase">
                            <span wire:loading.remove wire:target="createDatabase">{{ __('Add database') }}</span>
                            <span wire:loading wire:target="createDatabase">{{ __('Adding database…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
                @endif
            </div>

        </x-server-workspace-tab-panel>

        @foreach (['mysql', 'postgres', 'sqlite'] as $engine)
            <x-server-workspace-tab-panel
                :id="'db-panel-'.$engine"
                :labelled-by="'db-tab-'.$engine"
                :hidden="$workspace_tab !== $engine"
                panel-class="space-y-8"
            >
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
                            :active="$engine_subtab === 'overview'"
                            wire:click="setEngineSubtab('overview')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-heroicon-o-presentation-chart-line class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Overview') }}
                            </span>
                        </x-server-workspace-tab>
                        <x-server-workspace-tab
                            :id="'db-subtab-'.$engine.'-info'"
                            :active="$engine_subtab === 'info'"
                            wire:click="setEngineSubtab('info')"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-heroicon-o-information-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Info') }}
                            </span>
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
            </x-server-workspace-tab-panel>
        @endforeach

        <x-server-workspace-tab-panel
            id="db-panel-advanced"
            labelled-by="db-tab-advanced"
            :hidden="$workspace_tab !== 'advanced'"
            panel-class="space-y-8"
        >
            @if (! empty($remote_mysql_databases) || ! empty($remote_postgres_databases))
                <div class="{{ $card }} p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Discovered on server') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Names returned from the database engine. Import lets you attach credentials in Dply for databases that already exist on the host.') }}
                    </p>
                    <x-explainer class="mt-3">
                        <p>{{ __('Reads SHOW DATABASES (MySQL/MariaDB) and the equivalent for Postgres. The list is filtered against the dply records so only databases dply isn\'t already tracking show up here. Use this to adopt databases that were created outside the workspace (manually, by a backup restore, by another tool).') }}</p>
                        <p>{{ __('Importing creates a dply row with the database name and lets you set credentials; it doesn\'t change anything on the engine itself. Removing a row from dply doesn\'t drop the database — use Drop on the row to actually remove it from the engine.') }}</p>
                    </x-explainer>
                    @if (count($mysqlOnlyOnServer) > 0)
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('MySQL / MariaDB') }}</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($mysqlOnlyOnServer as $n)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-sm">
                                    <span class="font-mono text-brand-ink">{{ $n }}</span>
                                    <button
                                        type="button"
                                        wire:click="prefillDatabaseFromDiscovery(@js($n), 'mysql')"
                                        class="text-xs font-medium text-brand-forest hover:underline"
                                    >
                                        {{ __('Track in Dply') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (count($pgOnlyOnServer) > 0)
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('PostgreSQL') }}</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($pgOnlyOnServer as $n)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-sm">
                                    <span class="font-mono text-brand-ink">{{ $n }}</span>
                                    <button
                                        type="button"
                                        wire:click="prefillDatabaseFromDiscovery(@js($n), 'postgres')"
                                        class="text-xs font-medium text-brand-forest hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ __('Track in Dply') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (count($mysqlOnlyOnServer) === 0 && count($pgOnlyOnServer) === 0)
                        <p class="mt-4 text-sm text-brand-moss">{{ __('No extra database names on the server beyond what Dply already tracks.') }}</p>
                    @endif
                </div>
            @endif

            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Recent database workspace actions for this server.') }}</p>
                <x-explainer class="mt-3">
                    <p>{{ __('Every workspace action — engine install/uninstall, database create/drop, credential set/clear, SQL run, share-link created/revoked — writes a row here. Events are also forwarded to the organization-wide audit log when a signed-in user is the actor.') }}</p>
                    <p>{{ __('Event names are stable identifiers (e.g. database_dropped) so they\'re grep-able from the org log. SQL statements typed in the runner are NOT recorded in full — only the verb (SELECT, INSERT, …) so credentials and key contents stay out of the audit log.') }}</p>
                </x-explainer>
                <ul class="mt-6 divide-y divide-brand-ink/10 text-sm">
                    @forelse ($server->databaseAuditEvents as $ev)
                        <li class="py-3">
                            <span class="font-medium text-brand-ink">{{ $ev->event }}</span>
                            <span class="text-brand-mist"> · </span>
                            <span class="text-brand-moss">{{ $ev->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                            @if ($ev->user)
                                <span class="text-brand-mist"> · </span>
                                <span class="text-brand-moss">{{ $ev->user->name }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="py-4 text-brand-moss">{{ __('No events yet.') }}</li>
                    @endforelse
                </ul>
            </div>

        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="db-panel-notifications"
            labelled-by="db-tab-notifications"
            :hidden="$workspace_tab !== 'notifications'"
            panel-class="space-y-8"
        >
            @livewire(\App\Livewire\Notifications\ResourceSummary::class, [
                'resource' => $server,
                'heading' => __('Database and server notifications'),
                'manageUrl' => route('profile.notification-channels.bulk-assign', ['server' => $server->id]),
            ], key('resource-summary-databases-'.$server->id))
        </x-server-workspace-tab-panel>
        </div>
    @else
        <div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
            {{ __('Provisioning and SSH must be ready before you can use this section.') }}
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @if ($credentialsModalDatabase)
            <div
                class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                role="dialog"
                aria-modal="true"
                aria-labelledby="db-credentials-title"
                wire:key="db-cred-{{ $credentialsModalDatabase->id }}"
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeCredentialsModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div class="my-auto w-full max-w-lg dply-modal-panel" @click.stop>
                        <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                            <h2 id="db-credentials-title" class="text-lg font-semibold text-brand-ink">{{ __('Database credentials') }}</h2>
                            <p class="mt-1 font-mono text-sm text-brand-moss">{{ $credentialsModalDatabase->name }}</p>
                        </div>
                        <div class="space-y-4 px-6 py-6 sm:px-8">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</p>
                                <p class="mt-1 font-mono text-sm text-brand-ink">{{ $credentialsModalDatabase->username }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</p>
                                <p class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $credentialsModalDatabase->password }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host & port') }}</p>
                                <p class="mt-1 font-mono text-sm text-brand-ink">{{ $credentialsModalDatabase->host ?: '127.0.0.1' }}:{{ $credentialsModalDatabase->defaultPort() }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connection URL') }}</p>
                                <p class="mt-1 break-all font-mono text-xs leading-relaxed text-brand-moss">{{ $credentialsModalDatabase->connectionUrl() }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 pt-2">
                                <button
                                    type="button"
                                    x-data="{ ok: false }"
                                    @click="navigator.clipboard.writeText(@js($credentialsModalDatabase->connectionUrl())); ok = true; setTimeout(() => ok = false, 2000)"
                                    class="inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50"
                                >
                                    <span x-show="!ok">{{ __('Copy connection URL') }}</span>
                                    <span x-show="ok" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                                </button>
                                <x-secondary-button type="button" wire:click="closeCredentialsModal">{{ __('Close') }}</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($share_link_modal_url)
            <div
                class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                role="dialog"
                aria-modal="true"
                aria-labelledby="db-share-link-title"
                wire:key="db-share-link"
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeShareLinkModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div class="my-auto w-full max-w-lg dply-modal-panel" @click.stop>
                        <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                            <h2 id="db-share-link-title" class="text-lg font-semibold text-brand-ink">{{ __('Share link created') }}</h2>
                            @if ($share_link_modal_db_name)
                                <p class="mt-1 font-mono text-sm text-brand-moss">{{ $share_link_modal_db_name }}</p>
                            @endif
                        </div>
                        <div class="space-y-4 px-6 py-6 sm:px-8">
                            <p class="text-sm text-brand-moss leading-relaxed">
                                {{ __('Send this link to whoever needs the credentials. It honours the expiry and view limits you set.') }}
                            </p>
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <p class="break-all font-mono text-xs leading-relaxed text-brand-ink">{{ $share_link_modal_url }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 pt-2">
                                <button
                                    type="button"
                                    x-data="{ ok: false }"
                                    @click="navigator.clipboard.writeText(@js($share_link_modal_url)); ok = true; setTimeout(() => ok = false, 2000)"
                                    class="inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50"
                                >
                                    <span x-show="!ok">{{ __('Copy link') }}</span>
                                    <span x-show="ok" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                                </button>
                                <x-secondary-button type="button" wire:click="closeShareLinkModal">{{ __('Close') }}</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($connectionUrlModalDatabase)
            <div
                class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                role="dialog"
                aria-modal="true"
                aria-labelledby="db-connection-url-title"
                wire:key="db-conn-url-{{ $connectionUrlModalDatabase->id }}"
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeConnectionUrlModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div class="my-auto w-full max-w-lg dply-modal-panel" @click.stop>
                        <div class="border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                            <h2 id="db-connection-url-title" class="text-lg font-semibold text-brand-ink">{{ __('Connection URL') }}</h2>
                            <p class="mt-1 font-mono text-sm text-brand-moss">{{ $connectionUrlModalDatabase->name }}</p>
                        </div>
                        <div class="space-y-4 px-6 py-6 sm:px-8">
                            <p class="break-all font-mono text-sm leading-relaxed text-brand-ink">{{ $connectionUrlModalDatabase->connectionUrl() }}</p>
                            <div class="flex flex-wrap gap-2 pt-2">
                                <button
                                    type="button"
                                    x-data="{ ok: false }"
                                    @click="navigator.clipboard.writeText(@js($connectionUrlModalDatabase->connectionUrl())); ok = true; setTimeout(() => ok = false, 2000)"
                                    class="inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50"
                                >
                                    <span x-show="!ok">{{ __('Copy') }}</span>
                                    <span x-show="ok" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                                </button>
                                <x-secondary-button type="button" wire:click="closeConnectionUrlModal">{{ __('Close') }}</x-secondary-button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($editing_db_id)
            <x-modal
                name="edit-database-modal"
                :show="false"
                maxWidth="lg"
                overlayClass="bg-brand-ink/40"
                focusable
            >
                <form wire:submit="saveDatabaseEdit">
                    <div class="border-b border-brand-ink/10 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Edit database') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $editing_db_name }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            @switch($editing_db_engine)
                                @case('sqlite')
                                    {{ __('SQLite — file-based. Changing the path moves the file on the server.') }}
                                    @break
                                @case('postgres')
                                    {{ __('PostgreSQL — the on-host database keeps its existing settings; this updates Dply\'s description metadata.') }}
                                    @break
                                @default
                                    {{ __('MySQL/MariaDB — charset/collation here apply to the next create. The existing on-host database is unchanged.') }}
                            @endswitch
                        </p>
                    </div>

                    <div class="space-y-5 px-6 py-6">
                        <div>
                            <x-input-label for="edit-description" :value="__('Description')" />
                            <textarea
                                id="edit-description"
                                wire:model="edit_description"
                                rows="3"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                placeholder="{{ __('Optional notes about this database.') }}"
                            ></textarea>
                            <x-input-error :messages="$errors->get('edit_description')" class="mt-1" />
                        </div>

                        @if ($editing_db_engine === 'mysql')
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="edit-mysql-charset" :value="__('MySQL charset')" />
                                    <x-text-input id="edit-mysql-charset" wire:model="edit_mysql_charset" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4" />
                                    <x-input-error :messages="$errors->get('edit_mysql_charset')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="edit-mysql-collation" :value="__('MySQL collation')" />
                                    <x-text-input id="edit-mysql-collation" wire:model="edit_mysql_collation" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4_unicode_ci" />
                                    <x-input-error :messages="$errors->get('edit_mysql_collation')" class="mt-1" />
                                </div>
                            </div>
                        @endif

                        @if ($editing_db_engine === 'sqlite')
                            <div>
                                <x-input-label for="edit-sqlite-path" :value="__('File path on the server')" />
                                <x-text-input
                                    id="edit-sqlite-path"
                                    wire:model="edit_sqlite_path"
                                    class="mt-1 block w-full font-mono text-sm"
                                    placeholder="{{ rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/').'/'.$editing_db_name.'.db' }}"
                                />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Must sit under :root. Saving moves the file on the server.', ['root' => config('server_database.sqlite_root', '/var/lib/dply/sqlite')]) }}</p>
                                <x-input-error :messages="$errors->get('edit_sqlite_path')" class="mt-1" />
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeEditDatabaseModal">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveDatabaseEdit">
                            <span wire:loading.remove wire:target="saveDatabaseEdit">{{ __('Save changes') }}</span>
                            <span wire:loading wire:target="saveDatabaseEdit" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" />
                                {{ __('Saving…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endif

        @if ($sqlite_console_db_id)
            <x-modal
                name="sqlite-sql-console-modal"
                :show="false"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/40"
                focusable
            >
                <form wire:submit="runSqliteSql">
                    <div class="border-b border-brand-ink/10 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('SQLite SQL console') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Run SQL against this database') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Paste a SQL statement (or batch). Output streams back from sqlite3 on the server.') }}</p>
                        <x-explainer class="mt-3" tone="warn">
                            <p>{{ __('SQL runs as the engine\'s own DB user via SSH — this is full read + write + DDL access. There\'s no row-level safety net; SELECT and DROP are equally easy to type.') }}</p>
                            <p>{{ __('The audit log records the verb (SELECT/INSERT/UPDATE/DELETE/DROP/etc.) but not the body of the query, so passwords and key contents never get logged. Output streams back as the engine emits it; there\'s no truncation client-side.') }}</p>
                        </x-explainer>
                    </div>

                    <div class="space-y-4 px-6 py-6">
                        <div>
                            <x-input-label for="sqlite-console-sql" :value="__('SQL')" />
                            <textarea
                                id="sqlite-console-sql"
                                wire:model="sqlite_console_sql"
                                rows="8"
                                spellcheck="false"
                                class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                                placeholder="CREATE TABLE foo (id INTEGER PRIMARY KEY, name TEXT);"
                            ></textarea>
                            <x-input-error :messages="$errors->get('sqlite_console_sql')" class="mt-1" />
                        </div>

                        @if ($sqlite_console_output !== '' || $sqlite_console_exit_code !== null)
                            <div>
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Output') }}</p>
                                    @if ($sqlite_console_exit_code !== null)
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $sqlite_console_exit_code === 0,
                                            'bg-red-50 text-red-800 ring-red-200' => $sqlite_console_exit_code !== 0,
                                        ])>
                                            {{ $sqlite_console_exit_code === 0 ? __('OK') : __('Error') }}
                                        </span>
                                    @endif
                                </div>
                                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $sqlite_console_output ?: __('No output.') }}</pre>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeSqliteConsoleModal">{{ __('Close') }}</x-secondary-button>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="runSqliteSql">
                            <span wire:loading.remove wire:target="runSqliteSql">{{ __('Run query') }}</span>
                            <span wire:loading wire:target="runSqliteSql" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" />
                                {{ __('Running…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endif

        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
