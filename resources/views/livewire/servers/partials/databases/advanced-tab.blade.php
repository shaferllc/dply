            @include('livewire.servers.partials.databases._server-sync-card', ['card' => $card])

            @if (! empty($remote_mysql_databases) || ! empty($remote_postgres_databases) || ! empty($remote_mongodb_databases) || ! empty($remote_clickhouse_databases))
                <div class="{{ $card }} p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Discovered on server') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Names returned from the database engine. Import lets you attach credentials in Dply for databases that already exist on the host.') }}
                    </p>
                    @if (count($mysqlOnlyOnServer) > 0)
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('MySQL') }}</p>
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
                    @if (count($mariadbOnlyOnServer ?? []) > 0)
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('MariaDB') }}</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($mariadbOnlyOnServer as $n)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-sm">
                                    <span class="font-mono text-brand-ink">{{ $n }}</span>
                                    <button
                                        type="button"
                                        wire:click="prefillDatabaseFromDiscovery(@js($n), 'mariadb')"
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
                    @if (count($mongoOnlyOnServer ?? []) > 0)
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('MongoDB') }}</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($mongoOnlyOnServer as $n)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-sm">
                                    <span class="font-mono text-brand-ink">{{ $n }}</span>
                                    <button type="button" wire:click="prefillDatabaseFromDiscovery(@js($n), 'mongodb')" class="text-xs font-medium text-brand-forest hover:underline">{{ __('Track in Dply') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (count($clickhouseOnlyOnServer ?? []) > 0)
                        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('ClickHouse') }}</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($clickhouseOnlyOnServer as $n)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-sm">
                                    <span class="font-mono text-brand-ink">{{ $n }}</span>
                                    <button type="button" wire:click="prefillDatabaseFromDiscovery(@js($n), 'clickhouse')" class="text-xs font-medium text-brand-forest hover:underline">{{ __('Track in Dply') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (count($mysqlOnlyOnServer) === 0 && count($mariadbOnlyOnServer ?? []) === 0 && count($pgOnlyOnServer) === 0 && count($mongoOnlyOnServer ?? []) === 0 && count($clickhouseOnlyOnServer ?? []) === 0)
                        <x-empty-state
                            class="mt-4"
                            compact
                            icon="heroicon-o-magnifying-glass-circle"
                            :title="__('All databases tracked')"
                            :description="__('No extra database names on the server beyond what Dply already tracks.')"
                        />
                    @endif
                </div>
            @endif

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
                        <li class="py-2">
                            <x-empty-state
                                borderless
                                compact
                                icon="heroicon-o-clipboard-document-list"
                                :title="__('No audit events yet')"
                                :description="__('Database workspace actions — installs, backups, credential changes — will appear here.')"
                            />
                        </li>
                    @endforelse
                </ul>
            </div>
