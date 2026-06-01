<x-server-workspace-layout
    :server="$server"
    active="databases"
    :title="__('Databases')"
    :description="__('Create databases on this server, then reveal credentials and copy connection details for your apps.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($manageRemoteTaskId)
        {{-- Polls the cache row written by ServerManageRemoteSshJob so the success
             toast for Show processlist fires when the queued task finishes. The
             ConsoleAction banner inside the MySQL → Info subtab handles the output
             stream independently via its own wire:poll. --}}
        <div wire:poll.2s="syncManageRemoteTaskFromCache" class="hidden" aria-hidden="true"></div>
    @endif

    <x-explainer>
        <p>{{ __('This workspace manages databases on this server — MySQL, MariaDB, PostgreSQL, MongoDB, ClickHouse, and SQLite — plus per-app credentials. Engines install via apt + systemd (or file-based SQLite). For Redis/Valkey caching, use the Caches workspace.') }}</p>
        <p>{{ __('Engine state is read live via SSH; database + credential rows live in the dply database. The "Discovered on server" panel reconciles both directions: databases the engine knows about that dply hasn\'t recorded yet.') }}</p>
    </x-explainer>

    @if ($opsReady)
        @if ($databaseConsoleBannerRun)
            <div class="mb-4">
                @include('livewire.partials.console-action-banner-static', [
                    'run' => $databaseConsoleBannerRun,
                    'kindLabels' => (array) config('console_actions.kinds', []),
                ])
            </div>
        @endif

        <div class="min-w-0">
            <x-server-workspace-tablist :aria-label="__('Database workspace sections')" scroll class="w-full">
                <x-server-workspace-tab
                    id="db-tab-basics"
                    icon="heroicon-o-circle-stack"
                    :active="$workspace_tab === 'databases'"
                    wire:click="setWorkspaceTab('databases')"
                >
                    {{ __('Basics') }}
                </x-server-workspace-tab>
                @foreach ($engines as $engine)
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
                    <x-server-workspace-tab
                        :id="'db-tab-'.$engine"
                        :active="$workspace_tab === $engine"
                        wire:click="setWorkspaceTab('{{ $engine }}')"
                    >
                        <span class="inline-flex items-center gap-2">
                            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center" wire:loading.remove wire:target="setWorkspaceTab('{{ $engine }}')">
                                @if ($engine === 'sqlite')
                                    <x-heroicon-o-archive-box class="h-4 w-4 shrink-0" aria-hidden="true" />
                                @else
                                    <x-heroicon-o-circle-stack class="h-4 w-4 shrink-0" aria-hidden="true" />
                                @endif
                            </span>
                            <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center" wire:loading wire:target="setWorkspaceTab('{{ $engine }}')">
                                <x-spinner class="h-4 w-4" />
                            </span>
                            {{ $engineLabels[$engine] ?? ucfirst($engine) }}
                            @if (($comingSoonEngines[$engine] ?? false) && ! ($capabilities[$engine] ?? false) && ! $engineRow)
                                <span class="inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                            @elseif ($engineRow && in_array($engineRow->status, [
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
                    icon="heroicon-o-wrench-screwdriver"
                    :active="$workspace_tab === 'advanced'"
                    wire:click="setWorkspaceTab('advanced')"
                >
                    {{ __('Advanced') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="db-tab-notifications"
                    icon="heroicon-o-bell"
                    :active="$workspace_tab === 'notifications'"
                    wire:click="setWorkspaceTab('notifications')"
                >
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <x-workspace-tab-panel-loading>
            @if ($workspace_tab === 'databases')
                <x-server-workspace-tab-panel
                    id="db-panel-basics"
                    labelled-by="db-tab-basics"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.databases.overview-tab')
                </x-server-workspace-tab-panel>
            @endif

            @foreach ($engines as $engine)
                @if ($workspace_tab === $engine)
                    <x-server-workspace-tab-panel
                        :id="'db-panel-'.$engine"
                        :labelled-by="'db-tab-'.$engine"
                        panel-class="space-y-8"
                    >
                        @include('livewire.servers.partials.databases.engine-panel', compact('engine'))
                    </x-server-workspace-tab-panel>
                @endif
            @endforeach

            @if ($workspace_tab === 'advanced')
                <x-server-workspace-tab-panel
                    id="db-panel-advanced"
                    labelled-by="db-tab-advanced"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.databases.advanced-tab')
                </x-server-workspace-tab-panel>
            @endif

            @if ($workspace_tab === 'notifications')
                <x-server-workspace-tab-panel
                    id="db-panel-notifications"
                    labelled-by="db-tab-notifications"
                    panel-class="space-y-8"
                >
                    @include('livewire.servers.partials.databases.notifications-tab')
                </x-server-workspace-tab-panel>
            @endif
        </x-workspace-tab-panel-loading>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
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
                <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeCredentialsModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div class="my-auto flex w-full max-w-2xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Credentials') }}</p>
                                <h2 id="db-credentials-title" class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Database credentials') }}</h2>
                                <p class="mt-1 font-mono text-sm text-brand-moss">{{ $credentialsModalDatabase->name }}</p>
                            </div>
                        </div>
                        <div class="space-y-4 px-6 py-6">
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</p>
                                <p class="mt-0.5 font-mono text-sm text-brand-ink">{{ $credentialsModalDatabase->username }}</p>
                            </div>
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</p>
                                <p class="mt-0.5 break-all font-mono text-sm text-brand-ink">{{ $credentialsModalDatabase->password }}</p>
                            </div>
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host & port') }}</p>
                                <p class="mt-0.5 font-mono text-sm text-brand-ink">{{ $credentialsModalDatabase->host ?: '127.0.0.1' }}:{{ $credentialsModalDatabase->defaultPort() }}</p>
                            </div>
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connection URL') }}</p>
                                <p class="mt-0.5 break-all font-mono text-xs leading-relaxed text-brand-moss">{{ $credentialsModalDatabase->connectionUrl() }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                            <button
                                type="button"
                                x-data="{ ok: false }"
                                @click="navigator.clipboard.writeText(@js($credentialsModalDatabase->connectionUrl())); ok = true; setTimeout(() => ok = false, 2000)"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-m-clipboard-document class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span x-show="!ok">{{ __('Copy connection URL') }}</span>
                                <span x-show="ok" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                            </button>
                            <x-secondary-button type="button" wire:click="closeCredentialsModal">{{ __('Close') }}</x-secondary-button>
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
                <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeShareLinkModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div class="my-auto flex w-full max-w-2xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Share') }}</p>
                                <h2 id="db-share-link-title" class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Share link created') }}</h2>
                                @if ($share_link_modal_db_name)
                                    <p class="mt-1 font-mono text-sm text-brand-moss">{{ $share_link_modal_db_name }}</p>
                                @endif
                                <p class="mt-1 text-sm leading-6 text-brand-moss">
                                    {{ __('Send this link to whoever needs the credentials. It honours the expiry and view limits you set.') }}
                                </p>
                            </div>
                        </div>
                        <div class="px-6 py-6">
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Link') }}</p>
                                <p class="mt-1 break-all font-mono text-xs leading-relaxed text-brand-ink">{{ $share_link_modal_url }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                            <button
                                type="button"
                                x-data="{ ok: false }"
                                @click="navigator.clipboard.writeText(@js($share_link_modal_url)); ok = true; setTimeout(() => ok = false, 2000)"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-m-clipboard-document class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span x-show="!ok">{{ __('Copy link') }}</span>
                                <span x-show="ok" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                            </button>
                            <x-secondary-button type="button" wire:click="closeShareLinkModal">{{ __('Close') }}</x-secondary-button>
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
                <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeConnectionUrlModal"></div>
                <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
                    <div class="my-auto flex w-full max-w-2xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Connection') }}</p>
                                <h2 id="db-connection-url-title" class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Connection URL') }}</h2>
                                <p class="mt-1 font-mono text-sm text-brand-moss">{{ $connectionUrlModalDatabase->name }}</p>
                            </div>
                        </div>
                        <div class="px-6 py-6">
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/30 px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('URL') }}</p>
                                <p class="mt-1 break-all font-mono text-sm leading-relaxed text-brand-ink">{{ $connectionUrlModalDatabase->connectionUrl() }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                            <button
                                type="button"
                                x-data="{ ok: false }"
                                @click="navigator.clipboard.writeText(@js($connectionUrlModalDatabase->connectionUrl())); ok = true; setTimeout(() => ok = false, 2000)"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-m-clipboard-document class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span x-show="!ok">{{ __('Copy') }}</span>
                                <span x-show="ok" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                            </button>
                            <x-secondary-button type="button" wire:click="closeConnectionUrlModal">{{ __('Close') }}</x-secondary-button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if ($editing_db_id)
            <x-modal
                name="edit-database-modal"
                :show="false"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit="saveDatabaseEdit" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-pencil-square class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Edit') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $editing_db_name }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">
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
                    </div>

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
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

                        @if (\App\Support\Servers\DatabaseWorkspaceEngines::isMysqlFamily($editing_db_engine))
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

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeEditDatabaseModal">{{ __('Cancel') }}</x-secondary-button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="saveDatabaseEdit"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="saveDatabaseEdit" class="inline-flex items-center gap-2">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Save changes') }}
                            </span>
                            <span wire:loading wire:target="saveDatabaseEdit" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif

        @if ($sqlite_console_db_id)
            <x-modal
                name="sqlite-sql-console-modal"
                :show="false"
                maxWidth="3xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit="runSqliteSql" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('SQL console') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Run SQL against this database') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Paste a SQL statement (or batch). Output streams back from sqlite3 on the server.') }}</p>
                            <x-explainer class="mt-3" tone="warn">
                                <p>{{ __('SQL runs as the engine\'s own DB user via SSH — this is full read + write + DDL access. There\'s no row-level safety net; SELECT and DROP are equally easy to type.') }}</p>
                                <p>{{ __('The audit log records the verb (SELECT/INSERT/UPDATE/DELETE/DROP/etc.) but not the body of the query, so passwords and key contents never get logged. Output streams back as the engine emits it; there\'s no truncation client-side.') }}</p>
                            </x-explainer>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-6">
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

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeSqliteConsoleModal">{{ __('Close') }}</x-secondary-button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="runSqliteSql"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="runSqliteSql" class="inline-flex items-center gap-2">
                                <x-heroicon-o-play class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Run query') }}
                            </span>
                            <span wire:loading wire:target="runSqliteSql" class="inline-flex items-center gap-2 whitespace-nowrap">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Running…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif

        {{-- Credentials modal — opens automatically when a database is created --}}
        <x-modal name="database-credentials-modal" max-width="xl" focusable>
            @if ($generated_database_credentials)
                @php
                    $creds = $generated_database_credentials;
                    $credPlainPw = $creds['password'] ?? null;
                    $credPwHidden = (bool) ($creds['password_hidden'] ?? false);
                    $credEmailed = (bool) ($creds['credentials_emailed'] ?? false);
                    $credShowPw = filled($credPlainPw) && ! $credPwHidden;
                    $credPort = (int) ($creds['port'] ?? 5432);
                    $credUser = $creds['username'] ?? '';
                    $credDb = $creds['name'] ?? '';
                    $credEngine = $creds['engine'] ?? 'postgres';
                    $credPrivateIp = $creds['server_private_ip'] ?? null;
                    $credPublicIp = $creds['server_public_ip'] ?? null;
                    $credRemoteAccess = (bool) ($creds['remote_access'] ?? false);

                    // Build connection URLs for local and remote.
                    $userEnc = rawurlencode($credUser);
                    $passEnc = $credShowPw ? rawurlencode((string) $credPlainPw) : '●●●●●●●●';
                    $localConnStr = match ($credEngine) {
                        'postgres' => "postgresql://{$userEnc}:{$passEnc}@127.0.0.1:{$credPort}/{$credDb}",
                        'mongodb' => "mongodb://{$userEnc}:{$passEnc}@127.0.0.1:{$credPort}/{$credDb}?authSource={$credDb}",
                        'clickhouse' => "clickhouse://{$userEnc}:{$passEnc}@127.0.0.1:{$credPort}/{$credDb}",
                        default => "mysql://{$userEnc}:{$passEnc}@127.0.0.1:{$credPort}/{$credDb}",
                    };
                    $remoteHost = $credPrivateIp ?? $credPublicIp;
                    $remoteConnStr = $remoteHost ? match ($credEngine) {
                        'postgres' => "postgresql://{$userEnc}:{$passEnc}@{$remoteHost}:{$credPort}/{$credDb}",
                        'mongodb' => "mongodb://{$userEnc}:{$passEnc}@{$remoteHost}:{$credPort}/{$credDb}?authSource={$credDb}",
                        'clickhouse' => "clickhouse://{$userEnc}:{$passEnc}@{$remoteHost}:{$credPort}/{$credDb}",
                        default => "mysql://{$userEnc}:{$passEnc}@{$remoteHost}:{$credPort}/{$credDb}",
                    } : null;

                    // TablePlus deep link — uses the connection URL scheme.
                    // Requires TablePlus 4.0+ on macOS/Windows.
                    $tpDriver = match ($credEngine) {
                        'postgres' => 'PostgreSQL',
                        'mongodb' => 'MongoDB',
                        'clickhouse' => 'ClickHouse',
                        'mariadb' => 'MariaDB',
                        default => 'MySQL',
                    };
                    $tpLocalUrl = 'tableplus://open?driver='.$tpDriver
                        .'&name='.rawurlencode($server->name.' / '.$credDb.' (local)')
                        .'&host=127.0.0.1&port='.$credPort
                        .'&user='.rawurlencode($credUser)
                        .($credShowPw ? '&password='.rawurlencode((string) $credPlainPw) : '')
                        .'&database='.rawurlencode($credDb);
                    $tpRemoteUrl = $remoteHost && $credRemoteAccess
                        ? 'tableplus://open?driver='.$tpDriver
                            .'&name='.rawurlencode($server->name.' / '.$credDb.' (remote)')
                            .'&host='.rawurlencode($remoteHost).'&port='.$credPort
                            .'&user='.rawurlencode($credUser)
                            .($credShowPw ? '&password='.rawurlencode((string) $credPlainPw) : '')
                            .'&database='.rawurlencode($credDb)
                        : null;
                @endphp
                <div
                    class="bg-white"
                    x-data="{
                        copiedKey: null,
                        async copy(text, key) {
                            try {
                                await navigator.clipboard.writeText(text);
                                this.copiedKey = key;
                                setTimeout(() => this.copiedKey = null, 1800);
                            } catch (e) {}
                        },
                    }"
                >
                    {{-- Header --}}
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Database created') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':name credentials', ['name' => $credDb]) }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                @if ($credEmailed)
                                    {{ __('Emailed to you. Copy the password now — it hides when you close this.') }}
                                @else
                                    {{ __('Copy the password now — it hides when you close this.') }}
                                @endif
                            </p>
                        </div>
                        <button type="button" x-on:click="$dispatch('close-modal', 'database-credentials-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                            <x-heroicon-o-x-mark class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="divide-y divide-brand-ink/5 overflow-y-auto" style="max-height: 70vh">
                        {{-- Core credentials --}}
                        <div class="grid gap-3 p-6 sm:grid-cols-2">
                            @if (filled($credUser))
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                                    <div class="flex items-center justify-between">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</dt>
                                        <button type="button" class="text-[11px] font-medium text-brand-sage hover:underline" @click="copy(@js($credUser), 'user')">
                                            <span x-show="copiedKey !== 'user'">{{ __('Copy') }}</span>
                                            <span x-show="copiedKey === 'user'" x-cloak>{{ __('Copied!') }}</span>
                                        </button>
                                    </div>
                                    <dd class="mt-0.5 break-all font-mono text-sm font-semibold text-brand-ink">{{ $credUser }}</dd>
                                </div>
                            @endif
                            @if (filled($credPlainPw) || $credPwHidden)
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
                                    <div class="flex items-center justify-between">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</dt>
                                        @if ($credShowPw)
                                            <button type="button" class="text-[11px] font-medium text-brand-sage hover:underline" @click="copy(@js($credPlainPw), 'pw')">
                                                <span x-show="copiedKey !== 'pw'">{{ __('Copy') }}</span>
                                                <span x-show="copiedKey === 'pw'" x-cloak>{{ __('Copied!') }}</span>
                                            </button>
                                        @endif
                                    </div>
                                    @if ($credShowPw)
                                        <dd class="mt-0.5 break-all font-mono text-sm font-semibold text-brand-ink">{{ $credPlainPw }}</dd>
                                    @else
                                        <dd class="mt-0.5 font-mono text-sm tracking-widest text-brand-mist">••••••••••••••••</dd>
                                        <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Retrieve from Credentials on the database row.') }}</p>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Local connection --}}
                        @if ($credEngine !== 'sqlite')
                            <div class="px-6 py-5">
                                <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Local connection') }} <span class="ml-1 font-normal normal-case text-brand-mist">{{ __('— for apps running on this server') }}</span></p>
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host & Port') }}</p>
                                            <code class="font-mono text-sm font-semibold text-brand-ink">127.0.0.1:{{ $credPort }}</code>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connection URL') }}</p>
                                            <code class="break-all font-mono text-xs text-brand-ink">{{ $localConnStr }}</code>
                                        </div>
                                        <button type="button" class="shrink-0 text-[11px] font-medium text-brand-sage hover:underline" @click="copy(@js($localConnStr), 'local_url')">
                                            <span x-show="copiedKey !== 'local_url'">{{ __('Copy') }}</span>
                                            <span x-show="copiedKey === 'local_url'" x-cloak>{{ __('Copied!') }}</span>
                                        </button>
                                    </div>
                                    <a href="{{ $tpLocalUrl }}" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-table-cells class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Open in TablePlus') }}
                                    </a>
                                </div>
                            </div>
                        @endif

                        {{-- Remote connection --}}
                        @if ($remoteConnStr)
                            <div class="px-6 py-5">
                                <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">
                                    {{ __('Remote connection') }}
                                    @if ($credPrivateIp)
                                        <span class="ml-1 font-normal normal-case text-emerald-600">{{ __('— via private network') }}</span>
                                    @else
                                        <span class="ml-1 font-normal normal-case text-amber-700">{{ __('— via public IP') }}</span>
                                    @endif
                                </p>
                                @if (! $credRemoteAccess)
                                    <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        {{ __('Remote access is not yet enabled for this database. Go to Networking → enable remote access first.') }}
                                    </p>
                                @endif
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Host & Port') }}</p>
                                            <code class="font-mono text-sm font-semibold text-brand-ink">{{ $remoteHost }}:{{ $credPort }}</code>
                                        </div>
                                        <button type="button" class="shrink-0 text-[11px] font-medium text-brand-sage hover:underline" @click="copy(@js($remoteHost.':'.$credPort), 'remote_host')">
                                            <span x-show="copiedKey !== 'remote_host'">{{ __('Copy') }}</span>
                                            <span x-show="copiedKey === 'remote_host'" x-cloak>{{ __('Copied!') }}</span>
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connection URL') }}</p>
                                            <code class="break-all font-mono text-xs text-brand-ink">{{ $remoteConnStr }}</code>
                                        </div>
                                        <button type="button" class="shrink-0 text-[11px] font-medium text-brand-sage hover:underline" @click="copy(@js($remoteConnStr), 'remote_url')">
                                            <span x-show="copiedKey !== 'remote_url'">{{ __('Copy') }}</span>
                                            <span x-show="copiedKey === 'remote_url'" x-cloak>{{ __('Copied!') }}</span>
                                        </button>
                                    </div>
                                    @if ($tpRemoteUrl)
                                        <a href="{{ $tpRemoteUrl }}" class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-table-cells class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Open in TablePlus (remote)') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @elseif ($credEngine !== 'sqlite')
                            <div class="px-6 py-4">
                                <p class="text-xs text-brand-mist">
                                    {{ __('No remote connection available — attach this server to a private network (Networking workspace) to get a private IP for external access.') }}
                                </p>
                            </div>
                        @endif

                        {{-- SQLite path --}}
                        @if ($credEngine === 'sqlite' && filled($creds['host'] ?? null))
                            <div class="px-6 py-5">
                                <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('File path') }}</p>
                                        <code class="break-all font-mono text-sm font-semibold text-brand-ink">{{ $creds['host'] }}</code>
                                    </div>
                                    <button type="button" class="shrink-0 text-[11px] font-medium text-brand-sage hover:underline" @click="copy(@js($creds['host']), 'sqlite_path')">
                                        <span x-show="copiedKey !== 'sqlite_path'">{{ __('Copy') }}</span>
                                        <span x-show="copiedKey === 'sqlite_path'" x-cloak>{{ __('Copied!') }}</span>
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-between border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                        <p class="text-[11px] text-brand-mist">{{ __('Password hides when you close this dialog.') }}</p>
                        <button
                            type="button"
                            x-on:click="$dispatch('close-modal', 'database-credentials-modal')"
                            wire:click="hideGeneratedDatabasePassword"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                        >
                            {{ __('Done') }}
                        </button>
                    </div>
                </div>
            @endif
        </x-modal>

        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
