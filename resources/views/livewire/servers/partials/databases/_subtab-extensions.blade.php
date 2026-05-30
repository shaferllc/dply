@if (! $showEngineWorkspace)
    <div class="{{ $card }} overflow-hidden px-6 py-6 sm:px-8">
        <x-empty-state
            borderless
            icon="heroicon-o-puzzle-piece"
            tone="sage"
            :title="__('Extensions unavailable')"
            :description="__('Install PostgreSQL on Overview first — then optional extensions can be installed here.')"
        >
            <x-slot:actions>
                <button type="button" wire:click="setEngineSubtab('overview')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90">
                    {{ __('Go to Overview') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    </div>
@else
    <div class="{{ $card }} overflow-hidden" wire:init="loadPostgresExtensions">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('PostgreSQL') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Optional extensions') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Install apt packages and run CREATE EXTENSION on this server. Use for geospatial (PostGIS), embeddings (pgvector), or time-series (TimescaleDB).') }}
            </p>
        </div>
        <ul class="divide-y divide-brand-ink/10">
            @foreach (\App\Support\Servers\PostgresExtensionCatalog::all() as $key => $meta)
                @php
                    $installed = in_array($meta['extension'], $postgres_installed_extensions ?? [], true);
                @endphp
                <li class="flex flex-wrap items-start justify-between gap-4 px-6 py-5 sm:px-7">
                    <div class="min-w-0 max-w-xl">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="text-sm font-semibold text-brand-ink">{{ $meta['label'] }}</h4>
                            @if ($installed)
                                <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('Installed') }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-brand-moss">{{ $meta['description'] }}</p>
                        <p class="mt-1 font-mono text-xs text-brand-mist">CREATE EXTENSION {{ $meta['extension'] }};</p>
                    </div>
                    <button
                        type="button"
                        wire:click="installPostgresExtension('{{ $key }}')"
                        wire:loading.attr="disabled"
                        wire:target="installPostgresExtension('{{ $key }}')"
                        @disabled($installed)
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="installPostgresExtension('{{ $key }}')">{{ $installed ? __('Installed') : __('Install') }}</span>
                        <span wire:loading wire:target="installPostgresExtension('{{ $key }}')" class="inline-flex items-center gap-2">
                            <x-spinner size="sm" />
                            {{ __('Installing…') }}
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
