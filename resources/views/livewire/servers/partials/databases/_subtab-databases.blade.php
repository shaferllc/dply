@if (! $showEngineWorkspace)
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-circle-stack"
            :title="__(':engine databases', ['engine' => $engineLabels[$engine]])"
            :note="__('Create databases on this server and manage tracked credentials from each row.')"
            class="border-b border-brand-ink/10"
        />
        <div class="px-4 py-5 sm:px-5">
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-circle-stack"
            tone="sage"
            :title="__('Databases unavailable')"
            :description="__('Install :engine on Overview first — then create and manage databases here.', ['engine' => $dbEngineInfoForTab['label']])"
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
    </div>
@else
    @if ($generated_database_credentials && ($generated_database_credentials['engine'] ?? null) === $engine)
        @include('livewire.servers.partials.databases._generated-credentials-banner')
    @endif

    @php
        $engineCanCreate = (bool) ($capabilities[$engine] ?? false);
    @endphp
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-circle-stack"
            :title="__(':engine databases', ['engine' => $engineLabels[$engine]])"
            :count="$engineDatabases->isNotEmpty()
                ? trans_choice('{1} :count database|[2,*] :count databases', $engineDatabases->count(), ['count' => $engineDatabases->count()])
                : null"
            :note="__('Create databases on this server and manage tracked credentials from each row.')"
            class="border-b border-brand-ink/10"
        >
            @if ($engineCanCreate)
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="openEngineDatabaseCreate('{{ $engine }}')"
                        class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md bg-brand-ink px-2 text-[11px] font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Create database') }}
                    </button>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        @if ($engineDatabases->isEmpty())
            <div class="px-4 py-5 sm:px-5">
                <x-empty-state
                    borderless
                    compact
                    icon="heroicon-o-circle-stack"
                    tone="sage"
                    :title="__('No :engine databases yet', ['engine' => $engineLabels[$engine]])"
                    :description="__('Create a database on this server — Dply provisions the schema and credentials for you.')"
                >
                    <x-slot:actions>
                        @if ($engineCanCreate)
                            <button
                                type="button"
                                wire:click="openEngineDatabaseCreate('{{ $engine }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                            >
                                <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                                {{ __('Create database') }}
                            </button>
                        @endif
                    </x-slot:actions>
                </x-empty-state>
            </div>
        @else
            @include('livewire.servers.partials.databases-list', [
                'databases' => $engineDatabases,
            ])
        @endif
    </div>
@endif
