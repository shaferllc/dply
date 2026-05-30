@if (! $showEngineWorkspace)
    <div class="{{ $card }} overflow-hidden px-6 py-6 sm:px-8">
        <x-empty-state
            borderless
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
@else
    @if ($generated_database_credentials && ($generated_database_credentials['engine'] ?? null) === $engine)
        @include('livewire.servers.partials.databases._generated-credentials-banner')
    @endif

    @php
        $engineCanCreate = (bool) ($capabilities[$engine] ?? false);
        $showEngineCreateForm = $engine_create_form_open && $workspace_tab === $engine && $engineCanCreate;
    @endphp
    <div class="{{ $card }} overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Databases') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine databases', ['engine' => $engineLabels[$engine]]) }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Create databases on this server and manage tracked credentials from each row.') }}
                </p>
            </div>
            @if ($engineCanCreate && ! $showEngineCreateForm)
                <button
                    type="button"
                    wire:click="openEngineDatabaseCreate('{{ $engine }}')"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('Create database') }}
                </button>
            @endif
        </div>
        <div class="px-6 py-6 sm:px-7">
            @if ($showEngineCreateForm)
                @include('livewire.servers.partials.databases._create-database-form', [
                    'lockEngine' => $engine,
                    'showExplainer' => false,
                ])
            @elseif ($engineDatabases->isEmpty())
                <x-empty-state
                    borderless
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
            @else
                @include('livewire.servers.partials.databases-list', [
                    'databases' => $engineDatabases,
                ])
            @endif
        </div>
    </div>
@endif
