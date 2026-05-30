@php
    $lockEngine = $lockEngine ?? null;
    $showExplainer = $showExplainer ?? true;
    $effectiveEngine = $lockEngine ?? $new_db_engine;
    $isMysqlFamily = \App\Support\Servers\DatabaseWorkspaceEngines::isMysqlFamily($effectiveEngine);
@endphp

@if ($showExplainer)
    <x-explainer>
        <p>{{ __('Picks an engine, runs CREATE DATABASE, then creates a per-database user (defaulting to the same name) and grants it full access on that database only. The credentials are stored encrypted in the dply database — reveal + copy them from the Credentials column on each row.') }}</p>
        <p>{{ __('Auto-generation: an empty user defaults to the database name. An empty password generates a 32-character symbol-free string. Both are good defaults for app-only use.') }}</p>
    </x-explainer>
@endif

<form wire:submit="createDatabase" @class(['grid grid-cols-1 gap-5 sm:grid-cols-2', 'mt-5' => $showExplainer])>
    <div class="sm:col-span-2">
        <x-input-label for="new_db_name" value="{{ __('Name') }}" />
        <x-text-input id="new_db_name" wire:model.live.debounce.250ms="new_db_name" class="mt-1 block w-full font-mono text-sm" wire:loading.attr="disabled" wire:target="createDatabase" required />
        <p class="mt-1 text-xs text-brand-moss">
            {{ __('Lowercase letters, digits, and underscores only. Spaces, dashes, and dots auto-convert to underscores; everything else is dropped. Max 64 characters.') }}
        </p>
        <x-input-error :messages="$errors->get('new_db_name')" class="mt-1" />
    </div>
    @if ($lockEngine)
        <div class="sm:col-span-2">
            <x-input-label value="{{ __('Engine') }}" />
            <p class="mt-1 inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-sm font-medium text-brand-ink">
                <x-heroicon-o-circle-stack class="h-4 w-4 text-brand-forest" aria-hidden="true" />
                {{ $engineLabels[$lockEngine] ?? ucfirst($lockEngine) }}
            </p>
        </div>
    @else
        <div>
            <x-input-label for="new_db_engine" value="{{ __('Engine') }}" />
            <select id="new_db_engine" wire:model.live="new_db_engine" wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:bg-brand-sand/40">
                @if ($capabilities['mysql'] ?? false)
                    <option value="mysql">{{ __('MySQL') }}</option>
                @endif
                @if ($capabilities['mariadb'] ?? false)
                    <option value="mariadb">{{ __('MariaDB') }}</option>
                @endif
                @if ($capabilities['mongodb'] ?? false)
                    <option value="mongodb">{{ __('MongoDB') }}</option>
                @endif
                @if ($capabilities['clickhouse'] ?? false)
                    <option value="clickhouse">{{ __('ClickHouse') }}</option>
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
    @endif
    @if ($effectiveEngine !== 'sqlite')
        @if (! $lockEngine)
            <div>
                <x-input-label for="new_db_user_mode" value="{{ __('Database user') }}" />
                <select id="new_db_user_mode" wire:model.live="new_db_user_mode" @disabled(! $isMysqlFamily) wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:cursor-not-allowed disabled:bg-brand-sand/40">
                    <option value="new">{{ __('Create a new user') }}</option>
                    <option value="existing">{{ __('Use an existing MySQL/MariaDB user') }}</option>
                </select>
                @if (! $isMysqlFamily)
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Existing-user reuse is available for MySQL and MariaDB only.') }}</p>
                @endif
                <x-input-error :messages="$errors->get('new_db_user_mode')" class="mt-1" />
            </div>
        @elseif ($isMysqlFamily)
            <div>
                <x-input-label for="new_db_user_mode" value="{{ __('Database user') }}" />
                <select id="new_db_user_mode" wire:model.live="new_db_user_mode" wire:loading.attr="disabled" wire:target="createDatabase" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                    <option value="new">{{ __('Create a new user') }}</option>
                    <option value="existing">{{ __('Use an existing MySQL/MariaDB user') }}</option>
                </select>
                <x-input-error :messages="$errors->get('new_db_user_mode')" class="mt-1" />
            </div>
        @endif
        @if (! ($isMysqlFamily && $new_db_user_mode === 'existing'))
            <div @class(['sm:col-span-2' => $lockEngine && ! $isMysqlFamily])>
                <x-input-label for="new_db_username" value="{{ __('User (optional)') }}" />
                <x-text-input id="new_db_username" wire:model="new_db_username" autocomplete="off" class="mt-1 block w-full font-mono text-sm" wire:loading.attr="disabled" wire:target="createDatabase" placeholder="{{ __('Auto-generated if empty') }}" />
                <x-input-error :messages="$errors->get('new_db_username')" class="mt-1" />
            </div>
        @endif
        @if ($isMysqlFamily && $new_db_user_mode === 'existing')
            <div class="sm:col-span-2">
                <x-input-label for="new_db_existing_user_reference" value="{{ __('Existing MySQL/MariaDB user') }}" />
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
        @if (! ($isMysqlFamily && $new_db_user_mode === 'existing'))
            <div @class(['sm:col-span-2' => $lockEngine && ! $isMysqlFamily])>
                <x-password-field
                    id="new_db_password"
                    :label="__('Password (optional)')"
                    wire:model="new_db_password"
                    wire:target="createDatabase,generateNewDbPassword"
                    placeholder="••••••••"
                >
                    <x-slot:actions>
                        <button type="button" wire:click="generateNewDbPassword" wire:loading.attr="disabled" wire:target="createDatabase,generateNewDbPassword" class="font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                    </x-slot:actions>
                </x-password-field>
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
    @if ($isMysqlFamily)
        <div class="sm:col-span-2">
            <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 open:shadow-sm">
                <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-brand-ink">{{ __('Advanced MySQL/MariaDB options') }}</summary>
                <div class="grid gap-5 border-t border-brand-ink/10 px-4 py-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="new_mysql_charset" value="{{ __('Charset (optional)') }}" />
                        <x-text-input id="new_mysql_charset" wire:model="new_mysql_charset" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4" wire:loading.attr="disabled" wire:target="createDatabase" />
                        <x-input-error :messages="$errors->get('new_mysql_charset')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="new_mysql_collation" value="{{ __('Collation (optional)') }}" />
                        <x-text-input id="new_mysql_collation" wire:model="new_mysql_collation" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4_unicode_ci" wire:loading.attr="disabled" wire:target="createDatabase" />
                        <x-input-error :messages="$errors->get('new_mysql_collation')" class="mt-1" />
                    </div>
                </div>
            </details>
        </div>
    @endif
    <div class="sm:col-span-2 flex flex-wrap items-center justify-end gap-3 border-t border-brand-ink/10 pt-4">
        @if ($lockEngine)
            <button
                type="button"
                wire:click="closeEngineDatabaseCreate"
                wire:loading.attr="disabled"
                wire:target="createDatabase"
                class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Cancel') }}
            </button>
        @endif
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="createDatabase"
            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
        >
            <span wire:loading.remove wire:target="createDatabase" class="inline-flex items-center gap-2">
                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Add database') }}
            </span>
            <span wire:loading wire:target="createDatabase" class="inline-flex items-center gap-2 whitespace-nowrap">
                <x-spinner variant="cream" size="sm" />
                {{ __('Adding database…') }}
            </span>
        </button>
    </div>
</form>
