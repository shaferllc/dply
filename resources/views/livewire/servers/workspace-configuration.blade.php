<x-server-workspace-layout
    :server="$server"
    active="configuration"
    :title="__('Configuration')"
    :description="__('Edit allowlisted server config files — webserver, PHP, Redis, databases, system, and supervisor.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($configConsoleRun)
        @include('livewire.partials.console-action-banner-static', [
            'run' => $configConsoleRun,
            'kindLabels' => [
                'manage_action' => [
                    'running' => __('Working on server config…'),
                    'completed' => __('Server config operation finished.'),
                    'failed' => __('Server config operation failed.'),
                    'stale' => __('Server config operation did not finish.'),
                ],
            ],
        ])
    @endif

    <x-explainer>
        <p>{{ __('Pick a file, edit in the editor, validate the buffer, review the diff, then save. Paths are restricted to the server allowlist. Deployers can browse and view files read-only.') }}</p>
    </x-explainer>

    @if (! $opsReady)
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @else
        @if ($opsReady && ! $configCatalogLoaded && ! $configCatalogLoading)
            <div wire:init="loadConfigCatalog" class="hidden" aria-hidden="true"></div>
        @endif

        @if ($pending_load_console_id !== null)
            <div wire:poll.2s class="hidden" aria-hidden="true"></div>
        @endif

        <div class="{{ $card ?? 'rounded-2xl border border-brand-ink/10 bg-brand-cream shadow-sm' }} overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-cog-6-tooth class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Editor') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Configuration editor') }}</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ __('Load → edit → validate → review diff → save. Saves snapshot the live file, atomically install, re-validate, and auto-restore when validation rejects the new file.') }}</p>
                </div>
                @if ($config_scope !== '')
                    <button type="button" wire:click="clearConfigScope" class="ml-auto inline-flex shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">
                        <x-heroicon-o-x-mark class="h-3 w-3" />
                        {{ __('Clear scope filter') }}
                    </button>
                @endif
            </div>

            <div class="px-6 py-6 sm:px-7">
            <div class="mt-4">
                <label for="config-search" class="sr-only">{{ __('Search files') }}</label>
                <input
                    id="config-search"
                    type="search"
                    wire:model.live.debounce.300ms="config_search"
                    placeholder="{{ __('Search paths…') }}"
                    class="block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-forest focus:ring-brand-sage/30"
                />
            </div>

            <div class="mt-5 grid gap-5 md:grid-cols-[280px_minmax(0,1fr)]">
                @include('livewire.servers.partials.configuration.file-picker')

                @include('livewire.servers.partials.configuration.editor-panel', [
                    'configAutocomplete' => $configAutocomplete,
                    'configFileType' => $configFileType,
                ])
            </div>
            </div>
        </div>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.configuration.save-diff-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
