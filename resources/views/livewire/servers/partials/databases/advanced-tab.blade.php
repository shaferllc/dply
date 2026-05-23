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
