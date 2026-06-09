            @include('livewire.servers.partials.databases._generated-credentials-banner')
            @include('livewire.servers.partials.databases._cache-crosslink', ['server' => $server, 'opsReady' => $opsReady])

            @php
                $anyEngine = ($capabilities['mysql'] ?? false) || ($capabilities['mariadb'] ?? false) || ($capabilities['postgres'] ?? false) || ($capabilities['mongodb'] ?? false) || ($capabilities['clickhouse'] ?? false) || ($capabilities['sqlite'] ?? false);
            @endphp
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Create') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('New database') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                @if (! $capabilitiesLoaded)
                                    {{ __('Checking which database engines are installed on this server…') }}
                                @elseif (! $anyEngine)
                                    {{ __('No database engine detected on this server. Install an engine on its tab, or open Advanced → Server sync and click Recheck engines.') }}
                                @else
                                    {{ __('Creates the database and a user on the server. Leave user and password empty to generate values automatically.') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                @if (! $capabilitiesLoaded)
                    <div class="flex items-center gap-3 text-sm text-brand-moss">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Checking installed engines…') }}
                    </div>
                @elseif (! $anyEngine)
                    <x-empty-state
                        borderless
                        class="mt-2"
                        icon="heroicon-o-server-stack"
                        tone="amber"
                        :title="__('No database engine installed')"
                        :description="__('Install MySQL, MariaDB, PostgreSQL, or SQLite on an engine tab, or open Advanced → Server sync and click Recheck engines.')"
                    >
                        <x-slot:actions>
                            <button
                                type="button"
                                wire:click="setWorkspaceTab('advanced')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                {{ __('Open Advanced') }}
                            </button>
                        </x-slot:actions>
                    </x-empty-state>
                @else
                    <button
                        type="button"
                        wire:click="openDatabaseCreate"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                    >
                        <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                        {{ __('Create database') }}
                    </button>
                @endif
                </div>
            </section>

            @php
                $installedDatabases = $server->serverDatabases->sortBy('name');
            @endphp
            @if ($anyEngine)
                <section class="dply-card overflow-hidden">
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3 min-w-0">
                            <x-icon-badge>
                                <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Databases') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Installed databases') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Every database tracked on this server, across all engines. Open an engine tab for engine-specific tools.') }}
                                </p>
                            </div>
                        </div>
                        @if ($installedDatabases->isNotEmpty())
                            <span class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-full bg-brand-sand/60 px-3 py-1 text-xs font-medium text-brand-ink">
                                {{ trans_choice('{1} :count database|[2,*] :count databases', $installedDatabases->count(), ['count' => $installedDatabases->count()]) }}
                            </span>
                        @endif
                    </div>
                    <div class="p-6 sm:p-7">
                        @if ($installedDatabases->isEmpty())
                            <x-empty-state
                                borderless
                                icon="heroicon-o-circle-stack"
                                tone="sage"
                                :title="__('No databases yet')"
                                :description="__('Create your first database — Dply provisions the schema and credentials for you.')"
                            >
                                <x-slot:actions>
                                    <button
                                        type="button"
                                        wire:click="openDatabaseCreate"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                                    >
                                        <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                                        {{ __('Create database') }}
                                    </button>
                                </x-slot:actions>
                            </x-empty-state>
                        @else
                            @include('livewire.servers.partials.databases-list', [
                                'databases' => $installedDatabases,
                            ])
                        @endif
                    </div>
                </section>
            @endif

