@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $capabilities = $capabilities ?? ['mysql' => false, 'postgres' => false, 'redis' => false];
    $anyDatabaseEngine = !empty($capabilities['mysql']) || !empty($capabilities['postgres']);
    $localMysql = $server->serverDatabases->where('engine', 'mysql')->pluck('name')->all();
    $localPg = $server->serverDatabases->where('engine', 'postgres')->pluck('name')->all();
    $mysqlOnlyOnServer = $capabilities['mysql'] ?? false ? array_values(array_diff($remote_mysql_databases, $localMysql)) : [];
    $pgOnlyOnServer = $capabilities['postgres'] ?? false ? array_values(array_diff($remote_postgres_databases, $localPg)) : [];
@endphp

<x-server-workspace-layout
    :server="$server"
    active="databases"
    :title="__('Databases')"
    :description="__('Create databases on this server, then reveal credentials and copy connection details for your apps.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        @if (! $anyDatabaseEngine)
            <div class="rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-4 text-sm text-amber-950">
                <p class="font-medium text-brand-ink">{{ __('No database engine detected') }}</p>
                <p class="mt-2 leading-relaxed text-amber-950/90">
                    {{ __('Dply could not run MySQL/MariaDB as root or PostgreSQL as the postgres user over SSH. Install mysql-server, mariadb-server, or postgresql on the host, or fix root/socket access, then recheck.') }}
                </p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        wire:click="refreshDatabaseCapabilities"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-lg border border-amber-400/80 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-amber-100/80 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="refreshDatabaseCapabilities">{{ __('Recheck installation') }}</span>
                        <span wire:loading wire:target="refreshDatabaseCapabilities" class="inline-flex items-center gap-2">
                            <x-spinner variant="forest" />
                            {{ __('Checking…') }}
                        </span>
                    </button>
                    <a
                        href="{{ route('servers.services', $server) }}"
                        wire:navigate
                        class="text-sm font-medium text-brand-forest underline decoration-brand-forest/30 hover:decoration-brand-forest"
                    >
                        {{ __('Open Services') }}
                    </a>
                </div>
            </div>
        @endif

        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Create and connect to databases') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('Keep the basics here: create a database, review what Dply tracks on this server, and copy the connection details your app needs.') }}
            </p>
            <p class="mt-2 leading-relaxed text-brand-moss">
                {{ __('Redis, queues, object storage, and other app resources now live in the site deployment contract. This workspace stays focused on server databases while those other attachments show up from each site workspace.') }}
            </p>
        </div>

        <x-resource-notification-summary
            :resource="$server"
            :heading="__('Database and server notifications')"
            :manage-url="route('servers.databases', $server)"
        />

        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <x-server-workspace-tablist :aria-label="__('Database workspace sections')" class="sm:min-w-0 sm:flex-1">
                <x-server-workspace-tab
                    id="db-tab-basics"
                    :active="$workspace_tab === 'databases'"
                    wire:click="setWorkspaceTab('databases')"
                >
                    {{ __('Basics') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="db-tab-advanced"
                    :active="$workspace_tab === 'advanced'"
                    wire:click="setWorkspaceTab('advanced')"
                >
                    {{ __('Advanced') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
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
                            <dd class="mt-1 text-sm text-brand-ink">{{ $generated_database_credentials['engine'] === 'postgres' ? __('PostgreSQL') : __('MySQL / MariaDB') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</dt>
                            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $generated_database_credentials['username'] }}</dd>
                            @if ($generated_database_credentials['username_generated'])
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Generated for you.') }}</p>
                            @endif
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</dt>
                            <dd class="mt-1 break-all font-mono text-sm text-brand-ink">{{ $generated_database_credentials['password'] }}</dd>
                            @if ($generated_database_credentials['password_generated'])
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Generated for you.') }}</p>
                            @endif
                        </div>
                    </dl>
                </div>
            @endif

            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('New database') }}</h2>
                @if ($anyDatabaseEngine)
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Creates the database and a user on the server. Leave user and password empty to generate values automatically.') }}</p>
                @else
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Install a database engine on the server and use Recheck installation before creating databases here.') }}</p>
                @endif
                <form wire:submit="createDatabase" class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="new_db_name" value="{{ __('Name') }}" />
                        <x-text-input id="new_db_name" wire:model="new_db_name" class="mt-1 block w-full font-mono text-sm" :disabled="! $anyDatabaseEngine" wire:loading.attr="disabled" wire:target="createDatabase" required />
                        <x-input-error :messages="$errors->get('new_db_name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="new_db_engine" value="{{ __('Engine') }}" />
                        <select id="new_db_engine" wire:model.live="new_db_engine" @disabled(! $anyDatabaseEngine) wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:bg-brand-sand/40">
                            @if ($capabilities['mysql'] ?? false)
                                <option value="mysql">{{ __('MySQL / MariaDB') }}</option>
                            @endif
                            @if ($capabilities['postgres'] ?? false)
                                <option value="postgres">{{ __('PostgreSQL') }}</option>
                            @endif
                        </select>
                        <x-input-error :messages="$errors->get('new_db_engine')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="new_db_user_mode" value="{{ __('Database user') }}" />
                        <select id="new_db_user_mode" wire:model.live="new_db_user_mode" @disabled(! $anyDatabaseEngine || $new_db_engine !== 'mysql')" wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:bg-brand-sand/40">
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
                            <x-text-input id="new_db_username" wire:model="new_db_username" autocomplete="off" class="mt-1 block w-full font-mono text-sm" :disabled="! $anyDatabaseEngine" wire:loading.attr="disabled" wire:target="createDatabase" placeholder="{{ __('Auto-generated if empty') }}" />
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
                                <button type="button" wire:click="generateNewDbPassword" wire:loading.attr="disabled" wire:target="createDatabase,generateNewDbPassword" @disabled(! $anyDatabaseEngine) class="mb-1 text-xs font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                            </div>
                            <x-text-input id="new_db_password" type="password" wire:model="new_db_password" autocomplete="new-password" class="mt-1 block w-full text-sm" :disabled="! $anyDatabaseEngine" wire:loading.attr="disabled" wire:target="createDatabase" placeholder="••••••••" />
                            <x-input-error :messages="$errors->get('new_db_password')" class="mt-1" />
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 open:shadow-sm">
                            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-brand-ink">{{ __('Advanced options') }}</summary>
                            <div class="space-y-4 border-t border-brand-ink/10 px-4 py-4">
                                <div>
                                    <x-input-label for="new_db_description" value="{{ __('Description (optional)') }}" />
                                    <textarea
                                        id="new_db_description"
                                        wire:model="new_db_description"
                                        rows="3"
                                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:bg-brand-sand/40"
                                        @disabled(! $anyDatabaseEngine)
                                        wire:loading.attr="disabled"
                                        wire:target="createDatabase"
                                    ></textarea>
                                    <x-input-error :messages="$errors->get('new_db_description')" class="mt-1" />
                                </div>
                                <div class="grid gap-5 sm:grid-cols-2" wire:show="new_db_engine === 'mysql'">
                                    <div>
                                        <x-input-label for="new_mysql_charset" value="{{ __('MySQL charset (optional)') }}" />
                                        <x-text-input id="new_mysql_charset" wire:model="new_mysql_charset" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4" :disabled="! $anyDatabaseEngine" wire:loading.attr="disabled" wire:target="createDatabase" />
                                        <x-input-error :messages="$errors->get('new_mysql_charset')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="new_mysql_collation" value="{{ __('MySQL collation (optional)') }}" />
                                        <x-text-input id="new_mysql_collation" wire:model="new_mysql_collation" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4_unicode_ci" :disabled="! $anyDatabaseEngine" wire:loading.attr="disabled" wire:target="createDatabase" />
                                        <x-input-error :messages="$errors->get('new_mysql_collation')" class="mt-1" />
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                    <div class="sm:col-span-2 flex justify-end">
                        <x-primary-button type="submit" :disabled="! $anyDatabaseEngine" wire:loading.attr="disabled" wire:target="createDatabase">
                            <span wire:loading.remove wire:target="createDatabase">{{ __('Add database') }}</span>
                            <span wire:loading wire:target="createDatabase">{{ __('Adding database…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </div>

            @if ($server->serverDatabases->isNotEmpty())
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your databases') }}</h2>
                    <ul class="mt-4 space-y-3">
                        @foreach ($server->serverDatabases->sortBy('name') as $db)
                            <li class="flex gap-0 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                                <div class="w-1 shrink-0 bg-emerald-500" aria-hidden="true"></div>
                                <div class="min-w-0 flex-1 p-4 sm:p-5">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="font-mono text-base font-semibold text-brand-ink">{{ $db->name }}</p>
                                            <p class="mt-1 text-sm text-brand-moss">
                                                {{ __(':engine · 1 database user', ['engine' => $db->engine === 'postgres' ? 'PostgreSQL' : 'MySQL']) }}
                                            </p>
                                            @if (filled($db->description))
                                                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $db->description }}</p>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button
                                                type="button"
                                                wire:click="openCredentialsModal(@js($db->id))"
                                                wire:loading.attr="disabled"
                                                wire:target="openCredentialsModal"
                                                class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/50"
                                            >
                                                {{ __('See credentials') }}
                                            </button>
                                            <button
                                                type="button"
                                                x-data="{ copied: false }"
                                                @click="navigator.clipboard.writeText(@js($db->connectionUrl())); copied = true; clearTimeout(window._dplyDbCopyT); window._dplyDbCopyT = setTimeout(() => copied = false, 2000)"
                                                class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                            >
                                                <span x-show="!copied" x-cloak>{{ __('Connection URL') }}</span>
                                                <span x-show="copied" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="db-panel-advanced"
            labelled-by="db-tab-advanced"
            :hidden="$workspace_tab !== 'advanced'"
            panel-class="space-y-8"
        >
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:pb-0.5">
                <button
                    type="button"
                    wire:click="refreshDatabaseCapabilities"
                    wire:loading.attr="disabled"
                    title="{{ __('Re-run detection (cached for a few minutes)') }}"
                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="refreshDatabaseCapabilities">{{ __('Recheck engines') }}</span>
                    <span wire:loading wire:target="refreshDatabaseCapabilities" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                    </span>
                </button>
                <button
                    type="button"
                    wire:click="synchronizeDatabases"
                    wire:loading.attr="disabled"
                    @disabled(! $anyDatabaseEngine)
                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="synchronizeDatabases">{{ __('Synchronize databases') }}</span>
                    <span wire:loading wire:target="synchronizeDatabases" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                        {{ __('Scanning…') }}
                    </span>
                </button>
            </div>

            @if ($anyDatabaseEngine && (! empty($remote_mysql_databases) || ! empty($remote_postgres_databases)))
                <div class="{{ $card }} p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Discovered on server') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Names returned from the database engine. Import lets you attach credentials in Dply for databases that already exist on the host.') }}
                    </p>
                    @if (($capabilities['mysql'] ?? false) && count($mysqlOnlyOnServer) > 0)
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
                    @if (($capabilities['postgres'] ?? false) && count($pgOnlyOnServer) > 0)
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('PostgreSQL') }}</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($pgOnlyOnServer as $n)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-sm">
                                    <span class="font-mono text-brand-ink">{{ $n }}</span>
                                    <button
                                        type="button"
                                        wire:click="prefillDatabaseFromDiscovery(@js($n), 'postgres')"
                                        @disabled(! $anyDatabaseEngine)
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
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Database users') }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Each tracked database has a primary user created alongside it. Use See credentials or Connection URL from Basics for login details.') }}</p>

                @if ($server->serverDatabases->isEmpty())
                    <p class="mt-6 text-sm text-brand-moss">{{ __('No database users yet. Create a database above to provision a user on the server.') }}</p>
                @else
                    <div class="mt-6 overflow-x-auto rounded-xl border border-brand-ink/10">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-4 py-3">{{ __('User') }}</th>
                                    <th class="px-4 py-3">{{ __('Database') }}</th>
                                    <th class="px-4 py-3">{{ __('Engine') }}</th>
                                    <th class="px-4 py-3">{{ __('Host') }}</th>
                                    <th class="px-4 py-3 text-end">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10 bg-white">
                                @foreach ($server->serverDatabases->sortBy('username') as $db)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $db->username }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-brand-ink">{{ $db->name }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $db->engine === 'postgres' ? __('PostgreSQL') : __('MySQL') }}</td>
                                        <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $db->host }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end">
                                            <button
                                                type="button"
                                                wire:click="openCredentialsModal(@js($db->id))"
                                                wire:loading.attr="disabled"
                                                wire:target="openCredentialsModal"
                                                class="text-xs font-medium text-brand-forest hover:underline"
                                            >
                                                {{ __('Credentials') }}
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($capabilities['mysql'] ?? false)
                    <div class="mt-10 border-t border-brand-ink/10 pt-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Extra MySQL users') }}</h3>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Creates an additional MySQL user and grants ALL on the chosen database (same host pattern as the primary user).') }}</p>
                        <form wire:submit="addExtraMysqlUser" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <x-input-label for="extra_db_id" value="{{ __('Database') }}" />
                                <select id="extra_db_id" wire:model="extra_db_id" wire:loading.attr="disabled" wire:target="addExtraMysqlUser" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                                    <option value="">{{ __('Select…') }}</option>
                                    @foreach ($server->serverDatabases->where('engine', 'mysql') as $edb)
                                        <option value="{{ $edb->id }}">{{ $edb->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('extra_db_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="extra_username" value="{{ __('Username') }}" />
                                <x-text-input id="extra_username" wire:model="extra_username" wire:loading.attr="disabled" wire:target="addExtraMysqlUser" class="mt-1 block w-full font-mono text-sm" />
                                <x-input-error :messages="$errors->get('extra_username')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="extra_password" value="{{ __('Password') }}" />
                                <x-text-input id="extra_password" type="password" wire:model="extra_password" wire:loading.attr="disabled" wire:target="addExtraMysqlUser" class="mt-1 block w-full text-sm" />
                                <x-input-error :messages="$errors->get('extra_password')" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="extra_host" value="{{ __('Host') }}" />
                                <x-text-input id="extra_host" wire:model="extra_host" wire:loading.attr="disabled" wire:target="addExtraMysqlUser" class="mt-1 block w-full font-mono text-sm" />
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addExtraMysqlUser">
                                    <span wire:loading.remove wire:target="addExtraMysqlUser">{{ __('Add MySQL user') }}</span>
                                    <span wire:loading wire:target="addExtraMysqlUser">{{ __('Adding user…') }}</span>
                                </x-primary-button>
                            </div>
                        </form>
                        @foreach ($server->serverDatabases as $edb)
                            @if ($edb->engine === 'mysql' && $edb->extraUsers->isNotEmpty())
                                <ul class="mt-4 space-y-2 text-sm">
                                    @foreach ($edb->extraUsers as $ex)
                                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-ink/10 px-3 py-2">
                                            <span class="font-mono">{{ $ex->username.'@'.$ex->host }} → {{ $edb->name }}</span>
                                            <button type="button" wire:click="openConfirmActionModal('removeExtraUser', ['{{ $ex->id }}'], @js(__('Remove extra MySQL user')), @js(__('Drop this MySQL user on the server and remove it from Dply?')), @js(__('Remove user')), true)" wire:loading.attr="disabled" wire:target="removeExtraUser" class="text-xs font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('SSH database admin credentials') }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Optional root or superuser passwords for database engines on this server when passwordless sudo or socket access is not available. Stored encrypted.') }}</p>
                <form wire:submit="saveAdminCredentials" class="mt-8 space-y-6">
                    <div class="grid gap-6 sm:grid-cols-2">
                        @if ($capabilities['mysql'] ?? false)
                            <div>
                                <x-input-label for="admin_mysql_root_username" value="{{ __('MySQL root username') }}" />
                                <x-text-input id="admin_mysql_root_username" wire:model="admin_mysql_root_username" class="mt-1 block w-full font-mono text-sm" />
                            </div>
                            <div>
                                <x-input-label for="admin_mysql_root_password" value="{{ __('MySQL root password (optional)') }}" />
                                <x-text-input id="admin_mysql_root_password" type="password" wire:model="admin_mysql_root_password" class="mt-1 block w-full text-sm" placeholder="{{ __('Leave blank to keep existing') }}" autocomplete="new-password" />
                            </div>
                        @endif
                        @if ($capabilities['postgres'] ?? false)
                            <div>
                                <x-input-label for="admin_postgres_superuser" value="{{ __('PostgreSQL superuser') }}" />
                                <x-text-input id="admin_postgres_superuser" wire:model="admin_postgres_superuser" class="mt-1 block w-full font-mono text-sm" />
                            </div>
                            <div>
                                <x-input-label for="admin_postgres_password" value="{{ __('PostgreSQL password (optional)') }}" />
                                <x-text-input id="admin_postgres_password" type="password" wire:model="admin_postgres_password" class="mt-1 block w-full text-sm" placeholder="{{ __('Leave blank to keep existing') }}" autocomplete="new-password" />
                            </div>
                        @endif
                    </div>
                    @if ($capabilities['postgres'] ?? false)
                        <label class="flex items-start gap-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="admin_postgres_use_sudo" class="mt-1 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                            <span>{{ __('Use sudo -u postgres for PostgreSQL (disable to use TCP auth with password above)') }}</span>
                        </label>
                    @endif
                    <div class="flex flex-wrap gap-3">
                        <x-primary-button type="submit">{{ __('Save admin credentials') }}</x-primary-button>
                        @if ($capabilities['mysql'] ?? false)
                            <button type="button" wire:click="clearStoredMysqlRootPassword" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Clear MySQL password') }}</button>
                        @endif
                        @if ($capabilities['postgres'] ?? false)
                            <button type="button" wire:click="clearStoredPostgresPassword" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Clear PostgreSQL password') }}</button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Dply vs server drift') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Compare databases tracked in Dply with names visible to the database engine over SSH.') }}</p>
                    </div>
                    <button type="button" wire:click="runDriftAnalysis" wire:loading.attr="disabled" wire:target="runDriftAnalysis" class="rounded-xl border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">
                        <span wire:loading.remove wire:target="runDriftAnalysis">{{ __('Refresh drift') }}</span>
                        <span wire:loading wire:target="runDriftAnalysis">{{ __('Refreshing…') }}</span>
                    </button>
                </div>
                @if ($drift_snapshot)
                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        @if ($capabilities['mysql'] ?? false)
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('MySQL / MariaDB') }}</p>
                                <p class="mt-2 text-sm text-brand-moss">{{ __('Only in Dply') }}</p>
                                <p class="mt-1 font-mono text-xs text-brand-ink">{{ implode(', ', $drift_snapshot['mysql']['only_in_dply'] ?? []) ?: '—' }}</p>
                                <p class="mt-3 text-sm text-brand-moss">{{ __('Only on server') }}</p>
                                <p class="mt-1 font-mono text-xs text-brand-ink">{{ implode(', ', $drift_snapshot['mysql']['only_on_server'] ?? []) ?: '—' }}</p>
                            </div>
                        @endif
                        @if ($capabilities['postgres'] ?? false)
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('PostgreSQL') }}</p>
                                <p class="mt-2 text-sm text-brand-moss">{{ __('Only in Dply') }}</p>
                                <p class="mt-1 font-mono text-xs text-brand-ink">{{ implode(', ', $drift_snapshot['postgres']['only_in_dply'] ?? []) ?: '—' }}</p>
                                <p class="mt-3 text-sm text-brand-moss">{{ __('Only on server') }}</p>
                                <p class="mt-1 font-mono text-xs text-brand-ink">{{ implode(', ', $drift_snapshot['postgres']['only_on_server'] ?? []) ?: '—' }}</p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Recent database workspace actions for this server.') }}</p>
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

            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Host tools') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Connection helpers, credential sharing, backups, and other database-only tools.') }}</p>

                <div class="mt-10 border-t border-brand-ink/10 pt-8">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Laravel .env snippet') }}</h3>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Copy into your app environment for a tracked database (localhost apps on the same server).') }}</p>
                    @if ($server->serverDatabases->isNotEmpty())
                        @foreach ($server->serverDatabases->take(1) as $sample)
                            <pre class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10 bg-zinc-50 p-4 font-mono text-xs text-brand-ink">DB_CONNECTION={{ $sample->engine === 'postgres' ? 'pgsql' : 'mysql' }}
DB_HOST={{ $sample->host ?: '127.0.0.1' }}
DB_PORT={{ $sample->defaultPort() }}
DB_DATABASE={{ $sample->name }}
DB_USERNAME={{ $sample->username }}
DB_PASSWORD=********</pre>
                        @endforeach
                    @endif
                </div>

                <div class="mt-10 border-t border-brand-ink/10 pt-8">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Share credentials (read-only link)') }}</h3>
                    @if ($orgAllowsCredentialShares ?? true)
                        <form wire:submit="createCredentialShare" class="mt-4 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <x-input-label for="share_target_db_id" value="{{ __('Database') }}" />
                                <select id="share_target_db_id" wire:model="share_target_db_id" wire:loading.attr="disabled" wire:target="createCredentialShare" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                                    <option value="">{{ __('Select…') }}</option>
                                    @foreach ($server->serverDatabases as $sdb)
                                        <option value="{{ $sdb->id }}">{{ $sdb->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('share_target_db_id')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="share_expires_hours" value="{{ __('Expires in (hours)') }}" />
                                <x-text-input id="share_expires_hours" type="number" wire:model="share_expires_hours" wire:loading.attr="disabled" wire:target="createCredentialShare" class="mt-1 block w-full text-sm" min="1" max="720" />
                            </div>
                            <div>
                                <x-input-label for="share_max_views" value="{{ __('Max views') }}" />
                                <x-text-input id="share_max_views" type="number" wire:model="share_max_views" wire:loading.attr="disabled" wire:target="createCredentialShare" class="mt-1 block w-full text-sm" min="1" max="50" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createCredentialShare">
                                    <span wire:loading.remove wire:target="createCredentialShare">{{ __('Create share link') }}</span>
                                    <span wire:loading wire:target="createCredentialShare">{{ __('Creating…') }}</span>
                                </x-primary-button>
                            </div>
                        </form>
                    @else
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Public credential share links are disabled for this organization.') }}</p>
                    @endif
                </div>

                <div class="mt-10 border-t border-brand-ink/10 pt-8">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Per-database advanced actions') }}</h3>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Use these only when you need to detach Dply from a database or permanently drop it from the server.') }}</p>
                    <ul class="mt-4 space-y-3">
                        @foreach ($server->serverDatabases->sortBy('name') as $db)
                            @php
                                $canDropRemote = ($db->engine === 'mysql' && ($capabilities['mysql'] ?? false))
                                    || ($db->engine === 'postgres' && ($capabilities['postgres'] ?? false));
                            @endphp
                            <li class="flex flex-col gap-3 rounded-xl border border-brand-ink/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</p>
                                    <p class="mt-1 text-xs text-brand-moss">{{ $db->engine === 'postgres' ? __('PostgreSQL') : __('MySQL / MariaDB') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('deleteDatabase', ['{{ $db->id }}'], @js(__('Remove database from Dply')), @js(__('Remove this entry from Dply only? The database will stay on the server.')), @js(__('Remove from Dply')), true)"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteDatabase"
                                        class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                    >
                                        {{ __('Remove from Dply') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('dropDatabaseOnServer', ['{{ $db->id }}'], @js(__('Drop database on server')), @js(__('Permanently drop this database and user on the server? This cannot be undone.')), @js(__('Drop database')), true)"
                                        wire:loading.attr="disabled"
                                        wire:target="dropDatabaseOnServer"
                                        @disabled(! $canDropRemote)
                                        class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ __('Drop on server') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="mt-10 border-t border-brand-ink/10 pt-8">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Database activity notifications') }}</h3>
                    <p class="mt-2 text-sm text-brand-moss">
                        {{ __('Send database create and remove events from this server to notification channels you already manage here, including email channels.') }}
                    </p>
                    @if ($databaseAlertChannelRows === [])
                        <p class="mt-3 text-xs text-brand-moss">{{ __('No notification channels are available to your account in this organization yet.') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($server->organization_id)
                                <a href="{{ route('organizations.notification-channels', $server->organization_id) }}" wire:navigate class="rounded-lg bg-brand-forest px-3 py-2 text-xs font-medium text-white hover:bg-brand-forest/90">
                                    {{ __('Add organization channels') }}
                                </a>
                            @endif
                            <a href="{{ route('profile.notification-channels') }}" wire:navigate class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                                {{ __('My notification channels') }}
                            </a>
                        </div>
                    @else
                        <div class="mt-4 overflow-x-auto rounded-xl border border-brand-ink/10">
                            <table class="min-w-full text-left text-xs">
                                <thead class="bg-brand-sand/40 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('Channel') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Created') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Removed') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10 bg-white">
                                    @foreach ($databaseAlertChannelRows as $chRow)
                                        <tr wire:key="db-alerts-{{ $chRow['id'] }}">
                                            <td class="px-3 py-2 font-medium text-brand-ink">{{ $chRow['label'] }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="checkbox" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" wire:model.live="databaseAlertMatrix.{{ $chRow['id'] }}.created" @disabled($isDeployer) />
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="checkbox" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" wire:model.live="databaseAlertMatrix.{{ $chRow['id'] }}.removed" @disabled($isDeployer) />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="button" wire:click="saveDatabaseAlertPreferences" wire:loading.attr="disabled" wire:target="saveDatabaseAlertPreferences" @disabled($isDeployer) class="rounded-lg bg-brand-forest px-3 py-2 text-xs font-medium text-white hover:bg-brand-forest/90 disabled:opacity-50">
                                <span wire:loading.remove wire:target="saveDatabaseAlertPreferences">{{ __('Save notification routing') }}</span>
                                <span wire:loading wire:target="saveDatabaseAlertPreferences">{{ __('Saving…') }}</span>
                            </button>
                        </div>
                    @endif
                </div>

            </div>
        </x-server-workspace-tab-panel>
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

        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
