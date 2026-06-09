@if (! $showEngineWorkspace)
    <div class="{{ $card }} overflow-hidden px-6 py-6 sm:px-8">
        <x-empty-state
            borderless
            icon="heroicon-o-key"
            tone="sage"
            :title="__('Admin credentials unavailable')"
            :description="__('Install :engine on Overview first — then store optional admin credentials for SSH provisioning.', ['engine' => $dbEngineInfoForTab['label']])"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setEngineSubtab('overview')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    {{ __('Go to Overview') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    </div>
@else
    <div class="{{ $card }} overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Credentials') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine admin credentials', ['engine' => $engineLabels[$engine]]) }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Optional admin password used over SSH when passwordless sudo or socket access is not available. Stored encrypted.') }}
            </p>
        </div>
        <div class="px-6 py-6 sm:px-7">
            <form wire:submit="saveAdminCredentials('{{ $engine }}')" class="max-w-2xl space-y-6">
                @if (\App\Support\Servers\DatabaseWorkspaceEngines::isMysqlFamily($engine))
                    <div class="grid gap-6 sm:grid-cols-2 sm:items-end">
                        <div>
                            <x-input-label for="admin_mysql_root_username" value="{{ $engine === 'mariadb' ? __('MariaDB root username') : __('MySQL root username') }}" />
                            <x-text-input id="admin_mysql_root_username" wire:model="admin_mysql_root_username" class="mt-1 block w-full font-mono text-sm" />
                        </div>
                        <div>
                            <x-password-field
                                id="admin_mysql_root_password"
                                :label="$engine === 'mariadb' ? __('MariaDB root password (optional)') : __('MySQL root password (optional)')"
                                wire:model="admin_mysql_root_password"
                                wire:target="saveAdminCredentials,generateAdminMysqlRootPassword"
                                :placeholder="__('Leave blank to keep existing')"
                            >
                                <x-slot:actions>
                                    <button type="button" wire:click="generateAdminMysqlRootPassword" wire:loading.attr="disabled" wire:target="saveAdminCredentials,generateAdminMysqlRootPassword" class="font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                                </x-slot:actions>
                            </x-password-field>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <x-primary-button type="submit">{{ __('Save admin credentials') }}</x-primary-button>
                        <button type="button" wire:click="clearStoredMysqlRootPassword" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Clear MySQL password') }}</button>
                    </div>
                @elseif ($engine === 'postgres')
                    <div class="grid gap-6 sm:grid-cols-2 sm:items-end">
                        <div>
                            <x-input-label for="admin_postgres_superuser" value="{{ __('PostgreSQL superuser') }}" />
                            <x-text-input id="admin_postgres_superuser" wire:model="admin_postgres_superuser" class="mt-1 block w-full font-mono text-sm" />
                        </div>
                        <div>
                            <x-password-field
                                id="admin_postgres_password"
                                :label="__('PostgreSQL password (optional)')"
                                wire:model="admin_postgres_password"
                                wire:target="saveAdminCredentials,generateAdminPostgresPassword"
                                :placeholder="__('Leave blank to keep existing')"
                            >
                                <x-slot:actions>
                                    <button type="button" wire:click="generateAdminPostgresPassword" wire:loading.attr="disabled" wire:target="saveAdminCredentials,generateAdminPostgresPassword" class="font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                                </x-slot:actions>
                            </x-password-field>
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
                @elseif ($engine === 'mongodb')
                    <div class="grid gap-6 sm:grid-cols-2 sm:items-end">
                        <div>
                            <x-input-label for="admin_mongodb_username" value="{{ __('MongoDB admin username') }}" />
                            <x-text-input id="admin_mongodb_username" wire:model="admin_mongodb_username" class="mt-1 block w-full font-mono text-sm" placeholder="admin" />
                        </div>
                        <div>
                            <x-password-field
                                id="admin_mongodb_password"
                                :label="__('MongoDB admin password (optional)')"
                                wire:model="admin_mongodb_password"
                                wire:target="saveAdminCredentials,generateAdminMongodbPassword"
                                :placeholder="__('Leave blank to keep existing')"
                            >
                                <x-slot:actions>
                                    <button type="button" wire:click="generateAdminMongodbPassword" wire:loading.attr="disabled" wire:target="saveAdminCredentials,generateAdminMongodbPassword" class="font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                                </x-slot:actions>
                            </x-password-field>
                        </div>
                    </div>
                    <p class="text-xs text-brand-moss">{{ __('Used for mongosh over SSH when the server requires authentication (authSource: admin).') }}</p>
                    <div class="flex flex-wrap gap-3">
                        <x-primary-button type="submit">{{ __('Save admin credentials') }}</x-primary-button>
                        <button type="button" wire:click="clearStoredMongodbPassword" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Clear MongoDB password') }}</button>
                    </div>
                @elseif ($engine === 'clickhouse')
                    <div class="grid gap-6 sm:grid-cols-2 sm:items-end">
                        <div>
                            <x-input-label for="admin_clickhouse_username" value="{{ __('ClickHouse username') }}" />
                            <x-text-input id="admin_clickhouse_username" wire:model="admin_clickhouse_username" class="mt-1 block w-full font-mono text-sm" placeholder="default" />
                        </div>
                        <div>
                            <x-password-field
                                id="admin_clickhouse_password"
                                :label="__('ClickHouse password (optional)')"
                                wire:model="admin_clickhouse_password"
                                wire:target="saveAdminCredentials,generateAdminClickhousePassword"
                                :placeholder="__('Leave blank to keep existing')"
                            >
                                <x-slot:actions>
                                    <button type="button" wire:click="generateAdminClickhousePassword" wire:loading.attr="disabled" wire:target="saveAdminCredentials,generateAdminClickhousePassword" class="font-medium text-brand-forest hover:underline disabled:opacity-50">{{ __('Generate') }}</button>
                                </x-slot:actions>
                            </x-password-field>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <x-primary-button type="submit">{{ __('Save admin credentials') }}</x-primary-button>
                        <button type="button" wire:click="clearStoredClickhousePassword" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Clear ClickHouse password') }}</button>
                    </div>
                @endif
            </form>
        </div>
    </div>
@endif
