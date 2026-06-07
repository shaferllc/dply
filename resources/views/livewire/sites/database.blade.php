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
            <x-page-header
                :eyebrow="__('Database')"
                :title="__('Site databases')"
                :description="__('Create a database for this site and wire it straight into the .env, or attach one that already lives on this server. Backups, extra users, and dropping a database live on the server-level database manager.')"
                :show-documentation="false"
                flush
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

            @if (empty($installedEngines))
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
                    <a href="{{ route('servers.databases', $server) }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50 mt-4">
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                        {{ __('Manage server databases') }}
                    </a>
                </div>
            @else
                {{-- Linked databases ------------------------------------------------ --}}
                <div class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-5 py-4">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Databases for this site') }}</h3>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Databases linked to :site.', ['site' => $site->name]) }}</p>
                    </div>

                    @if ($linked->isEmpty())
                        <div class="px-5 py-6 text-sm text-brand-moss">
                            {{ __('No databases are linked to this site yet. Create one below.') }}
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/5">
                            @foreach ($linked as $db)
                                <li class="flex flex-wrap items-center gap-3 px-5 py-4" wire:key="linked-{{ $db->id }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-sm font-semibold text-brand-ink">{{ $db->name }}</span>
                                            <span class="rounded-md bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ \App\Support\Servers\DatabaseWorkspaceEngines::label($db->engine) }}
                                            </span>
                                        </div>
                                        <p class="mt-1 truncate font-mono text-xs text-brand-moss">
                                            @if (\App\Support\Servers\DatabaseWorkspaceEngines::family($db->engine) === 'sqlite')
                                                {{ $db->host }}
                                            @else
                                                {{ $db->username }}@<span>{{ $db->host ?: '127.0.0.1' }}:{{ $db->defaultPort() }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <x-secondary-button
                                        size="xs"
                                        type="button"
                                        wire:click="unlinkDatabase('{{ $db->id }}')"
                                        wire:confirm="{{ __('Detach :name from this site? The database is NOT dropped on the server.', ['name' => $db->name]) }}"
                                    >
                                        <x-heroicon-o-link-slash class="h-4 w-4" />
                                        {{ __('Detach') }}
                                    </x-secondary-button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Create a database ---------------------------------------------- --}}
                <form wire:submit="createDatabase" class="{{ $card }}">
                    <div class="border-b border-brand-ink/10 px-5 py-4">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Create a database') }}</h3>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('A user and password are generated automatically unless you set them.') }}</p>
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
                        <div class="border-b border-brand-ink/10 px-5 py-4">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Link an existing database') }}</h3>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Attach a database that already lives on this server but isn’t tied to a site yet.') }}</p>
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
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Database created') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __(':name is being provisioned in the background — the banner confirms when it’s ready.', ['name' => $share_link_db_name]) }}
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

            <div class="mt-5 flex justify-end">
                <x-primary-button size="sm" type="button" x-on:click="$dispatch('close-modal', 'site-db-credentials-modal')">
                    {{ __('Done') }}
                </x-primary-button>
            </div>
        </div>
    </x-modal>
</div>
