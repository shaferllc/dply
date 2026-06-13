@php
    $card = 'dply-card overflow-hidden';
    $labelCls = 'block text-xs font-semibold uppercase tracking-wide text-brand-moss mb-1';
    $inputCls = 'block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest';

    $runtimeMode = $site->runtimeTargetMode();
    $runtimeTarget = $site->runtimeTarget();
    $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
    $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
    $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
    $settingsSidebarItems = \App\Support\SiteSettingsSidebar::items($site, $server);
    $section = 'database';
    $routingTab = 'domains';
    $laravel_tab = 'commands';

    $installedEngines = $this->installedEngines;
    $linked = $this->linkedDatabases;
    $linkable = $this->linkableDatabases;
    $isMysqlFamily = \App\Support\Servers\DatabaseWorkspaceEngines::isMysqlFamily($new_db_engine);
    $isSqlite = \App\Support\Servers\DatabaseWorkspaceEngines::family($new_db_engine) === 'sqlite';
@endphp

<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Database'),
        'currentIcon' => 'circle-stack',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-hero-card
                :eyebrow="__('Site')"
                :title="__('Database')"
                :description="__('Create a database for this site and wire it into the .env, attach one already on this server, manage users, rotate the password, back it up, or drop it.')"
                icon="circle-stack"
            />

            @if ($watchedConsoleRunId)
                <div wire:poll.3s="resolveWatchedConsoleAction" class="hidden" aria-hidden="true"></div>
            @endif

            @if ($consoleRun)
                <div
                    id="site-console-action-banner"
                    x-data="{}"
                    x-on:dply-console-action-focus.window="$nextTick(() => document.getElementById('site-console-action-banner')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                >
                    @include('livewire.partials.console-action-banner-static', [
                        'run' => $consoleRun,
                        'kindLabels' => (array) config('console_actions.kinds', []),
                    ])
                </div>
            @endif

            <x-server-workspace-tablist :aria-label="__('Database sections')" scroll class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab id="db-tab-databases" icon="heroicon-o-circle-stack" :active="$dbTab === 'databases'" wire:click="setDatabaseTab('databases')">
                    {{ __('Databases') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="db-tab-create" icon="heroicon-o-plus-circle" :active="$dbTab === 'create'" wire:click="setDatabaseTab('create')">
                    {{ __('Create') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab id="db-tab-notifications" icon="heroicon-o-bell" :active="$dbTab === 'notifications'" wire:click="setDatabaseTab('notifications')">
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>

            <div wire:key="db-panel-{{ $dbTab }}" class="space-y-6">
            @if ($dbTab === 'notifications')
                @include('livewire.sites.partials.database.notifications-tab')
            @elseif (empty($installedEngines))
                {{-- No database engine on the server yet — point at the server-level
                     manager, which owns the (heavy) install flow. --}}
                <div class="{{ $card }} p-6 text-center">
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand-sand/60">
                        <x-heroicon-o-circle-stack class="h-6 w-6 text-brand-moss" />
                    </div>
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('No database engine installed') }}</h3>
                    <p class="mx-auto mt-1 max-w-md text-sm text-brand-moss">
                        {{ __('This server has no running database engine yet. Install one (MySQL, MariaDB, PostgreSQL, …) on the server, then come back to create a database for this site.') }}
                    </p>
                    <x-primary-button size="sm" href="{{ route('servers.databases', $server) }}" wire:navigate class="mt-4">
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                        {{ __('Manage server databases') }}
                    </x-primary-button>
                </div>
            @else
                @if ($dbTab === 'databases')
                {{-- Linked databases ------------------------------------------------ --}}
                <section class="{{ $card }}">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Databases') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Databases for this site') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Databases linked to :site. Manage users, rotate the password, back up, or drop each one.', ['site' => $site->name]) }}</p>
                        </div>
                    </div>

                    @if ($linked->isEmpty())
                        <div class="px-5 py-6 text-sm text-brand-moss">
                            {{ __('No databases are linked to this site yet. Create one below.') }}
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/5">
                            @foreach ($linked as $db)
                                @php
                                    $family = \App\Support\Servers\DatabaseWorkspaceEngines::family($db->engine);
                                    $supportsUsers = in_array($db->engine, ['mysql', 'mariadb', 'postgres'], true);
                                @endphp
                                <li class="px-5 py-4" wire:key="linked-{{ $db->id }}">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</span>
                                                <span class="rounded-md bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                    {{ \App\Support\Servers\DatabaseWorkspaceEngines::label($db->engine) }}
                                                </span>
                                            </div>
                                            <p class="mt-1 truncate font-mono text-xs text-brand-moss">
                                                @if ($family === 'sqlite')
                                                    {{ $db->host }}
                                                @else
                                                    {{ $db->username }}@<span>{{ $db->host ?: '127.0.0.1' }}:{{ $db->defaultPort() }}</span>
                                                @endif
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($supportsUsers)
                                                <x-secondary-button size="xs" type="button" wire:click="openAddUserModal('{{ $db->id }}')">
                                                    <x-heroicon-o-user-plus class="h-4 w-4" />
                                                    {{ __('Add user') }}
                                                </x-secondary-button>
                                                <x-secondary-button
                                                    size="xs"
                                                    type="button"
                                                    wire:click="rotatePassword('{{ $db->id }}')"
                                                    wire:confirm="{{ __('Rotate the password for :user on :name? You’ll get a new one-time credential link; update the app’s .env afterwards.', ['user' => $db->username, 'name' => $db->name]) }}"
                                                >
                                                    <x-heroicon-o-key class="h-4 w-4" />
                                                    {{ __('Rotate password') }}
                                                </x-secondary-button>
                                            @endif
                                            <x-secondary-button size="xs" type="button" wire:click="backupDatabase('{{ $db->id }}')" wire:loading.attr="disabled" wire:target="backupDatabase('{{ $db->id }}')">
                                                <x-heroicon-o-archive-box-arrow-down class="h-4 w-4" />
                                                {{ __('Back up now') }}
                                            </x-secondary-button>
                                            <x-secondary-button
                                                size="xs"
                                                type="button"
                                                wire:click="unlinkDatabase('{{ $db->id }}')"
                                                wire:confirm="{{ __('Detach :name from this site? The database is NOT dropped on the server.', ['name' => $db->name]) }}"
                                            >
                                                <x-heroicon-o-link-slash class="h-4 w-4" />
                                                {{ __('Detach') }}
                                            </x-secondary-button>
                                            <button
                                                type="button"
                                                wire:click="dropDatabase('{{ $db->id }}')"
                                                wire:confirm="{{ __('Drop :name on the server? This permanently deletes the database and its data, and removes it from Dply. This cannot be undone.', ['name' => $db->name]) }}"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4" />
                                                {{ __('Drop') }}
                                            </button>
                                        </div>
                                    </div>

                                    @if ($supportsUsers && $db->extraUsers->isNotEmpty())
                                        <div class="mt-3 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Extra users') }}</p>
                                            <ul class="mt-1.5 divide-y divide-brand-ink/5">
                                                @foreach ($db->extraUsers as $user)
                                                    <li class="flex items-center justify-between gap-2 py-1.5" wire:key="extra-{{ $user->id }}">
                                                        <span class="truncate font-mono text-xs text-brand-ink">{{ $user->username }}@<span class="text-brand-moss">{{ $user->host }}</span></span>
                                                        <button
                                                            type="button"
                                                            wire:click="removeExtraUser('{{ $db->id }}', '{{ $user->id }}')"
                                                            wire:confirm="{{ __('Remove user :user from :name on the server?', ['user' => $user->username, 'name' => $db->name]) }}"
                                                            class="shrink-0 text-xs font-medium text-rose-700 hover:underline"
                                                        >{{ __('Remove') }}</button>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($db->backups->isNotEmpty())
                                        <div class="mt-3 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent backups') }}</p>
                                            <ul class="mt-1.5 divide-y divide-brand-ink/5">
                                                @foreach ($db->backups->take(5) as $backup)
                                                    <li class="flex items-center justify-between gap-2 py-1.5" wire:key="backup-{{ $backup->id }}">
                                                        <span class="flex items-center gap-2 text-xs text-brand-moss">
                                                            @if ($backup->status === \App\Models\ServerDatabaseBackup::STATUS_COMPLETED)
                                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                                            @elseif ($backup->status === \App\Models\ServerDatabaseBackup::STATUS_FAILED)
                                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                                            @else
                                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                                                            @endif
                                                            <span>{{ $backup->created_at?->diffForHumans() }}</span>
                                                            <span class="text-brand-mist">· {{ ucfirst($backup->status) }}</span>
                                                        </span>
                                                        <span class="flex shrink-0 items-center gap-2">
                                                            @if ($backup->isDownloadable())
                                                                <button type="button" wire:click="downloadDatabaseBackup('{{ $backup->id }}')" class="text-xs font-medium text-brand-forest hover:underline">{{ __('Download') }}</button>
                                                            @endif
                                                            <button
                                                                type="button"
                                                                wire:click="deleteDatabaseBackup('{{ $backup->id }}')"
                                                                wire:confirm="{{ __('Delete this backup?') }}"
                                                                class="text-xs font-medium text-rose-700 hover:underline"
                                                            >{{ __('Delete') }}</button>
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @endif

                @if ($dbTab === 'create')
                {{-- Create a database ---------------------------------------------- --}}
                <form wire:submit="createDatabase" class="{{ $card }}">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Provision') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create a database') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('A user and password are generated automatically unless you set them.') }}</p>
                        </div>
                    </div>

                    <div class="space-y-5 px-5 py-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="new_db_engine" class="{{ $labelCls }}">{{ __('Engine') }}</label>
                                <select id="new_db_engine" wire:model.live="new_db_engine" class="{{ $inputCls }}">
                                    @foreach ($installedEngines as $engine)
                                        <option value="{{ $engine }}">{{ \App\Support\Servers\DatabaseWorkspaceEngines::label($engine) }}</option>
                                    @endforeach
                                </select>
                                @error('new_db_engine') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="new_db_name" class="{{ $labelCls }}">{{ __('Database name') }}</label>
                                <input id="new_db_name" type="text" wire:model.blur="new_db_name" class="{{ $inputCls }} font-mono" placeholder="my_app" />
                                @error('new_db_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @unless ($isSqlite)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="new_db_username" class="{{ $labelCls }}">{{ __('Username') }} <span class="font-normal normal-case text-brand-mist">{{ __('(optional)') }}</span></label>
                                    <input id="new_db_username" type="text" wire:model="new_db_username" class="{{ $inputCls }} font-mono" placeholder="{{ __('auto-generated') }}" />
                                    @error('new_db_username') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="new_db_password" class="{{ $labelCls }}">{{ __('Password') }} <span class="font-normal normal-case text-brand-mist">{{ __('(optional)') }}</span></label>
                                    <input id="new_db_password" type="text" wire:model="new_db_password" class="{{ $inputCls }} font-mono" placeholder="{{ __('auto-generated') }}" />
                                    @error('new_db_password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endunless

                        @if ($isMysqlFamily)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="new_mysql_charset" class="{{ $labelCls }}">{{ __('Charset') }} <span class="font-normal normal-case text-brand-mist">{{ __('(optional)') }}</span></label>
                                    <input id="new_mysql_charset" type="text" wire:model="new_mysql_charset" class="{{ $inputCls }} font-mono" placeholder="utf8mb4" />
                                    @error('new_mysql_charset') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="new_mysql_collation" class="{{ $labelCls }}">{{ __('Collation') }} <span class="font-normal normal-case text-brand-mist">{{ __('(optional)') }}</span></label>
                                    <input id="new_mysql_collation" type="text" wire:model="new_mysql_collation" class="{{ $inputCls }} font-mono" placeholder="utf8mb4_unicode_ci" />
                                    @error('new_mysql_collation') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif

                        <div>
                            <label for="new_db_description" class="{{ $labelCls }}">{{ __('Description') }} <span class="font-normal normal-case text-brand-mist">{{ __('(optional)') }}</span></label>
                            <input id="new_db_description" type="text" wire:model="new_db_description" class="{{ $inputCls }}" />
                            @error('new_db_description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-4">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model.live="write_env" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                <span class="text-sm">
                                    <span class="font-medium text-brand-ink">{{ __("Write credentials to this site's .env") }}</span>
                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Adds DB_CONNECTION, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST and DB_PORT to the environment cache. MongoDB and ClickHouse have no standard block and are skipped.') }}</span>
                                </span>
                            </label>

                            @if ($write_env)
                                <label class="mt-3 flex items-start gap-3 border-t border-brand-ink/10 pt-3">
                                    <input type="checkbox" wire:model="push_env" class="mt-0.5 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-forest" />
                                    <span class="text-sm">
                                        <span class="font-medium text-brand-ink">{{ __('Push the .env to the server now') }}</span>
                                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Writes the file live over SSH. The running app only picks up new values after a deploy or restart (config:cache / Octane / Horizon).') }}</span>
                                    </span>
                                </label>
                            @endif
                        </div>

                        <div class="flex justify-end">
                            <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="createDatabase">
                                <x-heroicon-o-plus class="h-4 w-4" />
                                {{ __('Create database') }}
                            </x-primary-button>
                        </div>
                    </div>
                </form>

                {{-- Link an existing database -------------------------------------- --}}
                @if ($linkable->isNotEmpty())
                    <form wire:submit="linkDatabase" class="{{ $card }}">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Attach') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Link an existing database') }}</h2>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Attach a database that already lives on this server but isn’t tied to a site yet.') }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-end gap-3 px-5 py-5">
                            <div class="min-w-0 flex-1">
                                <label for="link_database_id" class="{{ $labelCls }}">{{ __('Database') }}</label>
                                <select id="link_database_id" wire:model="link_database_id" class="{{ $inputCls }}">
                                    <option value="">{{ __('Choose a database…') }}</option>
                                    @foreach ($linkable as $db)
                                        <option value="{{ $db->id }}">{{ $db->name }} ({{ \App\Support\Servers\DatabaseWorkspaceEngines::label($db->engine) }})</option>
                                    @endforeach
                                </select>
                                @error('link_database_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <x-secondary-button size="xs" type="submit">
                                <x-heroicon-o-link class="h-4 w-4" />
                                {{ __('Link') }}
                            </x-secondary-button>
                        </div>
                    </form>
                @endif
                @endif
            @endif
            </div>

            <x-cli-snippet class="mt-6" :commands="[
                ['label' => __('List databases'), 'command' => 'dply sites:db:list '.$site->slug],
                ['label' => __('Create database'), 'command' => 'dply sites:db:create '.$site->slug.' <name>'],
            ]" />
        </main>
    </div>

    {{-- Credential share modal — surfaces a one-time link instead of the raw password. --}}
    <x-modal name="site-db-credentials-modal" max-width="lg" focusable>
        <div class="p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ $share_context === 'rotated' ? __('Password rotated') : __('Database created') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">
                        @if ($share_context === 'rotated')
                            {{ __('The new password is being applied on the server — the banner confirms when it’s done.', ['name' => $share_link_db_name]) }}
                        @else
                            {{ __(':name is being provisioned in the background — the banner confirms when it’s ready.', ['name' => $share_link_db_name]) }}
                        @endif
                    </p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'site-db-credentials-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            @if ($share_link_url)
                <div class="mt-4 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('One-time credential link') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Share this link to reveal the username and password. It expires and can only be opened a limited number of times.') }}</p>
                    <div class="mt-3 flex items-center gap-2" x-data="{ copied: false }">
                        <input type="text" readonly value="{{ $share_link_url }}" class="{{ $inputCls }} font-mono text-xs" />
                        <x-secondary-button
                            size="xs"
                            type="button"
                            class="shrink-0"
                            x-on:click="navigator.clipboard.writeText(@js($share_link_url)); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                        </x-secondary-button>
                    </div>
                </div>
            @endif

            @if ($share_context === 'rotated')
                <div class="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2.5 text-xs text-amber-900">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ __('The app’s .env still has the old password. Update DB_PASSWORD under Environment and redeploy so the site can connect.') }}</span>
                </div>
            @endif

            <div class="mt-5 flex justify-end">
                <x-primary-button size="sm" type="button" x-on:click="$dispatch('close-modal', 'site-db-credentials-modal')">
                    {{ __('Done') }}
                </x-primary-button>
            </div>
        </div>
    </x-modal>

    {{-- Add extra database user — provisioned over SSH via the queued admin job. --}}
    <x-modal name="site-db-add-user-modal" max-width="lg" focusable>
        <form wire:submit="addExtraUser" class="p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Add database user') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Grants a new user full privileges on this database. The user is created on the server in the background.') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'site-db-add-user-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="mt-4 space-y-3">
                <div>
                    <label class="{{ $labelCls }}" for="extra_username">{{ __('Username') }}</label>
                    <input id="extra_username" type="text" wire:model="extra_username" class="{{ $inputCls }} font-mono" placeholder="reporting_ro" />
                    <x-input-error :messages="$errors->get('extra_username')" class="mt-1" />
                </div>
                <div>
                    <label class="{{ $labelCls }}" for="extra_password">{{ __('Password') }}</label>
                    <input id="extra_password" type="text" wire:model="extra_password" class="{{ $inputCls }} font-mono" placeholder="{{ __('strong password') }}" />
                    <x-input-error :messages="$errors->get('extra_password')" class="mt-1" />
                </div>
                <div>
                    <label class="{{ $labelCls }}" for="extra_host">{{ __('Host (MySQL/MariaDB)') }}</label>
                    <input id="extra_host" type="text" wire:model="extra_host" class="{{ $inputCls }} font-mono" placeholder="localhost" />
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Use % to allow any host. Ignored for PostgreSQL (roles are global).') }}</p>
                    <x-input-error :messages="$errors->get('extra_host')" class="mt-1" />
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <x-secondary-button size="sm" type="button" x-on:click="$dispatch('close-modal', 'site-db-add-user-modal')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="addExtraUser">
                    <span wire:loading.remove wire:target="addExtraUser">{{ __('Add user') }}</span>
                    <span wire:loading wire:target="addExtraUser">{{ __('Queuing…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    @include('livewire.partials.create-notification-channel-modal')
</div>
