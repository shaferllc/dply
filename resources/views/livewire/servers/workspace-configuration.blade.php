<x-server-workspace-layout
    :server="$server"
    active="configuration"
    :title="__('Configuration')"
    :description="__('Edit allowlisted server config files — webserver, PHP, Redis, databases, system, and supervisor.')"
>
    {{-- Register the lazy CodeMirror loader on initial page render. The editor
         partial is only included once a file is selected; if that happens via a
         Livewire update, the @vite module script inside it is injected by morph
         and never executes — leaving window.dplyEnsureFileBrowserEditor undefined
         and the editor blank. Loading it here guarantees it's available. --}}
    @vite(['resources/js/file-browser-editor-lazy.js'])

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

    @if ($configReturnContext)
        <div class="mb-5 flex flex-col gap-4 rounded-2xl border border-brand-sage/25 bg-brand-sage/10 px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand-ink">{{ $configReturnContext['title'] }}</p>
                <p class="mt-1.5 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ $configReturnContext['description'] }}</p>
            </div>
            <a
                href="{{ $configReturnContext['back_url'] }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ $configReturnContext['back_label'] }}
            </a>
        </div>
    @endif

    @if (! $opsReady)
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @else
        @if ($opsReady && ! $configCatalogLoaded && ! $configCatalogLoading)
            <div wire:init="loadConfigCatalog" class="hidden" aria-hidden="true"></div>
        @endif

        @if ($pending_load_console_id !== null || $pending_validate_console_id !== null || $this->configFileContentLoading())
            <div wire:poll.2s class="hidden" aria-hidden="true"></div>
        @endif

        <div class="{{ $card ?? 'rounded-2xl border border-brand-ink/10 bg-brand-cream shadow-sm' }} overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-cog-6-tooth class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Editor') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Configuration editor') }}</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-relaxed text-brand-moss">{{ __('Load → edit → validate → review diff → save. Saves snapshot the live file, atomically install, re-validate, and auto-restore when validation rejects the new file.') }}</p>
                </div>
                @if ($config_scope !== '')
                    <div class="ml-auto flex shrink-0 flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2.5 py-1 text-[11px] font-semibold text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-funnel class="h-3 w-3" />
                            {{ __('Filtered: :scope', ['scope' => \Illuminate\Support\Str::headline($config_scope)]) }}
                        </span>
                        <button type="button" wire:click="clearConfigScope" class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">
                            <x-heroicon-o-x-mark class="h-3 w-3" />
                            {{ __('Show all files') }}
                        </button>
                    </div>
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

            <div class="mt-5 grid h-[60vh] gap-5 md:grid-cols-[280px_minmax(0,1fr)]">
                <div class="min-h-0">
                    @include('livewire.servers.partials.configuration.file-picker')
                </div>

                <div class="min-h-0">
                    @include('livewire.servers.partials.configuration.editor-panel', [
                        'configAutocomplete' => $configAutocomplete,
                        'configFileType' => $configFileType,
                    ])
                </div>
            </div>
            </div>
        </div>

        @if ($this->canCloneServer())
            <div class="{{ $card ?? 'rounded-2xl border border-brand-ink/10 bg-brand-cream shadow-sm' }} mt-6 p-6 sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Clone server') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Snapshot this DigitalOcean droplet and provision a new server from the snapshot. The clone lands in the same region and size, with a fresh SSH key. Snapshots typically take 3–8 minutes.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="openCloneServerModal"
                        @disabled($isDeployer)
                        class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <x-heroicon-o-document-duplicate class="h-4 w-4 opacity-80" aria-hidden="true" />
                        {{ __('Clone server') }}
                    </button>
                </div>
            </div>
        @endif
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

        {{-- Clone server modal. Triggered from the Clone server card above. --}}
        @if ($clone_open)
            <x-modal
                name="clone-server-modal"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit.prevent="confirmCloneServer" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <x-icon-badge>
                            <x-heroicon-o-document-duplicate class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Clone') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Clone :name?', ['name' => $server->name]) }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Region and size mirror the source. To change them, edit on the provider after the clone completes — or resize via the standard server settings.') }}</p>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        <div>
                            <label for="clone-name" class="block text-sm font-medium text-brand-ink">{{ __('New server name') }}</label>
                            <input
                                id="clone-name"
                                type="text"
                                wire:model="clone_name"
                                required
                                minlength="2"
                                maxlength="120"
                                class="mt-2 block w-full rounded-lg border border-brand-ink/15 px-3 py-2.5 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                            />
                            @error('clone_name')
                                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <dl class="grid grid-cols-2 gap-2">
                            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                                <dd class="mt-0.5 truncate font-mono text-sm font-semibold text-brand-ink">{{ $server->region ?: '—' }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                                <dd class="mt-0.5 truncate font-mono text-sm font-semibold text-brand-ink">{{ $server->size ?: '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="cancelCloneServer">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-document-duplicate class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Start clone') }}
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif
    </x-slot>
</x-server-workspace-layout>
