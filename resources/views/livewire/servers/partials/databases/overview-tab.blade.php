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
                                @if (! $anyEngine)
                                    {{ __('No database engine detected on this server. Install an engine on its tab, or open Advanced → Server sync and click Recheck engines.') }}
                                @else
                                    {{ __('Creates the database and a user on the server. Leave user and password empty to generate values automatically.') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                @if (! $anyEngine)
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
                @include('livewire.servers.partials.databases._create-database-form')
                @endif
                </div>
            </section>

