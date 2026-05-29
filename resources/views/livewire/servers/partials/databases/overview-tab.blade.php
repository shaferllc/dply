            @if ($generated_database_credentials)
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Just created') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('New database credentials') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Save these now. Dply generated credentials for :name and shows them here right after creation.', ['name' => $generated_database_credentials['name']]) }}
                            </p>
                        </div>
                        <button type="button" wire:click="dismissGeneratedDatabaseCredentials" class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Dismiss') }}
                        </button>
                    </div>
                    <dl class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7">
                        <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Database') }}</dt>
                            <dd class="mt-0.5 font-mono text-sm font-semibold text-brand-ink">{{ $generated_database_credentials['name'] }}</dd>
                        </div>
                        <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-brand-ink">{{ $engineLabels[$generated_database_credentials['engine']] ?? ucfirst((string) $generated_database_credentials['engine']) }}</dd>
                        </div>
                        @if (filled($generated_database_credentials['username']))
                            <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</dt>
                                <dd class="mt-0.5 font-mono text-sm font-semibold text-brand-ink">{{ $generated_database_credentials['username'] }}</dd>
                                @if ($generated_database_credentials['username_generated'])
                                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Generated for you.') }}</p>
                                @endif
                            </div>
                        @endif
                        @if (filled($generated_database_credentials['password']))
                            <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</dt>
                                <dd class="mt-0.5 break-all font-mono text-sm font-semibold text-brand-ink">{{ $generated_database_credentials['password'] }}</dd>
                                @if ($generated_database_credentials['password_generated'])
                                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Generated for you.') }}</p>
                                @endif
                            </div>
                        @endif
                        @if ($generated_database_credentials['engine'] === 'sqlite' && filled($generated_database_credentials['host'] ?? null))
                            <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm sm:col-span-2">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('File path') }}</dt>
                                <dd class="mt-0.5 break-all font-mono text-sm font-semibold text-brand-ink">{{ $generated_database_credentials['host'] }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif

            @php
                $anyEngine = ($capabilities['mysql'] ?? false) || ($capabilities['postgres'] ?? false) || ($capabilities['sqlite'] ?? false);
            @endphp
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Create') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('New database') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                @if (! $anyEngine)
                                    {{ __('No database engine detected on this server. Install MySQL, PostgreSQL, or SQLite, then use Recheck engines on the Advanced tab.') }}
                                @else
                                    {{ __('Creates the database and a user on the server. Leave user and password empty to generate values automatically.') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                @if (! $anyEngine)
                    {{-- empty state already in header strip --}}
                @else
                <x-explainer>
                    <p>{{ __('Picks an engine, runs CREATE DATABASE, then creates a per-database user (defaulting to the same name) and grants it full access on that database only. The credentials are stored encrypted in the dply database — reveal + copy them from the Credentials column on each row.') }}</p>
                    <p>{{ __('Auto-generation: an empty user defaults to the database name. An empty password generates a 32-character symbol-free string. Both are good defaults for app-only use.') }}</p>
                </x-explainer>
                <form wire:submit="createDatabase" class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
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
                    <div class="sm:col-span-2 flex justify-end border-t border-brand-ink/10 pt-4">
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
                @endif
                </div>
            </section>

